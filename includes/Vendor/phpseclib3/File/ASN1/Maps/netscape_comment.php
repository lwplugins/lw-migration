<?php

namespace LightweightPlugins\Migration\Vendor\phpseclib3\File\ASN1\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * netscape_comment
 *
 * PHP version 5
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2016 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

use LightweightPlugins\Migration\Vendor\phpseclib3\File\ASN1;

/**
 * netscape_comment
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class netscape_comment
{
    const MAP = ['type' => ASN1::TYPE_IA5_STRING];
}
