<?php
/**
 * Free disk space.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Null when the host does not tell (disabled function, open_basedir).
 */
final class DiskSpace {

	public static function free( string $dir ): ?int {
		if ( ! function_exists( 'disk_free_space' ) || ! is_dir( $dir ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a restricted host must not break a backup; null means unknown.

		return false === $free ? null : (int) $free;
	}
}
