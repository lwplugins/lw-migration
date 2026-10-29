<?php
/**
 * Chunk-level encoding of entry data.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Writer side: compress, then encrypt, then (only when compressed) prefix a
 * 4-byte big-endian length. Reader side mirrors it; without compression an
 * encrypted chunk is CHUNK_SIZE + 2 * IV_LENGTH bytes (the last one shorter).
 */
final class ChunkCodec {

	/**
	 * Compression codec.
	 *
	 * @var string
	 */
	private string $compression;

	/**
	 * Encryption cipher, if any.
	 *
	 * @var Cipher|null
	 */
	private ?Cipher $cipher;

	public function __construct( string $compression = Codec::NONE, ?Cipher $cipher = null ) {
		Codec::assertSupported( $compression );
		$this->compression = $compression;
		$this->cipher      = $cipher;
	}

	public static function plain(): self {
		return new self();
	}

	public function encode( string $raw ): string {
		$data = Codec::compress( $this->compression, $raw );
		if ( null !== $this->cipher ) {
			$data = $this->cipher->encrypt( $data );
		}
		if ( Codec::NONE !== $this->compression ) {
			$data = pack( 'N', strlen( $data ) ) . $data;
		}

		return $data;
	}

	/**
	 * Reads and decodes one stored chunk.
	 *
	 * @param resource $handle    Stream positioned at the chunk.
	 * @param int      $remaining Stored bytes left in the entry.
	 * @return array{0: string, 1: int} Decoded data and stored bytes consumed.
	 * @throws CorruptedEntryException If the entry data is corrupted or truncated.
	 */
	public function decodeNext( $handle, int $remaining ): array {
		if ( $remaining <= 0 ) {
			throw new CorruptedEntryException( 'No entry data left to read.' );
		}

		if ( Codec::NONE !== $this->compression ) {
			if ( $remaining < 4 ) {
				throw new CorruptedEntryException( 'Truncated chunk header.' );
			}
			$length = (int) unpack( 'N', self::read( $handle, 4 ) )[1];
			if ( $length > $remaining - 4 ) {
				throw new CorruptedEntryException( 'Chunk length exceeds the entry size.' );
			}
			if ( $length > Format::CHUNK_SIZE + 65536 ) {
				throw new CorruptedEntryException( 'Chunk is larger than the format allows.' );
			}
			$data = self::read( $handle, $length );
			if ( null !== $this->cipher ) {
				$data = $this->cipher->decrypt( $data );
			}

			return [ Codec::decompress( $this->compression, $data, Format::CHUNK_SIZE ), 4 + $length ];
		}

		if ( null !== $this->cipher ) {
			$length = $remaining > Format::CHUNK_SIZE
				? min( Format::CHUNK_SIZE + 2 * Format::IV_LENGTH, $remaining )
				: $remaining;

			return [ $this->cipher->decrypt( self::read( $handle, $length ) ), $length ];
		}

		$length = min( Format::CHUNK_SIZE, $remaining );

		return [ self::read( $handle, $length ), $length ];
	}

	/**
	 * @param resource $handle Stream.
	 * @param int      $length Exact byte count.
	 * @return string
	 * @throws CorruptedEntryException If unable to read the exact byte count.
	 */
	private static function read( $handle, int $length ): string {
		$buffer     = '';
		$buffer_len = 0;
		while ( $buffer_len < $length ) {
			$part = fread( $handle, $length - $buffer_len );
			if ( false === $part || '' === $part ) {
				throw new CorruptedEntryException( 'Unexpected end of archive data.' );
			}
			$buffer     .= $part;
			$buffer_len += strlen( $part );
		}

		return $buffer;
	}
}
