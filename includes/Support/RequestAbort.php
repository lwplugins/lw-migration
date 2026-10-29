<?php
/**
 * Keeps a request running after the client hangs up.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Calls ignore_user_abort() where the host allows it. Some hosts list it in
 * disable_functions, and in PHP 8 a disabled function does not exist: there
 * the request keeps the server's default, and the slice still saves its state
 * as it goes.
 */
final class RequestAbort {

	public static function ignore(): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
	}
}
