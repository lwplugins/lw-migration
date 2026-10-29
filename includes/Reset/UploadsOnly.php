<?php
/**
 * Lets core delete an attachment's files only inside the uploads folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Storage\FolderIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Core's wp_delete_attachment() checks only an attachment's main file against the
 * uploads folder; its sizes, original and backup sizes go next to the main
 * file, wherever an absolute _wp_attached_file puts it (a theme, our backups).
 * While a reset deletes an attachment, the wp_delete_file filter (run last)
 * clears every path whose real folder (by identity, links resolved) does not
 * exist, is not inside this site's uploads folder, or lies in a kept folder
 * (our storage, the backups folders, the site's own folders) below the
 * uploads folder: core then deletes nothing there. The kept folders above the
 * uploads folder (wp-content, ABSPATH) hold every upload, so they never count.
 */
final class UploadsOnly {

	/**
	 * @param string                $uploadsDir This site's uploads folder.
	 * @param array<string, string> $kept       "device:inode" => path, folders whose files never go.
	 */
	public function __construct( private string $uploadsDir, private array $kept ) {
	}

	/**
	 * Deletes an attachment through core with the guard on (in the current site's context).
	 *
	 * @param int                   $id   Attachment id.
	 * @param array<string, string> $kept Folders whose files never go, by identity.
	 * @return bool Whether core deleted it.
	 */
	public static function delete( int $id, array $kept ): bool {
		$guard  = new self( untrailingslashit( (string) wp_upload_dir( null, false )['basedir'] ), $kept );
		$filter = [ $guard, 'filter' ];
		add_filter( 'wp_delete_file', $filter, PHP_INT_MAX );
		try {
			return (bool) wp_delete_attachment( $id, true );
		} finally {
			remove_filter( 'wp_delete_file', $filter, PHP_INT_MAX );
		}
	}

	/**
	 * The wp_delete_file filter.
	 *
	 * @param mixed $file Path core is about to delete.
	 * @return string The path, or '' (core deletes nothing).
	 */
	public function filter( $file ): string {
		return is_string( $file ) && $this->allows( $file ) ? $file : '';
	}

	/**
	 * @param string $file Path core is about to delete.
	 */
	private function allows( string $file ): bool {
		$uploads  = FolderIdentity::of( $this->uploadsDir );
		$absolute = str_starts_with( $file, '/' ) || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $file );
		$dir      = dirname( $file );
		if ( null === $uploads || ! $absolute || ! is_dir( $dir ) ) {
			return false; // No real folder to compare (chain() would fall back to one above it): fail closed.
		}
		$below = [];
		foreach ( FolderIdentity::chain( $dir ) as $link ) {
			if ( $link['id'] === $uploads ) {
				return ! FolderIdentity::passes( $below, $this->kept ); // Only folders inside uploads: wp-content and ABSPATH lie above every upload.
			}
			$below[] = $link;
		}

		return false;
	}
}
