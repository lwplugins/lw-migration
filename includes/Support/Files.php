<?php
/**
 * File tree helpers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Removing a tree never follows a symlink out of it: a link (also the root
 * itself) is unlinked, never emptied. A folder that cannot be listed is left
 * in place instead of being walked.
 *
 * Job folders and work files are owner-only, set with chmod after creation so
 * the result does not depend on the umask.
 */
final class Files {

	/** Mode of a job folder. */
	public const PRIVATE_DIR = 0700;

	/** Mode of a job work file. */
	public const PRIVATE_FILE = 0600;

	/**
	 * Creates an owner-only folder (and its missing parents) when none is there.
	 *
	 * @param string $dir Folder.
	 * @return bool True when the folder exists afterwards.
	 */
	public static function privateDir( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}
		$parent = dirname( $dir );
		if ( file_exists( $dir ) || is_link( $dir ) || $parent === $dir || ! self::privateDir( $parent ) || ! is_writable( $parent ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent request may create it after the checks above; is_dir() below decides.
		if ( @mkdir( $dir, self::PRIVATE_DIR ) ) {
			chmod( $dir, self::PRIVATE_DIR );
		}
		clearstatcache( true, $dir );

		return is_dir( $dir );
	}

	/**
	 * Creates an empty owner-only file when nothing is at $path yet; an existing
	 * file (or link) is left as it is.
	 *
	 * @param string $path File.
	 * @return void
	 */
	public static function privateFile( string $path ): void {
		$dir = dirname( $path );
		if ( file_exists( $path ) || is_link( $path ) || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}
		$handle = fopen( $path, 'cb' );
		if ( false !== $handle ) {
			fclose( $handle );
			chmod( $path, self::PRIVATE_FILE );
		}
	}

	/**
	 * Deletes one file or link without a PHP warning (it would carry the
	 * server path); the caller reports a failure in its own words.
	 *
	 * @param string $path File or link.
	 * @return bool True when nothing is left at $path.
	 */
	public static function removeFile( string $path ): bool {
		if ( ! is_file( $path ) && ! is_link( $path ) ) {
			return ! file_exists( $path );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked above; a failure (permissions, a concurrent delete) is reported by the caller without server paths.
		@unlink( $path );
		clearstatcache( true, $path );

		return ! is_file( $path ) && ! is_link( $path );
	}

	/**
	 * Removes what it can; anything it may not delete (a read-only folder) is
	 * left in place quietly, never with a PHP warning that carries server paths.
	 *
	 * @param string $path File, link or folder.
	 * @return void
	 */
	public static function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			self::removeFile( $path );
			return;
		}
		$items = self::listing( $path );
		if ( null === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			self::removeTree( $path . '/' . $item );
		}
		clearstatcache();
		if ( is_writable( dirname( $path ) ) && [] === self::listing( $path ) ) {
			rmdir( $path );
		}
	}

	/**
	 * @param string $dir Folder.
	 * @return array<int, string>|null Names in it without . and ..; null when it is no folder or cannot be listed.
	 */
	private static function listing( string $dir ): ?array {
		$items = is_dir( $dir ) && is_readable( $dir ) && is_executable( $dir ) ? scandir( $dir ) : false;

		return false === $items ? null : array_values( array_diff( $items, [ '.', '..' ] ) );
	}
}
