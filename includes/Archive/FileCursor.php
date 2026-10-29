<?php
/**
 * Progress of Writer::addFile().
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Where the entry header is, how much source was read, how much was stored,
 * running CRC, and the source's size and mtime at the first open.
 */
final class FileCursor {

	public function __construct(
		public ?int $headerOffset = null,
		public int $sourceOffset = 0,
		public int $written = 0,
		public string $crc = Crc32::EMPTY,
		public ?int $sourceSize = null,
		public ?int $sourceMtime = null
	) {
	}

	/**
	 * @return array{headerOffset: ?int, sourceOffset: int, written: int, crc: string, sourceSize: ?int, sourceMtime: ?int}
	 */
	public function toArray(): array {
		return [
			'headerOffset' => $this->headerOffset,
			'sourceOffset' => $this->sourceOffset,
			'written'      => $this->written,
			'crc'          => $this->crc,
			'sourceSize'   => $this->sourceSize,
			'sourceMtime'  => $this->sourceMtime,
		];
	}

	/**
	 * @param array<string, mixed> $data Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			isset( $data['headerOffset'] ) ? (int) $data['headerOffset'] : null,
			(int) ( $data['sourceOffset'] ?? 0 ),
			(int) ( $data['written'] ?? 0 ),
			(string) ( $data['crc'] ?? Crc32::EMPTY ),
			isset( $data['sourceSize'] ) ? (int) $data['sourceSize'] : null,
			isset( $data['sourceMtime'] ) ? (int) $data['sourceMtime'] : null
		);
	}
}
