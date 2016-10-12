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

namespace ContaoCommunityAlliance\Contao\Composer\Exception;

class UnsuccessfulResponseException extends \RuntimeException
{
    /** @var \Request */
    private $response;

    /**
     * @return \Request
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param \Request $response
     */
    public function setResponse($response)
    {
        $this->response = $response;
    }

    /**
     * @param \Request $response
     *
     * @return static
     */
    public static function createWithResponse(\Request $response)
    {
        $e = new static();
        $e->setResponse($response);

        return $e;
    }

    public function __toString()
    {
    	if (null === $this->getResponse()) {
    		return parent::__toString();
	    }

		$data = [];
	    $data[] = 'Response: ' . $this->getResponse()->response;
	    $data[] = 'Response code: ' . $this->getResponse()->code;
	    $data[] = 'Response error: ' . $this->getResponse()->error;
	    $data[] = 'Request headers: ' . implode(', ', (array) $this->getResponse()->headers);
	    $data[] = 'Request: ' . $this->getResponse()->request;

        return implode('; ', $data);
    }
}
