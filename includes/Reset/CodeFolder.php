<?php
/**
 * Where a plugin or a theme lives on disk.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Archive\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin basename names its folder (dir/file.php) or its single file
 * (file.php); a theme's stylesheet names one folder. Anything else (a nested
 * or unsafe name, a hidden entry) has no folder a reset may remove, so the
 * plugins or themes folder itself is never the answer. WordPress-free.
 */
final class CodeFolder {

	/**
	 * @param string $pluginsDir The plugins folder.
	 * @param string $basename   Plugin basename.
	 * @return string|null The plugin's folder or single file, or null.
	 */
	public static function plugin( string $pluginsDir, string $basename ): ?string {
		$parts = explode( '/', $basename );
		if ( 1 === count( $parts ) ) {
			return self::plain( $parts[0] ) && str_ends_with( $parts[0], '.php' ) ? rtrim( $pluginsDir, '/' ) . '/' . $parts[0] : null;
		}

		return 2 === count( $parts ) && self::plain( $parts[0] ) && self::plain( $parts[1] ) ? rtrim( $pluginsDir, '/' ) . '/' . $parts[0] : null;
	}

	/**
	 * @param string $themeRoot  The theme folder.
	 * @param string $stylesheet Theme stylesheet (its folder name).
	 * @return string|null The theme's folder, or null.
	 */
	public static function theme( string $themeRoot, string $stylesheet ): ?string {
		return self::plain( $stylesheet ) ? rtrim( $themeRoot, '/' ) . '/' . $stylesheet : null;
	}

	/**
	 * @param string $path Path.
	 * @return bool Whether the path itself is a link.
	 */
	public static function isLink( string $path ): bool {
		clearstatcache( true, $path );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		return @is_link( $path );
	}

	private static function plain( string $name ): bool {
		return ! str_contains( $name, '/' ) && PathGuard::isSafeEntryName( $name ) && ! str_starts_with( $name, '.' );
	}
}
