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

use Alpari\Components\SoapClient\Server\SoapService;
use Zend\Soap\AutoDiscover;
use Zend\Soap\AutoDiscover\DiscoveryStrategy\ReflectionDiscovery;
use Zend\Soap\Server;

require_once __DIR__ . '/../../vendor/autoload.php';

$discover = new AutoDiscover();
$wsdl = $discover
    ->setClass(SoapService::class)
    ->setServiceName('Test')
    ->setDiscoveryStrategy(new ReflectionDiscovery())
    ->setUri(sprintf('http://%s:%s/', $_SERVER['SERVER_NAME'], $_SERVER['SERVER_PORT']))
    ->generate();
$wsdlXml = $wsdl->toXML();

if ($_SERVER['REQUEST_URI'] === '/index.wsdl') {
    echo $wsdlXml;
    return;
}

$server = new Server('data://text/plain;base64,' . base64_encode($wsdlXml));

$server->setObject(new SoapService());
$server->setClassmap([]);
$server->setReturnResponse(true);

echo $server->handle();
