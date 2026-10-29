<?php
/**
 * A file at a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2: name, size, modification time and the provider's reference
 * (S3: the object key; later a file id or a path).
 */
final class RemoteFile {

	/**
	 * @param string $name  Plain file name (no folder parts).
	 * @param int    $size  Bytes.
	 * @param int    $mtime Unix time.
	 * @param string $ref   Provider reference.
	 */
	public function __construct( public string $name, public int $size, public int $mtime, public string $ref ) {
	}

	/**
	 * @return array{name: string, size: int, mtime: int, ref: string}
	 */
	public function toArray(): array {
		return [
			'name'  => $this->name,
			'size'  => $this->size,
			'mtime' => $this->mtime,
			'ref'   => $this->ref,
		];
	}

	/**
	 * @param array<string, mixed> $a Stored file.
	 */
	public static function fromArray( array $a ): ?self {
		$name = $a['name'] ?? null;
		$ref  = $a['ref'] ?? null;
		if ( ! is_string( $name ) || '' === $name || 1 === preg_match( '#[\x00-\x1f\x7f/\\\\]#', $name ) || ! is_string( $ref ) || '' === $ref ) {
			return null;
		}
		if ( ! is_int( $a['size'] ?? null ) || $a['size'] < 0 || ! is_int( $a['mtime'] ?? null ) || $a['mtime'] < 0 ) {
			return null;
		}

		return new self( $name, $a['size'], $a['mtime'], $ref );
	}
}
