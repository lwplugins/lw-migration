<?php

namespace LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common;

defined( 'ABSPATH' ) || exit;

/**
 * PrivateKey interface
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2009 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

/**
 * PrivateKey interface
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
interface PrivateKey
{
    public function sign($message);
    //public function decrypt($ciphertext);
    public function getPublicKey();
    public function toString($type, array $options = []);

    /**
     * @param string|false $password
     * @return mixed
     */
    public function withPassword($password = false);
}
