<?php

namespace LightweightPlugins\Migration\Vendor\phpseclib3\File\ASN1\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * AttributeValue
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
 * AttributeValue
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
abstract class AttributeValue
{
    const MAP = ['type' => ASN1::TYPE_ANY];
}
