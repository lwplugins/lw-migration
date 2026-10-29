<?php
/**
 * A part of a local file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;

defined( 'ABSPATH' ) || exit;

/**
 * The bytes of a request body that cURL reads from the backup itself
 * (plan D22): $length bytes from $offset of $path. Like
 * CloudUploadStep::read(), a file with fewer bytes than the slice needs
 * stops the job before anything is sent, so a server never gets a short body.
 */
final class FileSlice {

	/**
	 * @param string $path   Local file.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes.
	 */
	public function __construct( public string $path, public int $offset, public int $length ) {
	}

	/**
	 * @return resource The file, positioned at the offset.
	 * @throws CloudJobException When it cannot be read (the backup is gone) or is shorter than the slice (it changed).
	 */
	public function open() {
		$in = is_file( $this->path ) ? fopen( $this->path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming our own backup.
		if ( false === $in ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'The backup %s cannot be read.', 'lw-migration' ), basename( $this->path ) ) );
		}
		$size = (int) ( fstat( $in )['size'] ?? 0 );
		if ( $this->offset < 0 || $this->length < 0 || $size - $this->offset < $this->length || 0 !== fseek( $in, $this->offset ) ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen.
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'The backup %s changed while it was uploaded.', 'lw-migration' ), basename( $this->path ) ) );
		}

		return $in;
	}
}
