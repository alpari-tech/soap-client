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

namespace Alpari\Components\SoapClient\Exception;

use SoapFault;

/**
 * Exception that thrown when timeout occurred during the service call
 */
class TimeoutSoapFault extends SoapFault
{

}
