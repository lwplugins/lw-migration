<?php
/**
 * The guarded web transport (Http\SafeTransport) in Brain Monkey tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;

/**
 * install() puts a fake server behind wp_safe_remote_request the way
 * SafeTransport reaches it: every name resolves to one public address (until
 * MonkeyTestCase resets the resolver), and the request fires cURL's hooks, so
 * the pin is applied. is_wp_error() also knows a real WP_Error (the guard's
 * own refusals), when its class is loaded.
 */
final class SafeWeb {

	/**
	 * @param \Closure $inner fn( string $url, array $args ): array|FakeWpError.
	 * @param string   $ip    The address every name resolves to.
	 */
	public static function install( \Closure $inner, string $ip = '93.184.216.34' ): CurlHooks {
		$curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn(): array => [ $ip ] );
		$send = $curl->wrap( $inner );
		Functions\when( 'wp_safe_remote_request' )->alias( static fn( $url, $args ) => $send( $url, $args ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => $r instanceof FakeWpError || $r instanceof \WP_Error );

		return $curl;
	}
}
