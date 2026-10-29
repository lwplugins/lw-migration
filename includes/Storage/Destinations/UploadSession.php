<?php
/**
 * An unfinished upload, kept in the job between requests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2: type, the provider's upload id or address ($remote), the bytes
 * the destination has ($offset), the part size ($chunk) and provider extras
 * (S3: the object key and the ETag of every part). Immutable: with() returns
 * the session after a part. fromArray() reads a stored session strictly and
 * answers null for anything damaged, so a resumed job starts the upload over
 * instead of sending to a wrong offset.
 */
final class UploadSession {

	/**
	 * @param string               $type   Destination type key.
	 * @param string               $name   File name at the destination.
	 * @param int                  $size   Total bytes.
	 * @param int                  $offset Bytes the destination has.
	 * @param int                  $chunk  Part size (every part but the last has exactly this many bytes on S3).
	 * @param string               $remote Provider's upload id or session address.
	 * @param array<string, mixed> $extra  Provider data (JSON-safe).
	 */
	public function __construct(
		public string $type,
		public string $name,
		public int $size,
		public int $offset,
		public int $chunk,
		public string $remote,
		public array $extra = []
	) {
	}

	/**
	 * @param int                  $offset New offset.
	 * @param array<string, mixed> $extra  New provider data.
	 */
	public function with( int $offset, array $extra ): self {
		return new self( $this->type, $this->name, $this->size, $offset, $this->chunk, $this->remote, $extra );
	}

	public function isComplete(): bool {
		return $this->offset >= $this->size;
	}

	/** Bytes of the next part: the part size, or what is left. */
	public function nextLength(): int {
		return max( 0, min( $this->chunk, $this->size - $this->offset ) );
	}

	/**
	 * @return array{type: string, name: string, size: int, offset: int, chunk: int, remote: string, extra: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'type'   => $this->type,
			'name'   => $this->name,
			'size'   => $this->size,
			'offset' => $this->offset,
			'chunk'  => $this->chunk,
			'remote' => $this->remote,
			'extra'  => $this->extra,
		];
	}

	/**
	 * @param array<string, mixed> $a Stored session.
	 */
	public static function fromArray( array $a ): ?self {
		foreach ( [ 'type', 'name', 'remote' ] as $key ) {
			if ( ! is_string( $a[ $key ] ?? null ) || '' === $a[ $key ] ) {
				return null;
			}
		}
		foreach ( [ 'size', 'offset', 'chunk' ] as $key ) {
			if ( ! is_int( $a[ $key ] ?? null ) ) {
				return null;
			}
		}
		$extra = $a['extra'] ?? [];
		if ( ! is_array( $extra ) || $a['size'] < 1 || $a['chunk'] < 1 || $a['offset'] < 0 || $a['offset'] > $a['size'] || 1 === preg_match( '#[\x00-\x1f\x7f/\\\\]#', $a['name'] ) ) {
			return null;
		}

		return new self( $a['type'], $a['name'], $a['size'], $a['offset'], $a['chunk'], $a['remote'], $extra );
	}
}
