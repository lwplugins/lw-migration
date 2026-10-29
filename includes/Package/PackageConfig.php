<?php
/**
 * Package.json model.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Package;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;

defined( 'ABSPATH' ) || exit;

/**
 * The first entry of every archive. Unknown keys are kept untouched.
 */
final class PackageConfig {

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Decoded JSON object.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	public static function fromJson( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( [] !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			throw new InvalidArchiveException( 'package.json is not a valid JSON object.' );
		}

		return new self( $data );
	}

	/**
	 * @param array<string, mixed> $data Config data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	public function toJson(): string {
		$json = json_encode( $this->data );
		if ( false === $json ) {
			throw new ArchiveException( 'Cannot encode package.json.' );
		}

		return $json;
	}

	/**
	 * Dot-path lookup, e.g. get( 'Compression.Type' ).
	 *
	 * @param string $path    Dot-separated keys.
	 * @param mixed  $default Returned when a key is missing.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$node = $this->data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return $default;
			}
			$node = $node[ $key ];
		}

		return $node;
	}

	public function compression(): string {
		$enabled = $this->get( 'Compression.Enabled' );
		$type    = $this->get( 'Compression.Type' );
		if ( empty( $enabled ) || empty( $type ) ) {
			return '';
		}
		if ( 'gzip' !== $type && 'bzip2' !== $type ) {
			throw new InvalidArchiveException( sprintf( 'Unsupported compression type in package.json: %s', is_scalar( $type ) ? (string) $type : gettype( $type ) ) );
		}

		return $type;
	}

	/**
	 * The Encrypted flag decides; a missing signature only means the password cannot be checked up front.
	 */
	public function isEncrypted(): bool {
		return ! empty( $this->get( 'Encrypted' ) );
	}

	public function encryptedSignature(): string {
		$signature = $this->get( 'EncryptedSignature', '' );

		return is_string( $signature ) ? $signature : '';
	}
}
