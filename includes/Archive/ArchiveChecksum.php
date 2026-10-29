<?php
/**
 * Resumable CRC over the head of an archive file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by Writer::finalize() and Reader::verifyChecksum().
 */
final class ArchiveChecksum {

	private const BLOCK = 1048576;

	/**
	 * @param resource  $handle   Readable archive stream.
	 * @param CrcCursor $cursor   Progress; `size` must be set.
	 * @param Deadline  $deadline Time budget.
	 * @return bool True when `[0, size)` is fully hashed.
	 * @throws ArchiveException If the cursor has no size.
	 * @throws InvalidArchiveException If the archive is shorter than the size.
	 */
	public static function advance( $handle, CrcCursor $cursor, Deadline $deadline ): bool {
		if ( null === $cursor->size ) {
			throw new ArchiveException( 'Checksum size is not set.' );
		}

		$crc = new IncrementalCrc( $cursor->crc );
		if ( 0 !== fseek( $handle, $cursor->offset ) ) {
			throw new ArchiveException( 'Cannot seek in the archive.' );
		}

		while ( $cursor->offset < $cursor->size ) {
			$data = fread( $handle, min( self::BLOCK, $cursor->size - $cursor->offset ) );
			if ( false === $data || '' === $data ) {
				throw new InvalidArchiveException( 'Unexpected end of archive while computing its checksum.' );
			}
			$crc->update( $data );
			$cursor->offset += strlen( $data );

			if ( $cursor->offset < $cursor->size && $deadline->reached() ) {
				$cursor->crc = $crc->value();
				return false;
			}
		}

		$cursor->crc = $crc->value();

		return true;
	}
}
