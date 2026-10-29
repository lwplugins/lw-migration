<?php
/**
 * Append-only work files that resume at a saved length.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Bytes past the saved length come from a request that died after the last
 * save and are cut off; a file shorter than the saved length means the job's
 * state no longer matches its files.
 */
final class ListFile {

	/**
	 * @param string $path   File.
	 * @param int    $length Valid length.
	 * @return resource Positioned at $length.
	 * @throws ExportException When it cannot be opened or is shorter than $length.
	 */
	public static function open( string $path, int $length ) {
		Files::privateFile( $path );
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new ExportException( 'Cannot open the file list.' );
		}
		$stat = fstat( $handle );
		if ( false === $stat || $stat['size'] < $length ) {
			fclose( $handle );
			throw new ExportException( 'The file list is shorter than the saved progress. Start the backup again.' );
		}
		if ( ! ftruncate( $handle, $length ) || 0 !== fseek( $handle, $length ) ) {
			fclose( $handle );
			throw new ExportException( 'Cannot open the file list.' );
		}

		return $handle;
	}

	/**
	 * @param resource $handle File.
	 * @param string   $data   Bytes to append.
	 * @return void
	 * @throws ExportException When the write is short.
	 */
	public static function write( $handle, string $data ): void {
		if ( strlen( $data ) !== fwrite( $handle, $data ) ) {
			throw new ExportException( 'Cannot write the file list. The disk may be full.' );
		}
	}

	/**
	 * @param resource $handle File.
	 * @return int Length after flushing.
	 * @throws ExportException When the flush fails.
	 */
	public static function flush( $handle ): int {
		if ( ! fflush( $handle ) ) {
			throw new ExportException( 'Cannot write the file list. The disk may be full.' );
		}

		return (int) ftell( $handle );
	}
}
