<?php
/**
 * In-memory transients.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * set/get/delete_transient like WordPress: get answers false for a missing
 * name, delete answers whether a value was there (the single-use check of
 * OAuthState relies on that answer). TTLs are recorded, not enforced.
 */
final class FakeTransients {

	/** @var array<string, array{0: mixed, 1: int}> */
	public static array $store = [];

	public static function reset(): void {
		self::$store = [];
	}

	public static function install(): void {
		self::reset();
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value, $ttl = 0 ): bool {
				self::$store[ (string) $name ] = [ $value, (int) $ttl ];
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( static fn( $name ) => self::$store[ (string) $name ][0] ?? false );
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ): bool {
				$had = isset( self::$store[ (string) $name ] );
				unset( self::$store[ (string) $name ] );
				return $had;
			}
		);
	}
}
