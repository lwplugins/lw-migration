<?php
/**
 * Progress of Reader::extractTo().
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Stored bytes consumed, decoded bytes written, running CRC of the output, the
 * temporary file the entry is written to until it is complete, and the size of
 * the file it replaces (measured when the temporary file was created; its bytes
 * are freed by the rename, after which Reader refunds them to the WriteBudget). Without a temporary file (a fresh or an older cursor) the entry starts over.
 */
final class ExtractCursor {

	public function __construct(
		public int $consumed = 0,
		public int $written = 0,
		public string $crc = Crc32::EMPTY,
		public ?string $tempPath = null,
		public int $replaced = 0
	) {
	}

	/**
	 * @return array{consumed: int, written: int, crc: string, tempPath: ?string, replaced: int}
	 */
	public function toArray(): array {
		return [
			'consumed' => $this->consumed,
			'written'  => $this->written,
			'crc'      => $this->crc,
			'tempPath' => $this->tempPath,
			'replaced' => $this->replaced,
		];
	}

	/**
	 * @param array<string, mixed> $data Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			(int) ( $data['consumed'] ?? 0 ),
			(int) ( $data['written'] ?? 0 ),
			(string) ( $data['crc'] ?? Crc32::EMPTY ),
			isset( $data['tempPath'] ) ? (string) $data['tempPath'] : null,
			max( 0, (int) ( $data['replaced'] ?? 0 ) )
		);
	}
}
