<?php
/**
 * Authenticated encryption of small secrets.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * XSalsa20-Poly1305 (sodium secretbox) with a fresh random nonce per value.
 * Sealed values carry a version prefix; anything that does not open (another
 * key, tampering, a value written before sealing existed) reads as null, so
 * callers treat it as missing. The key comes from the caller.
 */
final class SecretBox {

	public const KEY_BYTES = 32;

	private const NONCE_BYTES = 24;

	private const MAC_BYTES = 16;

	private const PREFIX = 'lwm1:';

	/**
	 * @param string $key 32 raw bytes.
	 * @throws \InvalidArgumentException On a key of another length.
	 */
	public function __construct( private string $key ) {
		if ( self::KEY_BYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'A secret box key has 32 bytes.' );
		}
	}

	/** Whether the sodium functions exist (PHP's extension or WordPress' bundled compat library). */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	public function seal( #[\SensitiveParameter] string $plain ): string {
		$nonce = random_bytes( self::NONCE_BYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) );
	}

	public function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < self::NONCE_BYTES + self::MAC_BYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_BYTES ), substr( $raw, 0, self::NONCE_BYTES ), $this->key );
		} catch ( \SodiumException $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}
}
