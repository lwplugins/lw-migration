<?php
/**
 * In-memory WordPress options for Brain Monkey tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * add_option() refuses an existing name and update_option() writes nothing when the
 * value is unchanged (a missing option counts as false), like WordPress. There is no
 * object cache: wp_cache_delete() is a no-op.
 */
final class FakeOptions {

	/** @var array<string, mixed> */
	public static array $values = [];

	public static function reset(): void {
		self::$values = [];
	}

	/**
	 * @param array<string, mixed> $initial Options present before the test.
	 */
	public static function install( array $initial = [] ): void {
		self::$values = $initial;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				return array_key_exists( $name, self::$values ) ? self::$values[ $name ] : $fallback;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value = '' ) {
				if ( array_key_exists( $name, self::$values ) ) {
					return false;
				}
				self::$values[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) {
				$old = array_key_exists( $name, self::$values ) ? self::$values[ $name ] : false;
				if ( $value === $old || serialize( $value ) === serialize( $old ) ) {
					return false;
				}
				self::$values[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) {
				$had = array_key_exists( $name, self::$values );
				unset( self::$values[ $name ] );
				return $had;
			}
		);
	}
}
