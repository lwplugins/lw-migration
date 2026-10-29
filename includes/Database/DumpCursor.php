<?php
/**
 * Progress of Dumper::run().
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * File length written so far, current object index, rows of the current table.
 */
final class DumpCursor {

	/**
	 * @param int                     $fileOffset Bytes written.
	 * @param int                     $index      Index into tables + views.
	 * @param bool                    $started    DROP/CREATE of the current table written.
	 * @param int                     $rows       Rows of the current table written.
	 * @param array<int, string>|null $lastKey    Primary key of the last written row.
	 */
	public function __construct(
		public int $fileOffset = 0,
		public int $index = 0,
		public bool $started = false,
		public int $rows = 0,
		public ?array $lastKey = null
	) {
	}

	public function nextTable(): void {
		++$this->index;
		$this->started = false;
		$this->rows    = 0;
		$this->lastKey = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'fileOffset' => $this->fileOffset,
			'index'      => $this->index,
			'started'    => $this->started,
			'rows'       => $this->rows,
			// Key values can be binary; base64 keeps the saved job valid UTF-8 JSON.
			'lastKey64'  => null === $this->lastKey ? null : array_map( 'base64_encode', $this->lastKey ),
		];
	}

	/**
	 * @param array<string, mixed> $a Saved cursor.
	 * @return self
	 */
	public static function fromArray( array $a ): self {
		return new self(
			(int) ( $a['fileOffset'] ?? 0 ),
			(int) ( $a['index'] ?? 0 ),
			(bool) ( $a['started'] ?? false ),
			(int) ( $a['rows'] ?? 0 ),
			self::keyFrom( $a )
		);
	}

	/**
	 * @param array<string, mixed> $a Saved cursor.
	 * @return array<int, string>|null
	 */
	private static function keyFrom( array $a ): ?array {
		if ( isset( $a['lastKey64'] ) && is_array( $a['lastKey64'] ) ) {
			return array_values( array_map( [ self::class, 'decode' ], $a['lastKey64'] ) );
		}
		if ( isset( $a['lastKey'] ) && is_array( $a['lastKey'] ) ) {
			return array_values( array_map( 'strval', $a['lastKey'] ) );
		}

		return null;
	}

	/**
	 * @param mixed $value Base64 text.
	 * @return string
	 * @throws DatabaseException On invalid base64.
	 */
	private static function decode( $value ): string {
		$decoded = base64_decode( (string) $value, true );
		if ( false === $decoded ) {
			throw new DatabaseException( 'The saved dump progress is corrupted (invalid key encoding).' );
		}

		return $decoded;
	}
}
