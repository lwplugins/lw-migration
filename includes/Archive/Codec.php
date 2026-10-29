<?php
/**
 * Per-chunk compression.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Gzip is a zlib stream (gzcompress level 9); bzip2 is bzcompress level 9.
 */
final class Codec {

	public const NONE  = '';
	public const GZIP  = 'gzip';
	public const BZIP2 = 'bzip2';

	/**
	 * Compressed bytes fed to the bzip2 filter per write.
	 */
	private const BZIP2_SLICE = 32;

	public static function assertSupported( string $type ): void {
		if ( self::NONE === $type ) {
			return;
		}
		if ( self::GZIP === $type ) {
			if ( ! function_exists( 'gzcompress' ) ) {
				throw new ArchiveException( 'The zlib PHP extension is required for gzip-compressed archives.' );
			}
			return;
		}
		if ( self::BZIP2 === $type ) {
			if ( ! function_exists( 'bzcompress' ) ) {
				throw new ArchiveException( 'The bz2 PHP extension is required for bzip2-compressed archives.' );
			}
			return;
		}

		throw new ArchiveException( sprintf( 'Unknown compression type: %s', $type ) );
	}

	public static function compress( string $type, string $data ): string {
		if ( self::NONE === $type ) {
			return $data;
		}
		self::assertSupported( $type );
		$result = self::GZIP === $type ? gzcompress( $data, 9 ) : bzcompress( $data, 9 );
		if ( ! is_string( $result ) ) {
			throw new ArchiveException( sprintf( 'Compression failed (%s).', $type ) );
		}

		return $result;
	}

	/**
	 * Decompresses one chunk, refusing output larger than $maxLength bytes.
	 *
	 * @param string $type      Compression type.
	 * @param string $data      Compressed bytes.
	 * @param int    $maxLength Maximum decompressed size.
	 * @return string
	 * @throws CorruptedEntryException If the data is invalid or inflates beyond the limit.
	 */
	public static function decompress( string $type, string $data, int $maxLength ): string {
		if ( self::NONE === $type ) {
			return $data;
		}
		self::assertSupported( $type );
		if ( self::GZIP === $type ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid or oversized input is reported by the exception below.
			$result = @gzuncompress( $data, $maxLength );
		} else {
			$result = self::bunzip( $data, $maxLength );
		}
		if ( ! is_string( $result ) ) {
			throw new CorruptedEntryException( sprintf( 'Could not decompress archive data (%s).', $type ) );
		}

		return $result;
	}

	/**
	 * Bounded bzip2 decode: the input is fed in small slices through a write
	 * filter into a disk-backed temp stream, so a bomb is caught after at most
	 * one slice of output instead of being inflated in memory.
	 *
	 * @param string $data      Compressed bytes.
	 * @param int    $maxLength Maximum decompressed size.
	 * @return string|false False on invalid data.
	 * @throws CorruptedEntryException If the output exceeds the limit.
	 */
	private static function bunzip( string $data, int $maxLength ) {
		$handle = fopen( 'php://temp/maxmemory:0', 'w+b' );
		if ( false === $handle ) {
			return false;
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is reported by the caller.
			$filter = @stream_filter_append( $handle, 'bzip2.decompress', STREAM_FILTER_WRITE );
			if ( false === $filter ) {
				return false;
			}
			$length = strlen( $data );
			for ( $offset = 0; $offset < $length; $offset += self::BZIP2_SLICE ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is reported by the caller.
				if ( false === @fwrite( $handle, substr( $data, $offset, self::BZIP2_SLICE ) ) ) {
					return false;
				}
				self::assertWithin( $handle, $maxLength );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- removing the filter flushes it; invalid input is reported by the caller.
			if ( false === @stream_filter_remove( $filter ) ) {
				return false;
			}
			self::assertWithin( $handle, $maxLength );
			rewind( $handle );

			return stream_get_contents( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * @param resource $handle    Output stream.
	 * @param int      $maxLength Maximum decompressed size.
	 * @return void
	 * @throws CorruptedEntryException If the output exceeds the limit.
	 */
	private static function assertWithin( $handle, int $maxLength ): void {
		$stat = fstat( $handle );
		if ( false === $stat || $stat['size'] > $maxLength ) {
			throw new CorruptedEntryException( 'Decompressed data exceeds the allowed size.' );
		}
	}
}
