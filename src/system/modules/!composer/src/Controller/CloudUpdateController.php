<?php

/**
 * Composer integration for Contao.
 *
 * PHP version 5
 *
 * @copyright  ContaoCommunityAlliance 2016
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @package    Composer
 * @license    LGPLv3
 */

namespace ContaoCommunityAlliance\Contao\Composer\Controller;

use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\RootPackage;
use Composer\Repository\PlatformRepository;
use ContaoCommunityAlliance\Contao\Composer\ConsoleColorConverter;
use ContaoCommunityAlliance\Contao\Composer\Exception\UnsuccessfulResponseException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Class CloudUpdateController
 */
class CloudUpdateController extends AbstractController
{
    const TMP_FILE_PATHNAME = 'system/tmp/composer-cloud-job';

    /** @var \File */
    private $jobFile;
    private $config = [];
    private $templateData = [];

    /**
     * {@inheritdoc}
     */
    public function handle(\Input $input)
    {
        $this->jobFile = new \File(self::TMP_FILE_PATHNAME);
        $this->config = json_decode($this->jobFile->getContent(), true);
        $jobId        = $this->config['jobId'] ?: null;

        if (\Input::post('terminate')) {
            try {
                $this->executeRequest('/jobs/' . $jobId, 'DELETE');
            } catch (\Exception $e) {
                // noop
            }

            $this->jobFile->delete();
            $this->reload();
        }

        if (\Input::post('cloud_confirm')) {
            $this->config['confirmed'] = true;
            $this->updateJobFile();
            $this->reload();
        }

        if (!$this->config['confirmed']) {

            return $this->handleOutput();
        }

        if (null === $jobId) {
            try {
                $jobId = $this->createJob();
                $this->config['jobId'] = $jobId;

            } catch (\Exception $e) {
                $this->setGeneralErrors($e);

                return $this->handleOutput();
            }
        }

        try {
            $jobDetails = $this->getJobDetails($jobId);
        } catch (\Exception $e) {
            $this->setGeneralErrors($e);

            return $this->handleOutput();
        }

        $jobStatus = $jobDetails['job']['status'];

        $this->templateData['jobId']     = $jobId;
        $this->templateData['jobStatus'] = $jobStatus;
        $this->templateData['isRunning'] = $this->isRunning($jobStatus);
        $this->templateData['output']    = $jobDetails['composerOutput'];


        // Handle install
        if (\Input::post('install') && 'finished' === $jobStatus) {
            $this->writeComposerLock($jobDetails['composerLock']);
            $this->config['writtenLock'] = true;
            $this->updateJobFile();

            $this->redirect('contao/main.php?do=composer&update=packages');
        }

        return $this->handleOutput();
    }

    /**
     * @param string $status
     *
     * @return bool
     */
    private function isRunning($status)
    {
        if (!in_array($status, ['finished', 'finished_with_errors'])) {

            return true;
        }

        return false;
    }

    /**
     * @param $jobId
     *
     * @return array
     *
     * @throws UnsuccessfulResponseException
     */
    private function getJobDetails($jobId)
    {
        $data = [];

        // Do not catch exception here so it bubbles up
        try {
            $response = $this->fetchData('/jobs/' . $jobId);
        } catch(UnsuccessfulResponseException $e) {

            // Only handle the case where a job is not available anymore
            // Otherwise bubble up the exception
            if (404 == $e->getResponse()->code) {
                $this->jobFile->delete();
                $this->reload();
            }

            throw $e;
        }

        // Must be valid content (200 status code) here
        $contents = json_decode($response->response, true);

        $data['job'] = $contents;
        $jobStatus   = $data['job']['status'];

        if (!$this->isRunning($jobStatus) && isset($contents['links']['composerLock'])) {
            try {
                $response = $this->fetchData($contents['links']['composerLock']);
                $data['composerLock'] = $response->response;
            } catch (UnsuccessfulResponseException $e) {
                // noop
            }
        }
        if (isset($contents['links']['composerOutput'])) {
            try {
                $response = $this->fetchData($contents['links']['composerOutput']);
                $data['composerOutput'] = $response->response;
            } catch (UnsuccessfulResponseException $e) {
                // noop
            }
        }

        return $data;
    }

    /**
     * @param $url
     *
     * @return \Request
     *
     * @throws UnsuccessfulResponseException
     */
    private function fetchData($url)
    {
        $response = $this->executeRequest($url);

        if ($response->code >= 200
            && $response->code < 300
        ) {

            return $response;
        }

        throw UnsuccessfulResponseException::createWithResponse($response);
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws UnsuccessfulResponseException
     */
    private function createJob()
    {
        $composerJson = $this->buildComposerJson();

        $response = $this->executeRequest(
            '/jobs',
            'POST',
            JsonFile::encode($composerJson),
            ['Composer-Resolver-Command' => $this->buildResolverOptionsHeader($composerJson)]
        );

        if (201 !== $response->code) {
            throw UnsuccessfulResponseException::createWithResponse($response);
        }

        $content = json_decode($response->response, true);

        if (!is_array($content)) {
            throw UnsuccessfulResponseException::createWithResponse($response);
        }

        if (isset($content['jobId'])) {

            return $content['jobId'];
        }

        throw UnsuccessfulResponseException::createWithResponse($response);
    }

    /**
     * @return array
     */
    private function buildComposerJson()
    {
        $jsonFile = new JsonFile(COMPOSER_DIR_ABSOULTE . '/composer.json');
        $composerJson = $jsonFile->read();

        // Add platform info
        $repository = new PlatformRepository();

        foreach ($repository->getPackages() as $package) {
            $composerJson['config']['platform'][$package->getName()] = $package->getVersion();
        }

        // Add installed-repository so the 3.5 dependencies won't make it into
        // the composer.lock
        $composerJson['extra']['composer-resolver']['installed-repository'] = static::getInstalledPackages();

        return $composerJson;
    }

    /**
     * @param \Exception $e
     */
    private function setGeneralErrors(\Exception $e)
    {
        log_message((string) $e, 'composer_cloud_update_error.log');

        $message = $e->getMessage();

        if ($e instanceof UnsuccessfulResponseException) {
            $message = $e->getResponse()->error;
        }

        $errors = $GLOBALS['TL_LANG']['composer_client']['cloud_update_exception'];
        $errorKey = null;

        // Wrong URL in the settings
        if (strpos($message, 'Invalid schema') !== false) {
            $errorKey = 'url_malformed';
        }

        // Could not resolve host
        if (strpos($message, 'getaddrinfo') !== false) {
            $errorKey = 'could_not_resolve_host';
        }

        // Could not connect to host
        if (strpos($message, 'Connection refused') !== false
            || strpos($message, 'couldn\'t connect to host') !== false
        ) {
            $errorKey = 'connection_refused';
        }

        // Timeout
        if (strpos($message, 'Operation timed out') !== false) {
            $errorKey = 'operation_timed_out';
        }

        if (null !== $errorKey) {
            $errors = $GLOBALS['TL_LANG']['composer_client']['cloud_update_errors'][$errorKey];
        }

        $this->templateData['errors'] = $errors;
    }

    /**
     * Execute a request.
     *
     * @param string     $url
     * @param string     $method
     * @param null $payload
     * @param array      $headers
     *
     * @return \Request
     * @throws \Exception
     */
    private function executeRequest($url, $method = 'GET', $payload = null, $headers = [])
    {
        $request = new \Request();
        $request->redirect = true;
        $request->setHeader('Content-Type', 'application/json');

        foreach ($headers as $header => $v) {
            $request->setHeader($header, $v);
        }

        // Add auth header
        if ($GLOBALS['TL_CONFIG']['composerCloudAuthKey']) {
            $authKey = base64_encode('cloud:' . $GLOBALS['TL_CONFIG']['composerCloudAuthKey']);
            $request->setHeader('Authorization', 'Basic ' . $authKey);
        }

        $url = rtrim($GLOBALS['TL_CONFIG']['composerCloudEndpoint'], '/') . $url;
        $request->send($url, $payload, $method);

        return $request;
    }

    /**
     * @return string
     */
    private function handleOutput()
    {
        $converter = new ConsoleColorConverter();
        $output    = $converter->parse($this->templateData['output']);

        // Update finished timestamp in file
        if (!$this->isRunning($this->templateData['jobStatus'])) {
            $this->config['finished'] = time();
        }

        // Auto disable running if there are errors and kill the job file
        if ($this->templateData['errors']) {
            $this->templateData['isRunning'] = false;
        }

        $this->updateJobFile();

        $startTime = new \DateTime();
        $startTime->setTimestamp($this->config['created']);

        $endTime = new \DateTime();
        $finished = $this->config['finished'] ?: time();
        $endTime->setTimestamp($this->templateData['isRunning'] ? time() : $finished);

        $uptime = $endTime->diff($startTime);
        $this->templateData['uptime'] = $uptime->format('%h h %I m %S s');

        if (\Environment::get('isAjaxRequest')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                array(
                    'jobId'     => $this->templateData['jobId'],
                    'jobStatus' => $this->templateData['jobStatus'],
                    'errors'    => $this->templateData['errors'],
                    'output'    => $output,
                    'isRunning' => $this->templateData['isRunning'],
                    'uptime'    => $this->templateData['uptime'],
                )
            );
            exit;
        } else {

            $cloudInfo = [];
            try {
                $response = $this->fetchData('/');
                $cloudInfo = json_decode($response->response, true);
            } catch (\Exception $e) {
                $this->setGeneralErrors($e);
            }

            $template = new \BackendTemplate('be_composer_client_cloud_update');
            $template->setData($this->templateData);
            $template->output     = $output;
            $template->confirmed  = $this->config['confirmed'];
            $template->cloudInfo  = $cloudInfo;
            $template->sponsors   = [
                [
                    'name' => 'terminal42 gmbh',
                    'href' => 'https://www.terminal42.ch',
                    'logo' => 't42.svg',
                ]
            ];

            return $template->parse();
        }
    }

    /**
     * Updates the job file
     */
    private function updateJobFile()
    {
        $this->jobFile->write(json_encode($this->config));
        $this->jobFile->close();
    }

    /**
     * Updates the composer.lock file
     *
     * @param string $newContent
     */
    private function writeComposerLock($newContent)
    {
        $composerLock = preg_replace('/json$/', 'lock', $this->configPathname);
        $writeBackup  = file_exists(TL_ROOT . '/' . $composerLock);

        $file = new \File($composerLock);

        if ($writeBackup) {
            $file->copyTo($composerLock . '.bup');
        }

        $file->write($newContent);
        $file->close();
    }

    /**
     * Builds the header for the job creation based on settings.
     *
     * @param array $composerJson
     *
     * @return string
     */
    private function buildResolverOptionsHeader(array $composerJson)
    {
        $header = '';

        if (isset($this->config['packages'])
            && is_array($this->config['packages'])
            && 0 !== count($this->config['packages'])
        ) {
            $header .= implode(' ', $this->config['packages']) . ' ';
        }

        // Always no-ansi
        $header .= '--no-ansi';

        $config = $this->getComposer()->getConfig();

        // Install preferences
        switch ($config->get('preferred-install')) {
            case 'source':
                $header .= ' --prefer-source';
                break;
            case 'dist':
                $header .= ' --prefer-dist';
                break;
            case 'auto':
            default:
                // noop
                break;
        }

        // Prefer stable flag
        if (isset($composerJson['prefer-stable']) && true === $composerJson['prefer-stable']) {
            $header .= ' --prefer-stable';
        }

        switch ($GLOBALS['TL_CONFIG']['composerVerbosity']) {
            case 'VERBOSITY_VERBOSE':
                $header .= ' -v';
                break;
            case 'VERBOSITY_VERY_VERBOSE':
                $header .= ' -vv';
                break;
            case 'VERBOSITY_DEBUG':
                $header .= ' -vvv';
                break;
        }

        if ($GLOBALS['TL_CONFIG']['composerProfiling']) {
            $header .= ' --profile';
        }

        return $header;
    }

    /**
     * Returns an array of all installed packages with the respective version number.
     *
     * @return array
     */
    public static function getInstalledPackages()
    {
        // Always add contao/core and contao/core-bundle
        $installedPackages = [
            'contao/core'        => VERSION . '.' . BUILD,
            'contao/core-bundle' => VERSION . '.' . BUILD,
        ];

        if (file_exists(TL_ROOT . '/vendor/composer/installed.json')) {

            $installed = file_get_contents(TL_ROOT . '/vendor/composer/installed.json');
            $installed = json_decode($installed, true);

            foreach ($installed as $package) {
                $installedPackages[$package['name']] = $package['version'];
            }
        }

        return $installedPackages;
    }
}
