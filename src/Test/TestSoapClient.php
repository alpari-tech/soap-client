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

namespace Alpari\Components\SoapClient\Test;

use Alpari\Components\SoapClient\Client\SoapClient;
use Symfony\Component\BrowserKit\Client;

/**
 * Test soap client that emulates soap call
 * Useful for web-services testing
 */
class TestSoapClient extends SoapClient
{
    /**
     * Framework test client
     *
     * @var Client
     */
    protected $client;

    /**
     * Constructs test soap client
     *
     * @param Client $client framework client
     * @param string $wsdl Wsdl path or string definition
     * @param array $options Additional options for client
     *
     * @return void
     */
    public function __construct(Client $client, $wsdl, array $options = [])
    {
        $this->client = $client;
        parent::__construct($wsdl, $options);
    }

    /**
     * Performs emulated soap request using test framework client
     *
     * {@inheritdoc}
     */
    public function __doRequest($request, $location, $action, $version, $oneWay = 0)
    {
        $this->client->request('POST', $location, [], [], [], $request);
        return $this->client->getResponse()->getContent();
    }

    /**
     * {@inheritdoc}
     */
    protected function performCurlRequest(
        string $serviceUrl,
        ?string $action,
        ?int $soapVersion,
        array $addCurlOpts = [],
        string $request = ''
    ): array {
        $this->client->request('POST', $serviceUrl, [], [], [], $request);

        return explode("\r\n\r\n", (string) $this->client->getResponse());
    }
}
