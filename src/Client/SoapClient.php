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

use Alpari\Components\SoapClient\Exception\DelayedRequestException;
use Alpari\Components\SoapClient\Exception\TimeoutSoapFault;
use DOMDocument;
use LibXMLError;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SoapClient as PhpSoapClient;
use SoapFault;
use SoapVar;

/**
 * SOAP client
 *
 * Extends standard PHP SoapClient class.
 * Comparing with original class, __doRequest() method was overridden to support timeouts.
 */
class SoapClient extends PhpSoapClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Defines wsdl cache relative filename. Placeholder holds current username and md5-hash of wsdl uri
     */
    private const WSDL_CACHE_RELATIVE_PATH_PATTERN = 'alpari.wsdl-%s-%s';

    /**
     * Last request body as SOAP request
     *
     * @var string
     */
    protected $lastRequestBody = '';

    /**
     * Last response body as SOAP request
     *
     * @var string
     */
    protected $lastResponseBody = '';

    /**
     * Last response headers
     *
     * @var string
     */
    protected $lastResponseHeaders = '';

    /**
     * Last response headers
     *
     * @var string
     */
    protected $lastRequestHeaders = '';

    /**
     * Name of the last function, which was called by the client
     *
     * @var string
     */
    protected $lastFunction = '';

    /**
     * List of arguments for last function call
     *
     * @var array
     */
    protected $lastArguments = [];

    /**
     * Last request information
     *
     * @var string[]
     */
    protected $lastRequestInfo = [];

    /**
     * Default options for client
     *
     * @var array
     */
    protected $options = [
        'timeout'      => null,
        'curl'         => [],
        'headers'      => [],
    ];

    /**
     * Url to wsdl used in SoapClient lazy initialization
     *
     * @var string
     */
    protected $wsdl;

    /**
     * Preconfigured client options used in SoapClient lazy initialization
     *
     * @var array
     */
    protected $parentOptions = [];

    /**
     * Flag holds information whether client was initialized lazy or not
     *
     * @var bool
     */
    protected $initialized = false;

    /**
     * Asynchronous mode for requests
     *
     * @var bool
     */
    protected $isAsync = false;

    /**
     * Array with information about asynchronous queries
     *
     * @var array
     */
    protected $asyncQueries;

    /**
     * Forced CURL query for handling multi-queries inside $
     *
     * @var resource
     */
    protected $forcedQuery;

    /**
     * Forced CURL response for handling multi-queries inside SoapClientTimeout
     *
     * @var string
     */
    protected $forcedResponse;

    /**
     * Array where key is curl protocol
     *
     * @var array
     */
    protected $curlSupportedProtocols;

    /**
     * Default constructor for soap client with timeout
     *
     * @param string $wsdl Wsdl path or string definition
     * @param array $options Additional options for client
     */
    public function __construct($wsdl, array $options = [])
    {
        $parentOptions = array_diff_key($options, $this->options);

        $this->options = array_intersect_key($options, $this->options) + $this->options;

        if (isset($options['local_cert'])) {
            $this->options['curl'] += [CURLOPT_SSLCERT => $options['local_cert']];
        }

        if (isset($options['local_key'])) {
            $this->options['curl'] += [CURLOPT_SSLKEY => $options['local_key']];
        }

        if (isset($options['ca_bundle'])) {
            $this->options['curl'] += [CURLOPT_CAINFO => $options['ca_bundle']];
        }

        if (isset($options['passphrase'])) {
            $this->options['curl'] += [CURLOPT_SSLCERTPASSWD => $options['passphrase']];
        }

        if (isset($options['cache_prefix'])) {
            $this->options['cache_prefix'] = $options['cache_prefix'];
        }

        //preparing options for lazy initialization
        $this->wsdl = $wsdl;
        $this->parentOptions = $parentOptions;

        $this->curlSupportedProtocols = array_change_key_case(array_flip(curl_version()['protocols']), CASE_LOWER);
    }

    /**
     * Set timeout
     *
     * @param int $timeout Timeout in milli-sec
     *
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->options['timeout'] = $timeout;

        return $this;
    }

    /**
     * Set a HTTP header for request. If value is null, the header will be deleted
     *
     * @param string $name Header name
     * @param mixed $value Mixed value, must be castable to string
     *
     * @return self
     */
    public function setHeader(string $name, $value = null): self
    {
        if ($value === null) {
            unset ($this->options['headers'][$name]);
        } else {
            $this->options['headers'][$name][] = $value;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function __setCookie($name, $value = null)
    {
        if ($value === null) {
            parent::__setCookie($name);
        } else {
            parent::__setCookie($name, (string) $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __getLastResponseHeaders()
    {
        return $this->lastResponseHeaders;
    }

    /**
     * {@inheritdoc}
     */
    public function __getLastRequestHeaders()
    {
        return $this->lastRequestHeaders;
    }

    /**
     * {@inheritdoc}
     */
    public function __getLastRequest()
    {
        return $this->lastRequestBody;
    }

    /**
     * {@inheritdoc}
     */
    public function __getLastResponse()
    {
        return $this->lastResponseBody;
    }

    /**
     * Returns an information about last request
     *
     * @see http://php.net/manual/en/function.curl-getinfo.php
     *
     * @return array
     */
    public function __getLastRequestInfo()
    {
        return $this->lastRequestInfo;
    }

    /**
     * Initializes lazy the client and proxies call to the client
     *
     * {@inheritdoc}
     */
    public function __soapCall($function, $arguments, $options = null, $inputHeaders = null, &$outputHeaders = null)
    {
        if (!$this->initialized) {
            $this->init();
        }

        $this->lastFunction  = $function;
        $this->lastArguments = $arguments;
        try {
            $soapResponse = parent::__soapCall($function, $arguments, $options, $inputHeaders, $outputHeaders);
            $this->extractSoapVars($soapResponse);
            return $soapResponse;
        } catch (DelayedRequestException $e) {
            $this->asyncQueries[(string) $e->getRequest()] = [
                'request'       => $e->getRequest(),
                'function'      => $function,
                'arguments'     => $arguments,
                'options'       => $options,
                'inputHeaders'  => $inputHeaders,
                'outputHeaders' => &$outputHeaders,
                'requestIndex'  => count($this->asyncQueries),
            ];

            return null;
        }
    }

    /**
     * Initializes lazy the client and proxies call to the client
     *
     * {@inheritdoc}
     */
    public function __call($functionName, $arguments)
    {
        if (!$this->initialized) {
            $this->init();
        }

        return $this->__soapCall($functionName, $arguments);
    }



    /**
     * Initializes lazy the client and proxies call to the client
     *
     * {@inheritdoc}
     */
    public function __getFunctions()
    {
        if (!$this->initialized) {
            $this->init();
        }

        return parent::__getFunctions();
    }

    /**
     * Initializes lazy the client and proxies call to the client
     *
     * {@inheritdoc}
     */
    public function __getTypes()
    {
        if (!$this->initialized) {
            $this->init();
        }

        return parent::__getTypes();
    }

    /**
     * Sends request with cURL if timeout was set
     *
     * @param string $requestBody  Request
     * @param string $location Location
     * @param string $action   Action
     * @param int    $version  Version
     * @param int    $oneWay   One way
     *
     * @throws SoapFault
     * @throws TimeoutSoapFault
     *
     * @return string
     */
    public function __doRequest($requestBody, $location, $action, $version, $oneWay = 0)
    {
        [, $action] = explode('#', $action) + ['/dev/null', 'none'];
        $serviceUrl = $location . '?' . $action;

        $this->logger()->info(
            'Performing request',
            [
                'function'  => $this->lastFunction,
                'arguments' => $this->formatArguments($this->lastFunction, $this->lastArguments),
                'location'  => $serviceUrl,
            ]
        );

        [$responseHeaders, $responseBody] = $this->performCurlRequest($serviceUrl, $action, $version, [], $requestBody);
        $this->logger()->debug('Request finished', $this->lastRequestInfo);

        $this->extractCookies($responseHeaders);

        return $oneWay && !$this->isWaitingForOneWay() ? '' : $responseBody;
    }

    /**
     * Performs asynchronous queries in parallel
     *
     * @param callable $callable Callback that will receive current instance for asynchronous quering
     *
     * @throws \SoapFault For nested async query
     * @throws \Exception
     *
     * @return array List of responses
     */
    public function async(callable $callable): array
    {
        if ($this->isAsync) {
            throw new SoapFault('Client', 'Nested asynchronous calls are not supported.');
        }

        try {
            $this->isAsync      = true;
            $this->asyncQueries = [];

            // call closure and record all queries in $this->asyncQueries
            $callable($this);
            $this->isAsync = false;

        } catch (\Throwable $exception) {
            $this->isAsync = false;
            throw $exception;
        }

        $multiSoapQuery = curl_multi_init();
        foreach ($this->asyncQueries as $asyncQuery) {
            curl_multi_add_handle($multiSoapQuery, $asyncQuery['request']);
        }

        // Async reading and synchronization
        $asyncResponses = [];
        do {
            while (CURLM_CALL_MULTI_PERFORM === curl_multi_exec($multiSoapQuery, $active)) {
            }

            // a request was just completed -- find out which one
            // https://www.onlineaspect.com/2009/01/26/how-to-use-curl_multi-without-blocking/
            while($done = curl_multi_info_read($multiSoapQuery)) {
                $asyncQuery = $this->asyncQueries[(string)$done['handle']];
                unset($this->asyncQueries[(string)$done['handle']]);

                $this->forcedQuery    = $asyncQuery['request'];
                $this->forcedResponse = curl_multi_getcontent($done['handle']);
                $responseIndex        = $asyncQuery['requestIndex'];
                try {
                    $asyncResponses[$responseIndex] = $this->__soapCall(
                        $asyncQuery['function'],
                        $asyncQuery['arguments'],
                        $asyncQuery['options'],
                        $asyncQuery['inputHeaders'],
                        $asyncQuery['outputHeaders']
                    );
                } catch (\Exception $exception) {
                    $asyncResponses[$responseIndex] = $exception;
                }

                $this->forcedQuery    = null;
                $this->forcedResponse = null;

                curl_multi_remove_handle($multiSoapQuery, $done['handle']);
            }

            curl_multi_select($multiSoapQuery);
        } while ($active);

        return $asyncResponses;
    }

    /**
     * Lazy initializes client
     *
     * @return void
     */
    protected function init(): void
    {
        if ($this->wsdl) {
            // fetching and caching base64-formatted wsdl in wsdl-mode
            $this->wsdl = $this->fetchWsdl();
        }
        parent::__construct($this->wsdl, $this->parentOptions);
        $this->initialized = true;
    }

    /**
     * Performs curl request and fills lastRequestInfo and lastResponseHeaders
     * Makes POST-request by default
     *
     * @param string      $serviceUrl  url to be requested
     * @param string|null $action      SOAP service action
     * @param int         $soapVersion Version of SOAP request
     * @param array       $addCurlOpts list of additional curl options to replace default ones
     *                                 ATTENTION! use this option to replace only $baseCurlOptions from prepareConnection
     *                                 to decorate current request (or add opposite parameter to $baseCurlOptions from prepareConnection)!
     *                                 Otherwise you can apply your options for all next requests
     *
     * @param string      $requestBody request body
     *
     * @return array with response headers and response body
     * @throws SoapFault in case of fault
     * @throws TimeoutSoapFault in case of timeout
     */
    protected function performCurlRequest(string $serviceUrl, ?string $action, ?int $soapVersion, array $addCurlOpts = [], string $requestBody = ''): array
    {
        $curlRequest = $this->prepareConnection($requestBody, $serviceUrl, $action, $soapVersion, $addCurlOpts);

        if ($this->isAsync) {
            throw new DelayedRequestException($curlRequest);
        }

        $response     = $this->forcedQuery ? $this->forcedResponse : curl_exec($curlRequest);
        $curlInfo     = curl_getinfo($curlRequest);
        $errorCode    = curl_errno($curlRequest);
        $errorMessage = curl_error($curlRequest);

        // catching all the fault situations except server-side faults (server response codes >= 400)
        // CURLOPT_FAILONERROR is switched off by default
        // BUG: for multi query errorCode is always equal to 0, so check the errorMessage too
        if ($errorCode || $errorMessage) {
            if ($errorCode === CURLE_OPERATION_TIMEOUTED) {
                throw new TimeoutSoapFault('Client.Timeout', $errorMessage);
            }
            throw new SoapFault('Server', $errorMessage);
        }

        if (strpos($response, 'HTTP/') === 0) {
            // Support for HTTP 1xx responses, there will be three parts instead of two
            if (strpos($response, 'HTTP/1.1 10') === 0) {
                [, $responseHeaders, $responseBody] = explode("\r\n\r\n", $response, 3);
            } else {
                [$responseHeaders, $responseBody] = explode("\r\n\r\n", $response, 2);
            }
        } else {
            // non-HTTP request support (for example fetching wsdl from filesystem)
            $responseHeaders = '';
            $responseBody    = $response;
        }

        $this->lastRequestInfo     = $curlInfo;
        $this->lastRequestBody     = $requestBody;
        $this->lastResponseHeaders = $responseHeaders;
        $this->lastResponseBody    = $responseBody;

        return [$responseHeaders, $responseBody];
    }

    /**
     * Initializes a new CURL connection or reuses current one
     *
     * @param string $request     Content for request
     * @param string $serviceUrl  Absolute URL for performing request
     * @param string $action      SOAP service action
     * @param int    $soapVersion Version of SOAP request
     * @param array  $addCurlOpts Additional CURL options for request
     *
     * @return resource
     */
    protected function prepareConnection(string $request, string $serviceUrl, ?string $action, ?int $soapVersion, array $addCurlOpts = [])
    {
        if ($this->forcedQuery) {
            return $this->forcedQuery;
        }

        // Cache of connections for reusing keep-alive
        static $connections = [];

        $host   = parse_url($serviceUrl, PHP_URL_HOST);
        $scheme = parse_url($serviceUrl, PHP_URL_SCHEME) ?: 'http';
        $port   = parse_url($serviceUrl, PHP_URL_PORT) ?: getservbyname($scheme, 'tcp');

        if ($this->isAsync || !isset($connections[$host][$port]) || !\is_resource($connections[$host][$port])) {
            $connections[$host][$port] = curl_init();
        }

        $curlRequest = $connections[$host][$port];
        $headers     = $this->prepareHeaders($request, $action, $soapVersion, $host);

        $cookies = $this->prepareCookies();

        $this->lastRequestHeaders = implode("\r\n", $headers);
        if (!empty($cookies)) {
            $this->lastRequestHeaders .= "\r\nCookie: $cookies";
        }

        $options = $this->options['curl'];

        $baseCurlOptions = [
            CURLOPT_URL            => $serviceUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $request,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT_MS     => $this->options['timeout'] ?? ini_get('default_socket_timeout') * 1e3,
            CURLOPT_FOLLOWLOCATION => false,
            // handling servers faults by soap internal handler by default
            CURLOPT_FAILONERROR    => false,
            CURLOPT_COOKIE         => $cookies,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, // verify server CN
        ];

        // setting default options that configures validation of server ssl-certificate on client side
        if (isset($options[CURLOPT_SSL_VERIFYPEER])) {
            $baseCurlOptions[CURLOPT_SSL_VERIFYPEER] = $options[CURLOPT_SSL_VERIFYPEER];
        }
        if (isset($options[CURLOPT_SSL_VERIFYHOST])) {
            $baseCurlOptions[CURLOPT_SSL_VERIFYHOST] = $options[CURLOPT_SSL_VERIFYHOST];
        }

        curl_setopt_array($curlRequest, $addCurlOpts + $baseCurlOptions + $options);

        return $curlRequest;
    }

    /**
     * Prepare headers for CURL format
     *
     * @param string      $request     Content for request
     * @param string|null $action      SOAP service action
     * @param int         $soapVersion Version of SOAP protocol
     * @param string      $host        Name of the host to connect
     *
     * @return array
     */
    protected function prepareHeaders(string $request, ?string $action, ?int $soapVersion, ?string $host): array
    {
        $origin  = $_SERVER['HTTP_HOST'] ?? gethostname();
        $referer = $origin . ($_SERVER['REQUEST_URI'] ?? '');

        $headers = [
            'Host'           => $host,
            'Connection'     => !empty($this->parentOptions['keep_alive']) ? 'Keep-Alive' : 'Close',
            'Content-Length' => \strlen($request),
            'Expect'         => '',
            'Referer'        => $referer
            ] + $this->options['headers'];

        if ($soapVersion === SOAP_1_1) {
            $headers += ['SOAPAction' => $action, 'Content-Type' => 'text/xml; charset=utf-8'];
        } elseif ($soapVersion === SOAP_1_2) {
            $headers += ['Content-Type' => 'application/soap+xml; charset=utf-8; action=' . $action];
        }

        $result = [];
        foreach ($headers as $header => $values) {
            foreach ((array) $values as $value) {
                $result[] = $header . ': ' . $value;
            }
        }

        return $result;
    }

    /**
     * Creates server cookie string
     *
     * @return string
     */
    protected function prepareCookies(): string
    {
        $result = [];
        foreach ($this->__getCookies() as $name => $values) {
            foreach ($values as $value) {
                $result[] = "$name=$value";
            }
        }

        return implode('; ', $result);
    }

    /**
     * Fetches "Set-Cookie"-headers from headers list, extracts only
     * name and value cookie fields ("name=value") and calls parent __setCookie method
     *
     * @param string $responseHeaders all response headers
     *
     * @return void
     */
    protected function extractCookies(string $responseHeaders): void
    {
        $headers = $this->parseHeaders($responseHeaders);

        if (!empty($headers['Set-Cookie'])) {
            $cookies = (array) $headers['Set-Cookie'];
            foreach ($cookies as $cookie) {
                $cookie = explode(';', $cookie);
                [$name, $value] = explode('=', $cookie[0]);
                $this->__setCookie($name, $value);
            }
        }
    }

    /**
     * Parse HTTP headers to array
     *
     * @param string $headers HTTP headers
     *
     * @return array
     */
    protected function parseHeaders(string $headers): array
    {
        if (\function_exists('http_parse_headers')) {
            return http_parse_headers($headers);
        }

        $retVal = [];
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $headers));
        foreach ($fields as $field) {
            $match = null;
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace_callback(
                    '/(?<=^|[\x09\x20\x2D])./',
                    function ($matches) {
                        return strtoupper($matches[0]);
                    },
                    strtolower(trim($match[1]))
                );
                if (isset($retVal [$match [1]])) {
                    $retVal[$match[1]] = [$retVal[$match[1]], $match[2]];
                } else {
                    $retVal[$match[1]] = trim($match[2]);
                }
            }
        }
        return $retVal;
    }

    /**
     * Tries to fetch wsdl into base64-string and throws exception in case
     * of failure (without fatal's as in original soap functionality).
     *
     * ATTENTION! Base64 representation is important because its using
     * disables internal wsdl cache mechanism and allows to emulate it
     * on php-side
     *
     * @throws SoapFault in case of failure
     *
     * @return string wsdl base64 xml representation
     */
    protected function fetchWsdl(): string
    {
        $isWsdlCacheEnabled = $this->isWsdlCacheEnabled();
        if ($isWsdlCacheEnabled && $wsdl = $this->loadWsdlFromCache()) {
            return $wsdl;
        }

        // temporary disable async mode to fetch a wsdl
        $oldAsyncMode  = $this->isAsync;
        $this->isAsync = false;

        // perform GET-request with redirects following in order to get wsdl content
        $scheme = parse_url($this->wsdl, PHP_URL_SCHEME);

        [, $wsdl] = $scheme !== null && isset($this->curlSupportedProtocols[strtolower($scheme)])
            ? $this->performCurlRequest(
                  $this->wsdl,
                  null,
                  null,
                  [
                      CURLOPT_POST           => false,
                      CURLOPT_CUSTOMREQUEST  => 'GET',
                      CURLOPT_FOLLOWLOCATION => true,
                      // in case of all fails (server 400+ response codes, network problems, etc) we are waiting for exceptions.
                      CURLOPT_FAILONERROR    => true,
                  ]
              )
            : [null, file_get_contents($this->wsdl)];

        $this->isAsync = $oldAsyncMode;

        // check that wsdl document is valid xml
        libxml_use_internal_errors(true);
        $xmlWsdl = new DOMDocument();
        if (!$xmlWsdl->loadXML($wsdl)) {
            $wsdlErrors = libxml_get_errors();
            libxml_clear_errors();
            if ($wsdlErrors) {
                $wsdlErrorMessages = array_map(
                    function (LibXMLError $error) { return $error->message; },
                    $wsdlErrors);
                throw new SoapFault(
                    'Client.WSDL', 'WSDL can not be fetched or is not valid xml document. ' . 'Details: ' . implode(
                                     ', ', $wsdlErrorMessages));
            }
        }

        $wsdl = 'data://text/plain;base64,' . base64_encode($wsdl);

        if ($isWsdlCacheEnabled) {
            $this->cacheWsdl($wsdl);
        }

        return $wsdl;
    }

    /**
     * Recursively replaces SoapVar nodes in original soap response with their enc_value properties
     *
     * This method makes possible receiving objects encoded by php soap engine on the server side as a "default type"
     * (for example for returnings such as "array", i.e. without defining the concrete type structure). In this case php
     * nevertheless tries to map complex types inside response using classmap feature, but wraps them in SoapVar object.
     * This method performs unwrapping.
     *
     * @param mixed &$soapResponse original soap response
     */
    private function extractSoapVars(&$soapResponse)
    {
        if ($soapResponse instanceof SoapVar) {
            $soapResponse = $soapResponse->enc_value;
        } elseif (\is_array($soapResponse)) {
            foreach ($soapResponse as $key => &$value) {
                $this->extractSoapVars($value);
            }
        }
    }

    /**
     * Decides whether wsdl caching is enabled.
     *
     * ATTENTION! Now wsdl cache seems to be disabled
     * when result cache setting is equal to WSDL_CACHE_NONE and enabled
     * otherwise ( WSDL_CACHE_DISK (1), WSDL_CACHE_MEMORY (2), WSDL_CACHE_BOTH (3))
     * So we use ONLY DISC CACHE now even in  WSDL_CACHE_MEMORY (2), WSDL_CACHE_BOTH (3) cases
     *
     * @return bool
     */
    private function isWsdlCacheEnabled(): bool
    {
        // check wsdl cache globally enabled / disabled
        if ((bool) ini_get('soap.wsdl_cache_enabled') === false) {
            return false;
        }

        // cache_wsdl soap client value. Setting php-ini value by default
        $cacheWsdlLocal = $this->parentOptions['cache_wsdl'] ?? ini_get('soap.wsdl_cache');

        return $cacheWsdlLocal !== WSDL_CACHE_NONE;
    }

    /**
     * Caches wsdl content
     *
     * @param string $wsdlContent wsdl content
     *
     * @throws RuntimeException in case of failures during cache writing
     *
     * @return void
     */
    private function cacheWsdl(string $wsdlContent): void
    {
        $cacheFile = $this->getWsdlCachePath();
        $dir       = \dirname($cacheFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('Unable to create the "%s" directory for wsdl cache', $dir));
            }
        } elseif (!is_writable($dir)) {
            throw new RuntimeException(sprintf('Unable to write in the "%s" directory', $dir));
        }

        $file = fopen($cacheFile, 'c+b');
        if ($file === false) {
            $this->logger()->warning(sprintf('Failed to write wsdl cache file "%s".', $cacheFile));

            return;
        }

        try {
            if (flock($file, LOCK_EX | LOCK_NB)) {
                $lengthContent = strlen($wsdlContent);
                if (!ftruncate($file, $lengthContent)) {
                    throw new RuntimeException('can not to truncate file');
                }

                $written = fwrite($file, $wsdlContent, $lengthContent);
                if ($written === false || $written !== $lengthContent) {
                    unlink($cacheFile);
                    throw new RuntimeException('can not write data to file');
                }

                flock($file, LOCK_UN);

                chmod($cacheFile, 0600);
            }
        } catch (RuntimeException $e) {
            $this->logger()->warning(
                sprintf('Failed to write wsdl cache file "%s": %s.', $cacheFile, $e->getMessage()),
                ['exception' => $e]
            );
        } finally {
            fclose($file);
        }
    }

    /**
     * Loads wsdl content from cache, returns content in case of success
     * and false in case of failure or when cache is not fresh
     *
     * @return string|null
     */
    private function loadWsdlFromCache(): ?string
    {
        $cacheTtl  = (int) ini_get('soap.wsdl_cache_ttl');
        $cacheFile = $this->getWsdlCachePath();

        if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        $isFresh = filemtime($cacheFile) + $cacheTtl > time();
        if (!$isFresh) {
            return null;
        }

        return file_get_contents($cacheFile);
    }

    /**
     * Returns wsdl cache file path
     *
     * @return string
     */
    private function getWsdlCachePath(): string
    {
        $cacheDir = ini_get('soap.wsdl_cache_dir');

        return sprintf(
            '%s%s%s%s.wsdl',
            rtrim($cacheDir, DIRECTORY_SEPARATOR),
            DIRECTORY_SEPARATOR,
            $this->options['cache_prefix'] ?? '',
            md5($this->wsdl)
        );
    }

    /**
     * Returns true if soap client was configured in order
     * to wait one way calls using SOAP_WAIT_ONE_WAY_CALLS
     * constant and false otherwise
     *
     * @see http://www.php.net/manual/en/soapclient.soapclient.php
     *
     * @return bool
     */
    private function isWaitingForOneWay(): bool
    {
        $features = $this->_features ?? 0;

        return (bool) ($features & SOAP_WAIT_ONE_WAY_CALLS);
    }

    /**
     * Combines values with keys from definition
     *
     * @param string $functionName  Name of the function to call
     * @param array  $lastArguments List of arguments (without keys)
     *
     * @return array Associative array with arguments
     */
    private function formatArguments(string $functionName, array $lastArguments): array
    {
        $argumentNames = [];
        $functions     = $this->__getFunctions();
        foreach ($functions as $functionPrototype) {
            if (!preg_match('/(\w+)\s(\w+)\((.*)\)/i', $functionPrototype, $matches)) {
                continue;
            }
            [,, $functionProtoName, $functionArgs] = $matches;
            if ($functionName !== $functionProtoName) {
                continue;
            }
            if (!preg_match_all('/\$([\S]+)/i', $functionArgs, $argumentList)) {
                continue;
            }
            $argumentNames = $argumentList[1];
        }

        $combinedArguments = $argumentNames ? array_combine($argumentNames, $lastArguments) : $lastArguments;

        return $combinedArguments;
    }

    /**
     * Return logger object for request
     *
     * @return LoggerInterface
     */
    final protected function logger(): LoggerInterface
    {
        if (!$this->logger) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }
}
