<?php

namespace LightweightPlugins\Migration\Vendor\phpseclib3\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Indicates an absent or malformed packet length header
 */
class InvalidPacketLengthException extends ConnectionClosedException
{
}
