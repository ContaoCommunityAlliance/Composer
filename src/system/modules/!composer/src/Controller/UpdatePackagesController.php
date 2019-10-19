<?php

/**
 * Composer integration for Contao.
 *
 * PHP version 5
 *
 * @copyright  ContaoCommunityAlliance 2013
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @package    Composer
 * @license    LGPLv3
 * @filesource
 */

namespace ContaoCommunityAlliance\Contao\Composer\Controller;

use Composer\Downloader\DownloadManager;
use Composer\Installer;
use Composer\Package\Package;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\ArrayRepository;
use ContaoCommunityAlliance\Composer\Plugin\ConfigUpdateException;
use ContaoCommunityAlliance\Composer\Plugin\DuplicateContaoException;
use ContaoCommunityAlliance\Contao\Composer\Util\FunctionAvailabilityCheck;
use ContaoCommunityAlliance\Contao\Composer\Util\Messages;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Class UpdatePackagesController
 */
class UpdatePackagesController extends AbstractController
{
    const OUTPUT_FILE_PATHNAME = 'composer/composer.out';

    /**
     * {@inheritdoc}
     */
    public function handle(\Input $input)
    {
        try {
            $packages       = $input->post('packages') ?: $input->get('packages');
            $packages       = explode(',', $packages);
            $packages       = array_filter($packages);
            $dryRun         = $input->get('dry-run') || $input->post('dry-run');
            $installOnly    = false;

            $mode = $this->determineRuntimeMode();

            if ($GLOBALS['TL_CONFIG']['composerUseCloudForUpdate']) {
                $runCloud = true;
                $cloudTmpFile = TL_ROOT . '/' . CloudUpdateController::TMP_FILE_PATHNAME;

                if (file_exists($cloudTmpFile)) {
                    $config = json_decode(file_get_contents($cloudTmpFile), true);
                    if (isset($config['finished']) && $config['finished'] < time()) {
                        $installOnly = true;
                        $runCloud = false;
                        unlink($cloudTmpFile);
                    }
                }

                if ($runCloud) {
                    $this->runCloudUpdate($packages, $dryRun);
                }
            }

            switch ($mode) {
                case 'inline':
                    $this->runInline($packages, $dryRun, $installOnly);
                    break;

                case 'process':
                    $this->runProcess($packages, $dryRun, $installOnly);
                    break;

                case 'detached':
                    $this->runDetached($packages, $dryRun, $installOnly);
                    break;
            }
        } catch (DuplicateContaoException $e) {
            if (isset($_SESSION['COMPOSER_DUPLICATE_CONTAO_EXCEPTION'])
                && $_SESSION['COMPOSER_DUPLICATE_CONTAO_EXCEPTION']
            ) {
                unset($_SESSION['COMPOSER_DUPLICATE_CONTAO_EXCEPTION']);
                do {
                    Messages::addError(str_replace(TL_ROOT, '', $e->getMessage()));
                    $e = $e->getPrevious();
                } while ($e);
                $this->redirect('contao/main.php?do=composer');
            } else {
                $_SESSION['COMPOSER_DUPLICATE_CONTAO_EXCEPTION'] = true;
                $this->reload();
            }
        } catch (ConfigUpdateException $e) {
            do {
                Messages::addConfirmation(str_replace(TL_ROOT, '', $e->getMessage()));
                $e = $e->getPrevious();
            } while ($e);
            $this->reload();
        } catch (\RuntimeException $e) {
            do {
                Messages::addError(str_replace(TL_ROOT, '', $e->getMessage()));
                $e = $e->getPrevious();
            } while ($e);
            $this->redirect('contao/main.php?do=composer');
        }
    }

    protected function runInline($packages, $dryRun, $installOnly)
    {
        // disable all hooks
        $GLOBALS['TL_HOOKS'] = array();

        $lockPathname = preg_replace('#\.json$#', '.lock', $this->configPathname);

        /** @var DownloadManager $downloadManager */
        $downloadManager = $this->composer->getDownloadManager();
        $downloadManager->setOutputProgress(false);

        $command = $installOnly ? 'install' : 'update';

        $argv = array(false, $command);
        if ($dryRun && !$installOnly) {
            $argv[] = '--dry-run';
        }
        if ($packages) {
            $argv = array_merge($argv, $packages);
        }

        $outputStream = fopen('php://memory', 'rw');
        $argvInput    = new ArgvInput($argv);
        $streamOutput = new StreamOutput($outputStream);

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, $command, $argvInput, $streamOutput);
        $this->composer
            ->getEventDispatcher()
            ->dispatch($commandEvent->getName(), $commandEvent);

        $installer = Installer::create($this->io, $this->composer);

        // Add contao/core as package
        if ($GLOBALS['TL_CONFIG']['composerUseCloudForUpdate']) {
            $additionalInstalledRepository = new ArrayRepository();
            $installed = CloudUpdateController::getInstalledPackages();
            foreach ($installed as $name => $version) {
                $additionalInstalledRepository->addPackage(
                    new Package($name, $version, $version)
                );
            }
            $installer->setAdditionalInstalledRepository($additionalInstalledRepository);
        }

        if (!$installOnly) {
            $installer->setDryRun($dryRun);
        }

        $installer->setUpdateWhitelist($packages);
        $installer->setWhitelistDependencies(true);

        switch ($this->composer->getConfig()
                               ->get('preferred-install')) {
            case 'source':
                $installer->setPreferSource(true);
                break;
            case 'dist':
                $installer->setPreferDist(true);
                break;
            case 'auto':
            default:
                // noop
                break;
        }

        if (file_exists(TL_ROOT . '/' . $lockPathname) && !$installOnly) {
            $installer->setUpdate(true);
        }

        try {
            $installer->run();
        } catch (\Exception $e) {
            $_SESSION['COMPOSER_OUTPUT'] .= $this->io->getOutput();
            throw $e;
        }

        $_SESSION['COMPOSER_OUTPUT'] .= $this->io->getOutput();
        file_put_contents(TL_ROOT . '/' . self::OUTPUT_FILE_PATHNAME, $_SESSION['COMPOSER_OUTPUT']);

        // redirect to database update
        $this->redirect('contao/main.php?do=composer&update=database');
    }

    private function buildCmd($packages, $dryRun, $installOnly)
    {
        $command = $installOnly ? 'install' : 'update';

        $cmd = sprintf(
            '%s composer.phar %s --no-ansi --no-interaction',
            $GLOBALS['TL_CONFIG']['composerPhpPath'],
            $command
        );

        if ($dryRun && !$installOnly) {
            $cmd .= ' --dry-run';
        }

        switch ($this->composer->getConfig()->get('preferred-install')) {
            case 'source':
                $cmd .= ' --prefer-source';
                break;
            case 'dist':
                $cmd .= ' --prefer-dist';
                break;
            case 'auto':
            default:
                // noop
                break;
        }

        if ($packages && !$installOnly) {
            $cmd .= ' --with-dependencies ' . implode(' ', array_map('escapeshellarg', $packages));
        }

        switch ($GLOBALS['TL_CONFIG']['composerVerbosity']) {
            case 'VERBOSITY_QUIET':
                $cmd .= ' --quiet';
                break;
            case 'VERBOSITY_VERBOSE':
                $cmd .= ' -v';
                break;
            case 'VERBOSITY_VERY_VERBOSE':
                $cmd .= ' -vv';
                break;
            case 'VERBOSITY_DEBUG':
                $cmd .= ' -vvv';
                break;
            default:
        }

        if ($GLOBALS['TL_CONFIG']['composerProfiling']) {
            $cmd .= ' --profile';
        }

        return $cmd;
    }

    protected function runProcess($packages, $dryRun, $installOnly)
    {
        // disable all hooks
        $GLOBALS['TL_HOOKS'] = array();

        $cmd          = $this->buildCmd($packages, $dryRun, $installOnly);
        $inputStream  = fopen('php://temp', 'r');
        $outputStream = fopen('php://temp', 'rw');
        $pipes        = array();

        $proc = proc_open(
            $cmd,
            array(
                $inputStream,
                $outputStream,
                $outputStream,
            ),
            $pipes,
            TL_ROOT . '/composer'
        );

        if ($proc === false) {
            throw new \RuntimeException('Could not execute ' . $cmd);
        }

        proc_close($proc);

        fseek($outputStream, 0);
        $_SESSION['COMPOSER_OUTPUT'] .= stream_get_contents($outputStream);
        file_put_contents(TL_ROOT . '/' . self::OUTPUT_FILE_PATHNAME, $_SESSION['COMPOSER_OUTPUT']);

        fclose($inputStream);
        fclose($outputStream);

        // redirect to database update
        $this->redirect('contao/main.php?do=composer&update=database');
    }

    protected function runDetached($packages, $dryRun, $installOnly)
    {
        $cmd = $this->buildCmd($packages, $dryRun, $installOnly);

        file_put_contents(TL_ROOT . '/' . DetachedController::OUT_FILE_PATHNAME, '$ ' . $cmd . PHP_EOL);

        $cmd .= sprintf(
            ' >> %s 2>&1 & echo $!',
            escapeshellarg(TL_ROOT . '/' . DetachedController::OUT_FILE_PATHNAME)
        );

        $processId = shell_exec($cmd);

        $pidFile = new \File(DetachedController::PID_FILE_PATHNAME);
        $pidFile->write(trim($processId));
        $pidFile->close();

        // redirect to database update
        $this->redirect('contao/main.php?do=composer');
    }

    protected function runCloudUpdate($packages, $dryRun)
    {
        $config = [
            'packages' => $packages,
            'dryRun'   => $dryRun,
            'created'  => time(),
        ];

        $tmpFile = new \File(CloudUpdateController::TMP_FILE_PATHNAME);
        $tmpFile->write(json_encode($config));
        $tmpFile->close();

        // redirect to database update
        $this->redirect('contao/main.php?do=composer');
    }

    /**
     * Determine the runtime mode to use depending on the availability of functions.
     *
     * @return mixed
     */
    private function determineRuntimeMode()
    {
        $mode = $GLOBALS['TL_CONFIG']['composerExecutionMode'];
        if ('inline' === $mode) {
            return $mode;
        }

        // Cloud update installation must use inline mode
        if ($GLOBALS['TL_CONFIG']['composerUseCloudForUpdate']) {
            return 'inline';
        }

        $functions = array();

        if ($mode === 'process') {
            $functions = array('proc_open', 'proc_close');
        }
        if ($mode === 'detached') {
            $functions = array('shell_exec');
            if (!defined('PHP_WINDOWS_VERSION_BUILD')) {
                $functions[] = 'posix_kill';
            }
        }

        foreach ($functions as $function) {
            if (!FunctionAvailabilityCheck::isFunctionEnabled($function)) {
                Messages::addWarning($function . ' is disabled, reverting from ' . $mode . ' to inline execution');
                return 'inline';
            }
        }

        return $mode;
    }
}
