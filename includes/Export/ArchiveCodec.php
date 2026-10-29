<?php
/**
 * Chunk codec of the archive a job writes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Compression from the job options, encryption from the job secret.
 */
final class ArchiveCodec {

	/**
	 * @param Job $job Export job.
	 * @return string
	 * @throws ExportException When the secret is gone (e.g. an expired transient).
	 */
	public static function password( Job $job ): string {
		$password = $job->secrets['password'] ?? null;
		if ( ! is_string( $password ) || '' === $password ) {
			throw new ExportException( __( 'The backup password is no longer available. Start the backup again.', 'lw-migration' ) );
		}

		return $password;
	}

	/**
	 * @param Job $job Export job.
	 * @return ChunkCodec
	 * @throws ExportException When the backup is encrypted and the password is gone.
	 */
	public static function forJob( Job $job ): ChunkCodec {
		$options = ExportOptions::fromArray( $job->options );

		return new ChunkCodec( $options->compression, $options->encrypt ? new Cipher( self::password( $job ) ) : null );
	}
}
