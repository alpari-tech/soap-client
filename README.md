# Soap client

Extended PHP soap client with additional features.

## Features

 1. Timeout support for requests
 1. Asynchronous SOAP requests
 1. Custom HTTP-request headers
 1. Logging support with PSR-3-compatible logger
 1. Special test client for writing tests on your server
 
## Prerequisites

The `soap`, `dom`, `curl`, `libxml` extensions must be pre-installed on the server in order to use this library.

## Installing

The recommended way is using a [composer](https://getcomposer.org/download/)

```
$ composer require alpari/soap-client:~1.0 --prefer-dist|--prefer-source
```

## Usage

### Simple SOAP request

```php
use Alpari\Components\SoapClient\Client\SoapClient;

$client = new SoapClient('http://example.com/service?wsdl');

$result = $client->targetServiceMethod(1, 2);
```

### Async SOAP requests

This feature execute multiple SOAP requests in parallel. 

```php
use Alpari\Components\SoapClient\Client\SoapClient;

$client = new SoapClient('http://example.com/service?wsdl');
$responses = $client->async(function (SoapClient $client) {
    $client->methodA(25);
    $client->methodB(64);
});
```

The _responses_ variable will contain the result of all calls made inside `async` function in order
they were called there. In example above the _responses[0]_ is the return value of
remote method `methodA`, _response[1]_ is the return value of `methodB`. 
If there was an exception during some method call, the corresponding array item will contain an exception object
of `SoapFault` class.
 
### Requests with timeout

To set timeout for request use `setTimeout` value with a single parameter - time to wait request completion
in milliseconds. If `setTimeout` method is not called explicitly, the value of php-ini setting
`default_socket_timeout` will be used.
 
```php
$client = new SoapClient('http://example.com/service?wsdl');

$client->setTimeout(60000); // in milli-seconds

$result = $client->targetServiceMethod(1, 2);
```
 
### Passing custom HTTP headers

```php
$client = new SoapClient('http://example.com/service?wsdl');

$client->setHeader('X-Header-Name', 'A value');

$result = $client->targetServiceMethod(1, 2);
```
 
### Logging requests

The `SoapClient` implements `Psr\Log\LoggerAwareInterface`.

```php
use Psr\Log\NullLogger;

$client = new SoapClient('http://example.com/service?wsdl');

$client->setLogger(new NullLogger());

$result = $client->targetServiceMethod(1, 2);
```

### SOAP client constructor options

This implementation of SOAP client supports additional array of options:

 - `local_cert` - client certificate file name to use for SSL connection.
 - `local_key` - private for client certificate, if it is stored as a separate file.
 - `passphrase` - password for certificate private key.
 - `ca_bundle` - file name with trusted CAs for verifying server certificate. If not provided, the system one is used.
 - `curl` - array with additional curl options. See all possible options [here](http://php.net/manual/en/function.curl-setopt.php).
 - `cache_prefix` - file name prefix for generated wsdl cache file names 
 
### Testing own SOAP servers

To test your own SOAP server you need to install additional packages if you steel don't have them
in a project:

```bash
composer require --dev symfony/http-kernel symfony/browser-kit
```

If the project is based on [Symfony framework](https://symfony.com), just use `Alpari\Components\SoapClient\Test\TestSoapClient` in
the test-case

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Alpari\Components\SoapClient\Test\TestSoapClient;

class SoapServiceTest extends WebTestCase
{
    public function testSoapServiceMethod()
    {
        $httpClient = static::createClient();
        $soapClient = new TestSoapClient($soapClient, 'wsdl url');

        $result = $soapClient->SoapServiceMethod('Method args');
        
        self::assertEquals(200, $httpClient->getResponse()->getStatusCode());
        self::assertEquals('something', $result);
    }
}
```

If the project is based on some other framework you will need an implementation of `Symfony\Component\HttpKernel\HttpKernelInterface`
in your test which will redirect all calls to application's entry point and return
symfony `Response` object:

```php
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Alpari\Components\SoapClient\Test\TestSoapClient;
use Symfony\Component\HttpKernel\Client;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Response;

class SoapServiceTest extends TestCase
{
    public function testSoapServiceMethod()
    {
        $httpClient = new Client(new class implements HttpKernelInterface {
            public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
            {
                return new Response(
                  // rendered content from application front controller 
                );
            }
        });
        $soapClient = new TestSoapClient($soapClient, 'wsdl url');

        $result = $soapClient->SoapServiceMethod('Method args');
        
        self::assertEquals(200, $httpClient->getResponse()->getStatusCode());
        self::assertEquals('something', $result);
    }
}
```


## Running the tests

Tests are written with phpunit. To run the tests use the following command:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details

