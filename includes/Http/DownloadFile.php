<?php
/**
 * The download file on disk.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Pure file handling for a resumable download: the target only ever grows by
 * whole parts, and a resume cuts it back to the saved offset (never pads it).
 */
final class DownloadFile {

	/**
	 * Current size; 0 for a missing file.
	 *
	 * @param string $path File.
	 */
	public static function size( string $path ): int {
		clearstatcache( true, $path );

		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Deletes the file when it exists.
	 *
	 * @param string $path File.
	 */
	public static function remove( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Keeps the first $offset bytes when the file has them, otherwise empties it
	 * (creating it when missing). Returns the size it now has.
	 *
	 * @param string $target Download file.
	 * @param int    $offset Saved offset.
	 * @throws DownloadException When the file cannot be opened or cut.
	 */
	public static function cutBack( string $target, int $offset ): int {
		$keep   = $offset >= 0 && self::size( $target ) >= $offset ? $offset : 0;
		$handle = fopen( $target, 'c+b' );
		if ( false === $handle ) {
			throw new DownloadException( 'Cannot prepare the download file.' );
		}
		$cut = ftruncate( $handle, $keep );
		fclose( $handle );
		if ( ! $cut ) {
			throw new DownloadException( 'Cannot prepare the download file.' );
		}

		return $keep;
	}

	/**
	 * Appends the part; on a failed write the target is cut back to $from.
	 *
	 * @param string $part   Downloaded part.
	 * @param string $target Download file.
	 * @param int    $from   Size of the target before the append.
	 * @throws DownloadException When the part cannot be read or appended.
	 */
	public static function append( string $part, string $target, int $from ): void {
		$in = fopen( $part, 'rb' );
		if ( false === $in ) {
			throw new DownloadException( 'Cannot read the downloaded part.' );
		}
		$out = fopen( $target, 'ab' );
		if ( false === $out ) {
			fclose( $in );
			throw new DownloadException( 'Cannot write the downloaded file.' );
		}
		$copied = stream_copy_to_stream( $in, $out );
		fflush( $out );
		$whole = false !== $copied && self::size( $part ) === $copied;
		if ( ! $whole ) {
			ftruncate( $out, $from );
		}
		fclose( $in );
		fclose( $out );
		if ( ! $whole ) {
			throw new DownloadException( 'Cannot write the downloaded file. The disk may be full.' );
		}
	}
}
