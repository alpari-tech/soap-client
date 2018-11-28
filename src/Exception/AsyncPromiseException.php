<?php

/*
 * This file is part of the Soap client.
 *
 * (c) Alpari
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types=1);

namespace Alpari\SoapClient\Exception;

/**
 * Asynchronous query object exception
 */
class AsyncPromiseException extends \RuntimeException
{

    /**
     * Handler of prepared CURL request
     *
     * @var resource|null
     */
    protected $request;

    /**
     * Initialize delayed request exception
     *
     * @param resource $curlRequest Prepared curl query
     */
    public function __construct($curlRequest)
    {
        parent::__construct('Delayed response', 0);
        $this->request = $curlRequest;
    }

    /**
     * Returns delayed curl resource
     *
     * @return resource
     */
    public function getRequest()
    {
        return $this->request;
    }
}
