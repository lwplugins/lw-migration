<?php
/**
 * Temporary directory helper for tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

/**
 * Creates and recursively removes scratch directories.
 */
final class TempDir {

	public static function create(): string {
		$dir = sys_get_temp_dir() . '/lwm-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0755, true );

		return (string) realpath( $dir );
	}

	public static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::remove( $dir . '/' . $item );
		}
		rmdir( $dir );
	}
}
