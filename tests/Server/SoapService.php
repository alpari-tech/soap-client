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

namespace Alpari\Components\SoapClient\Server;


use Symfony\Component\HttpFoundation\Request;

/**
 * Class SoapService
 */
class SoapService
{
    /**
     * @param string $what
     *
     * @return string
     */
    public function echoString(string $what): string
    {
        return $what;
    }

    /**
     * @return string
     */
    public function showHeaders(): string
    {
        $request = Request::createFromGlobals();
        $headers = [];
        foreach ($request->headers->all() as $headerName => $headerValues) {
            if (is_iterable($headerValues)) {
                foreach ($headerValues as $headerValue) {
                    $headers[] = ucwords($headerName, ' -_') . ": $headerValue";
                }
            } else {
                $headers[] = ucwords($headerName, ' -_') . ": $headerValues";
            }
        }

        return implode("\r\n", $headers) . var_export($request->headers->all(), true);
    }

    public function wait(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @param string $name
     * @param string $value
     *
     * @return string
     */
    public function setCookie(string $name, string $value): string
    {
        header("Set-Cookie: $name=$value");

        return '';
    }

    /**
     * @param string $message
     *
     * @return string
     */
    public function throwException(string $message): string
    {
        throw new \SoapFault('CODE', $message);
    }
}
