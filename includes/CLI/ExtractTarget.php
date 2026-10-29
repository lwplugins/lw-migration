<?php
/**
 * Where an extraction really lands.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a target folder the way the file system will (existing parts by
 * their real path, symlinks included; the missing rest lexically), so the
 * web-root guard checks the folder that is actually written.
 */
final class ExtractTarget {

	/**
	 * @param string $dir Folder as typed (absolute, or relative to $cwd).
	 * @param string $cwd Current folder.
	 * @return string Absolute path without `.`/`..` segments.
	 */
	public static function resolve( string $dir, string $cwd ): string {
		$path = str_starts_with( $dir, '/' ) ? $dir : rtrim( $cwd, '/' ) . '/' . $dir;
		$real = '/';
		$rest = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( [] !== $rest ) {
					array_pop( $rest );
				} else {
					$real = dirname( $real );
				}
				continue;
			}
			$candidate = rtrim( $real, '/' ) . '/' . $segment;
			$resolved  = [] === $rest ? realpath( $candidate ) : false;
			if ( false === $resolved ) {
				$rest[] = $segment;
			} else {
				$real = $resolved;
			}
		}

		return '' === implode( '/', $rest ) ? $real : rtrim( $real, '/' ) . '/' . implode( '/', $rest );
	}

	/**
	 * @param string             $path  Resolved path.
	 * @param array<int, string> $roots Folders (resolved by their real path when they exist).
	 * @return bool True when $path is one of the roots or inside one.
	 */
	public static function isInside( string $path, array $roots ): bool {
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			$base = false === $real ? rtrim( $root, '/' ) : rtrim( $real, '/' );
			if ( '' === $base || $path === $base || str_starts_with( $path, $base . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
