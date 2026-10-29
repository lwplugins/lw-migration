<?php
/**
 * Progress of an archive-wide checksum.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Bytes hashed so far, running CRC and the total length to hash.
 */
final class CrcCursor {

	public function __construct(
		public int $offset = 0,
		public string $crc = Crc32::EMPTY,
		public ?int $size = null
	) {
	}

	/**
	 * @return array{offset: int, crc: string, size: ?int}
	 */
	public function toArray(): array {
		return [
			'offset' => $this->offset,
			'crc'    => $this->crc,
			'size'   => $this->size,
		];
	}

	/**
	 * @param array<string, mixed> $data Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			(int) ( $data['offset'] ?? 0 ),
			(string) ( $data['crc'] ?? Crc32::EMPTY ),
			isset( $data['size'] ) ? (int) $data['size'] : null
		);
	}
}
