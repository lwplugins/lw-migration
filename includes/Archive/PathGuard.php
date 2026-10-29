<?php
/**
 * Keeps extracted entries inside the target directory.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\ControlChars;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects traversal, absolute paths, NUL bytes, backslashes and symlink escapes.
 * Messages quote the entry name with its control characters shown as escapes.
 */
final class PathGuard {

	public static function isSafeEntryName( string $name ): bool {
		if ( '' === $name || str_contains( $name, "\0" ) || str_contains( $name, '\\' ) ) {
			return false;
		}
		if ( str_starts_with( $name, '/' ) || 1 === preg_match( '/^[a-zA-Z]:/', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	public static function resolve( string $baseDir, string $entryName ): string {
		if ( ! self::isSafeEntryName( $entryName ) ) {
			throw new UnsafePathException( sprintf( 'Unsafe path in archive: %s', ControlChars::escape( $entryName ) ) );
		}

		$base = realpath( $baseDir );
		if ( false === $base || ! is_dir( $base ) ) {
			throw new UnsafePathException( sprintf( 'Target directory does not exist: %s', $baseDir ) );
		}

		$target       = $base . '/' . $entryName;
		$ancestor     = dirname( $target );
		$base_len     = strlen( $base );
		$ancestor_len = strlen( $ancestor );
		while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && $ancestor_len > $base_len ) {
			$ancestor     = dirname( $ancestor );
			$ancestor_len = strlen( $ancestor );
		}
		$real = realpath( $ancestor );
		if ( false === $real || ( $real !== $base && ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) ) {
			throw new UnsafePathException( sprintf( 'Archive path escapes the target directory: %s', ControlChars::escape( $entryName ) ) );
		}
		if ( is_link( $target ) ) {
			throw new UnsafePathException( sprintf( 'Refusing to overwrite a symlink: %s', ControlChars::escape( $entryName ) ) );
		}

		return $target;
	}
}
