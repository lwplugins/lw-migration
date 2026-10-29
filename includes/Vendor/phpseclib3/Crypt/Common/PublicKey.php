<?php

namespace LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common;

defined( 'ABSPATH' ) || exit;

/**
 * PublicKey interface
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2009 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

/**
 * PublicKey interface
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
interface PublicKey
{
    public function verify($message, $signature);
    //public function encrypt($plaintext);
    public function toString($type, array $options = []);
    public function getFingerprint($algorithm);
}
