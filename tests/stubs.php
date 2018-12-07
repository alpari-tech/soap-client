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

use org\bovigo\vfs\vfsStreamWrapper;

function tempnam(string $dir = null, string $prefix = ''): string
{
    $dir = $dir ?: vfsStreamWrapper::getRoot()->url();

    $name = preg_replace('/[^A-Za-z0-9]+/', '', base64_encode(random_bytes(128)));

    $tmpFile = rtrim($dir, DIRECTORY_SEPARATOR). DIRECTORY_SEPARATOR . $prefix . $name;

    file_put_contents($tmpFile, '');

    return $tmpFile;
}
