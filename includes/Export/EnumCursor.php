<?php
/**
 * Progress of FileEnumerator::run().
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Root index, folders still to read (relative), list and visited-file lengths, totals.
 * Folder names may hold any bytes; toArray() base64-encodes them for job.json.
 */
final class EnumCursor {

	/**
	 * @param int                $root      Current root index.
	 * @param bool               $started   The current root's walk began.
	 * @param array<int, string> $stack     Relative folders still to read.
	 * @param int                $listBytes File list length after the last finished folder.
	 * @param int                $files     Files listed.
	 * @param int                $bytes     Their total size.
	 * @param int                $skipped   Files and folders skipped.
	 * @param int                $visitedBytes Visited-folders file length after the last finished folder.
	 */
	public function __construct(
		public int $root = 0,
		public bool $started = false,
		public array $stack = [],
		public int $listBytes = 0,
		public int $files = 0,
		public int $bytes = 0,
		public int $skipped = 0,
		public int $visitedBytes = 0
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'root'         => $this->root,
			'started'      => $this->started,
			'stack64'      => array_map( 'base64_encode', $this->stack ),
			'listBytes'    => $this->listBytes,
			'files'        => $this->files,
			'bytes'        => $this->bytes,
			'skipped'      => $this->skipped,
			'visitedBytes' => $this->visitedBytes,
		];
	}

	/**
	 * @param array<string, mixed> $a Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		$stack = [];
		foreach ( (array) ( $a['stack64'] ?? [] ) as $encoded ) {
			$stack[] = (string) base64_decode( (string) $encoded, true );
		}

		return new self( (int) ( $a['root'] ?? 0 ), (bool) ( $a['started'] ?? false ), $stack, (int) ( $a['listBytes'] ?? 0 ), (int) ( $a['files'] ?? 0 ), (int) ( $a['bytes'] ?? 0 ), (int) ( $a['skipped'] ?? 0 ), (int) ( $a['visitedBytes'] ?? 0 ) );
	}
}
