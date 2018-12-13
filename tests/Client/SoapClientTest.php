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

namespace Alpari\Components\SoapClient\Client;

use Alpari\Components\SoapClient\Server\SoapService;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use SoapFault;
use Symfony\Component\Process\Process;

/**
 * Class SoapClientTest
 */
class SoapClientTest extends TestCase
{
    /**
     * @var string
     */
    private static $server;

    /**
     * @var Process
     */
    private static $process;

    /**
     * @var SoapClient|MockObject
     */
    private $client;

    /**
     * @return void
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        self::$server = '127.0.0.1:' . random_int(40000, 65535);

        self::$process = new Process(['php', '-S', self::$server, 'index.php'], realpath(__DIR__ . '/../Server'));
        self::$process->start();

        do {
            if (!self::$process->isRunning()) {
                self::fail('Can not start SOAP server');
            }

            [$host, $port] = explode(':', self::$server);
            $f = @fsockopen($host, (int) $port, $errno);
            if ($f !== false) {
                fclose($f);
                break;
            }

            usleep(10000);
        } while (true);
    }

    /**
     * @return void
     */
    public static function tearDownAfterClass()
    {
        self::$process->stop();
        parent::tearDownAfterClass();
    }

    /**
     * @return void
     */
    public function testSimpleSoapRequest(): void
    {
        $request = 'whatever';
        $result  = $this->client->echoString($request);
        self::assertSame($request, $result);
    }

    public function testAsyncRequest(): void
    {
        $requests = [];
        for ($i = 0; $i < 20; $i++) {
            $requests[] = "Request $i";
        }

        $responses = $this->client->async(function (SoapClient $client) use ($requests) {
            foreach ($requests as $request) {
                $client->echoString($request);
            }
        });

        self::assertSame($requests, $responses);
    }

    /**
     * @expectedException SoapFault
     * @expectedExceptionMessage Nested asynchronous calls are not supported.
     */
    public function testCanNotDoNestedAsyncRequests(): void
    {
        $this->client->async(function (SoapClient $client) {
            $client->async(function (SoapClient $client) {
                self::fail('This should have beeen never called.');
            });
        });
    }

    /**
     * @return void
     * @expectedException \SoapFault
     * @expectedExceptionMessage Oops...
     */
    public function testExceptionInAsyncRequestsNotFailAllRequests(): void
    {
        $responses = $this->client->async(function (SoapClient $client) {
            $client->throwException('Oops...');
        });

        self::assertCount(1, $responses);
        throw $responses[0];
    }

    public function testPassingHeaders(): void
    {
        $object = new class {
            public function __toString()
            {
                return 'string-object';
            }
        };

        $this->client->setHeader('X-Custom-Header', 'a value');
        $this->client->setHeader('X-Object', $object);
        $this->client->__setCookie('X-String', 'string');
        $this->client->__setCookie('X-Object', $object);

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'X-Custom-Header: a value') !== false);
        self::assertTrue(strpos($headers, 'X-Object: string-object') !== false);
        self::assertTrue(strpos($headers, 'Cookie: X-String=string; X-Object=string-object') !== false);

        $lastRequestHeaders = $this->client->__getLastRequestHeaders();
        self::assertTrue(strpos($lastRequestHeaders, 'X-Custom-Header: a value') !== false);
        self::assertTrue(strpos($lastRequestHeaders, 'X-Object: string-object') !== false);
        self::assertTrue(strpos($lastRequestHeaders, 'Cookie: X-String=string; X-Object=string-object') !== false);
    }

    public function testPassingMultipleValuesForSingleHeader(): void
    {
        $this->client->setHeader('X-Header', '1');
        $this->client->setHeader('X-Header', '2');
        $this->client->echoString('Send it!');

        $headers = $this->client->__getLastRequestHeaders();
        self::assertTrue(strpos($headers, 'X-Header: 1') !== false);
        self::assertTrue(strpos($headers, 'X-Header: 2') !== false);
    }

    public function testRemovingCookie(): void
    {
        $this->client->__setCookie('X-String', 'string');

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'Cookie: X-String=string') !== false);

        $this->client->__setCookie('X-String', null);

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'Cookie: X-String=string') === false);
    }

    public function testRemovingHeader(): void
    {
        $this->client->setHeader('X-String', 'string');

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'X-String: string') !== false);

        $this->client->setHeader('X-String', null);

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'X-String: string') === false);
    }

    public function testAcceptingRemoteCookies(): void
    {
        $this->client->setCookie('test', 'item');

        $headers = $this->client->showHeaders();
        self::assertTrue(strpos($headers, 'Cookie: test=item') !== false);


        $this->client->setCookie('xxx', 'www');
        $headers = $this->client->showHeaders();
        self::assertRegExp('/Cookie: test=item;.*?xxx=www/', $headers);
    }

    /**
     * @expectedException \Alpari\Components\SoapClient\Exception\TimeoutSoapFault
     * @expectedExceptionMessageRegExp /Operation timed out after \d+ milliseconds with 0 bytes received/
     */
    public function testTimeoutRequest(): void
    {
        $client = new SoapClient(
            $this->getWsdlServerUrl(),
            ['features' => SOAP_WAIT_ONE_WAY_CALLS]
        );

        $client->setTimeout(1);

        $client->wait(60);
    }

    /**
     * @param bool        $isEnabled
     * @param string|null $prefix
     * @param string|null $expectedCacheName
     *
     * @return void
     * @dataProvider wsdlCachingDataProvider
     */
    public function testCachingWsdl(bool $isEnabled, ?string $prefix, ?string $expectedCacheName): void
    {
        ini_set('soap.wsdl_cache_enabled', (string) (int) $isEnabled);
        ini_set('soap.wsdl_cache_dir', vfsStreamWrapper::getRoot()->url());

        $client = new SoapClient($this->getWsdlServerUrl(), ['cache_prefix' => $prefix]);

        $client->echoString('no matter what is written here');

        self::assertSame($isEnabled, vfsStreamWrapper::getRoot()->hasChildren());
        if ($expectedCacheName !== null) {
            foreach (vfsStreamWrapper::getRoot()->getChildren() as $child) {
                break;
            }

            self::assertRegExp($expectedCacheName, $child->getName());
        }
    }

    /**
     * @return void
     * @expectedException SoapFault
     * @expectedExceptionMessage not a wsdl
     */
    public function testThatWsdlIsLoadedFromCache(): void
    {
        ini_set('soap.wsdl_cache_enabled', '1');
        ini_set('soap.wsdl_cache_dir', vfsStreamWrapper::getRoot()->url());

        $this->client->echoString('no matter what is written here');

        $file = vfsStreamWrapper::getRoot()->getChildren()[0];

        file_put_contents($file->url(), 'not a wsdl');

        $anotherClient = new SoapClient($this->getWsdlServerUrl());
        $anotherClient->echoString('no matter what is written here');
    }

    /**
     * @return void
     * @expectedException \SoapFault
     * @expectedExceptionMessage Great success
     */
    public function testThatWsdlCanBeLocalResource(): void
    {
        $cacheFile = vfsStreamWrapper::getRoot()->url() . '/wsdl';
        copy($this->getWsdlServerUrl(), $cacheFile);

        $anotherClient = $this->getMockBuilder(SoapClient::class)
            ->setConstructorArgs([$cacheFile])
            ->setMethods(['performCurlRequest'])
            ->getMock();

        $anotherClient->expects(self::once())
            ->method('performCurlRequest')
            ->with(sprintf('http://%s/?echoString', self::$server))
            ->willThrowException(new SoapFault('TEST', 'Great success'));

        $anotherClient->echoString('no matter what is written here');
    }

    public function testGetFunctions(): void
    {
        $functions = $this->client->__getFunctions();
        $methods   = array_map(
            function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(SoapService::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        foreach ($functions as $k => $v) {
            $functions[$k] = preg_replace('/\w+ (\w+)\(.*\)/', '$1', $v);
        }

        self::assertEquals($methods, $functions);
    }

    public function testGetLastInfo(): void
    {
        $testString = 'OiOIoIOIOIoiOIOIoIOIo';
        $this->client->echoString($testString);
        $request = $this->client->__getLastRequest();
        self::assertNotNull($request);
        self::assertNotNull($this->client->__getLastResponse());
        self::assertNotNull($this->client->__getLastRequestHeaders());
        self::assertNotNull($this->client->__getLastResponseHeaders());

        $stub = $this->getMockBuilder(SoapClient::class)
                    ->setConstructorArgs([$this->getWsdlServerUrl()])
                    ->setMethods(['__doRequest'])
                    ->disableProxyingToOriginalMethods()
                    ->getMockForAbstractClass();

        $stub->expects(self::once())
                    ->method('__doRequest')
                    ->willReturnCallback(function ($requestBody) use ($request) {
                        self::assertSame($requestBody, $request);

                        return '';
                    });


        $stub->echoString($testString);
    }

    public function testThatLocationCanBeChanged(): void
    {
        $newLocation = md5(microtime());

        $stub = $this->getMockBuilder(SoapClient::class)
                     ->setConstructorArgs([$this->getWsdlServerUrl()])
                     ->setMethods(['__doRequest'])
                     ->disableProxyingToOriginalMethods()
                     ->getMockForAbstractClass();

        $stub->expects(self::once())
             ->method('__doRequest')
             ->willReturnCallback(function ($requestBody, $location) use ($newLocation) {
                 self::assertSame($newLocation, $location);

                 return '';
             });
        $this->client->__setLocation();

        $stub->__setLocation($newLocation);

        $stub->echoString('xxx-ooo');
    }

    public function wsdlCachingDataProvider(): array
    {
        return [
            [false, null, null],
            [true, null, '/^[0-9a-f]{32}\.wsdl$/'],
            [true, '', '/^[0-9a-f]{32}\.wsdl$/'],
            [true, 'xWSDLka-', '/^xWSDLka-[0-9a-f]{32}\.wsdl$/'],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        parent::setUp();
        vfsStream::setup();

        $this->client = new SoapClient(
            $this->getWsdlServerUrl(),
            ['features' => SOAP_WAIT_ONE_WAY_CALLS]
        );
        $this->client->setLogger(new NullLogger());

        ini_set('soap.wsdl_cache_enabled', '0');
        ini_set('soap.wsdl_cache_dir', vfsStreamWrapper::getRoot()->url());
    }

    /**
     * @return string
     */
    private function getWsdlServerUrl(): string
    {
        return sprintf('http://%s/index.wsdl', self::$server);
    }
}
