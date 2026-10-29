<?php
/**
 * Per-chunk encryption, compatible with existing .wpress archives.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC with PKCS7 padding; output = IV(16) . ciphertext.
 * Key = first 16 bytes of the raw SHA-1 of the password, NUL-padded to 32 bytes —
 * this exact derivation is required to read and write existing archives.
 */
final class Cipher {

	public const SIGNATURE_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	private const METHOD = 'aes-256-cbc';

	/**
	 * Derived 32-byte AES key.
	 *
	 * @var string
	 */
	private string $key;

	public function __construct( string $password ) {
		self::assertSupported();
		$this->key = str_pad( substr( sha1( $password, true ), 0, Format::IV_LENGTH ), 32, "\0" );
	}

	public static function assertSupported(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( self::METHOD, openssl_get_cipher_methods(), true ) ) {
			throw new ArchiveException( 'The OpenSSL PHP extension with AES-256-CBC is required for encrypted archives.' );
		}
	}

	public function encrypt( string $plain ): string {
		$iv     = random_bytes( Format::IV_LENGTH );
		$cipher = openssl_encrypt( $plain, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			throw new ArchiveException( 'Encryption failed.' );
		}

		return $iv . $cipher;
	}

	public function decrypt( string $data ): string {
		$length = strlen( $data );
		if ( $length < 2 * Format::IV_LENGTH || 0 !== ( $length - Format::IV_LENGTH ) % 16 ) {
			throw new CorruptedEntryException( 'Encrypted data is malformed.' );
		}

		$plain = openssl_decrypt( substr( $data, Format::IV_LENGTH ), self::METHOD, $this->key, OPENSSL_RAW_DATA, substr( $data, 0, Format::IV_LENGTH ) );
		if ( false === $plain ) {
			throw new CorruptedEntryException( 'Could not decrypt archive data. The password may be wrong.' );
		}

		return $plain;
	}

	public function signature(): string {
		return base64_encode( $this->encrypt( self::SIGNATURE_TEXT ) );
	}

	public function verifySignature( string $base64 ): bool {
		$raw = base64_decode( $base64, true );
		if ( false === $raw ) {
			return false;
		}
		try {
			return self::SIGNATURE_TEXT === $this->decrypt( $raw );
		} catch ( CorruptedEntryException $e ) {
			return false;
		}
	}
}
