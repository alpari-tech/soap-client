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

use Alpari\Components\SoapClient\Server\SoapService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Client;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zend\Soap\AutoDiscover;
use Zend\Soap\AutoDiscover\DiscoveryStrategy\ReflectionDiscovery;
use Zend\Soap\Server;

/**
 * Class TestSoapClientTest
 */
class TestSoapClientTest extends TestCase
{
    private static $wsdlXml;

    /**
     * @var TestSoapClient
     */
    protected $client;

    /**
     * @var HttpKernelInterface|MockObject
     */
    protected $kernel;

    /**
     * @var Server
     */
    protected $server;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        $discover = new AutoDiscover();
        $wsdl = $discover
            ->setClass(SoapService::class)
            ->setServiceName('Test')
            ->setDiscoveryStrategy(new ReflectionDiscovery())
            ->setUri('http://localhost')
            ->generate();

        self::$wsdlXml = $wsdl->toXML();
    }

    public function testHandlingRequestInSameProcess(): void
    {
        $this->kernel->expects(self::exactly(2))
            ->method('handle')
            ->willReturnCallback(function (Request $request) {
                if ($request->query->has('wsdl')) {
                    return new Response(self::$wsdlXml);
                }

                return new Response($this->server->handle($request->getContent()));
            });

        $testString = 'TEST';
        $result     = $this->client->echoString($testString);
        self::assertSame($testString, $result);
    }

    /**
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();
        ini_set('soap.wsdl_cache_enabled', '0');

        $this->kernel = $this->getMockBuilder(HttpKernelInterface::class)
            ->setMethods(['handle'])
            ->getMockForAbstractClass();

        $this->client = new TestSoapClient(new Client($this->kernel), 'http://localhost?wsdl');

        $this->server = new Server('data://text/plain;base64,' . base64_encode(self::$wsdlXml));

        $this->server->setObject(new SoapService());
        $this->server->setClassmap([]);
        $this->server->setReturnResponse(true);
    }
}
