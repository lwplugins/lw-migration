<?php
/**
 * Creates output folders.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Checks before mkdir, so a folder that cannot be made is an exception with
 * the path as given, never a PHP warning with the server's absolute path.
 */
final class Folder {

	/**
	 * @param string $dir Folder to create with its missing parents.
	 * @return void
	 * @throws ArchiveException When something else is in the way or the nearest existing parent is not writable.
	 */
	public static function ensure( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}
		$ancestor = $dir;
		while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
			$ancestor = dirname( $ancestor );
		}
		if ( ! is_dir( $ancestor ) || ! is_writable( $ancestor ) || ! mkdir( $dir, 0755, true ) ) {
			throw new ArchiveException( sprintf( 'Cannot create directory: %s', $dir ) );
		}
	}
}
