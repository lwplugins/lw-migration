<?php
/**
 * One archive entry (decoded header).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Header fields of an entry plus its position in the archive.
 */
final class Entry {

	/**
	 * @param string $name         Full archive path, `/`-separated.
	 * @param int    $size         Stored (possibly compressed/encrypted) byte count.
	 * @param int    $mtime        Unix modification time.
	 * @param string $crc          '' or crc32b of the original content.
	 * @param int    $headerOffset Byte offset of the header block.
	 */
	public function __construct(
		public string $name,
		public int $size,
		public int $mtime,
		public string $crc,
		public int $headerOffset
	) {
	}

	public function dataOffset(): int {
		return $this->headerOffset + Format::HEADER_SIZE;
	}

	public function isConfig(): bool {
		return Format::isConfigEntry( $this->name );
	}
}
