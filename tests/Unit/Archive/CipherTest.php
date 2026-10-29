<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Package\PackageConfig;
use PHPUnit\Framework\TestCase;

final class CipherTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress';

	public function test_round_trip_and_layout(): void {
		$cipher = new Cipher( 'secret' );
		$data   = random_bytes( 512000 );
		$enc    = $cipher->encrypt( $data );

		// IV + PKCS7-padded ciphertext: 512000 -> 512016, plus 16-byte IV.
		$this->assertSame( 512000 + 32, strlen( $enc ) );
		$this->assertSame( $data, $cipher->decrypt( $enc ) );
	}

	public function test_key_is_reference_compatible(): void {
		$cipher = new Cipher( 'secret' );
		$enc    = $cipher->encrypt( 'payload' );
		$iv     = substr( $enc, 0, 16 );
		$key    = str_pad( substr( sha1( 'secret', true ), 0, 16 ), 32, "\0" );

		$this->assertSame( 'payload', openssl_decrypt( substr( $enc, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ) );
	}

	public function test_verifies_reference_signature_from_fixture(): void {
		$manifest = json_decode( (string) file_get_contents( self::FIXTURES . '/manifest.json' ), true );
		$raw      = (string) file_get_contents( self::FIXTURES . '/encrypted.wpress' );
		// package.json is the first entry and is stored plain: header (4377) + data.
		$size    = (int) rtrim( substr( $raw, 255, 14 ), "\0" );
		$package = PackageConfig::fromJson( substr( $raw, 4377, $size ) );

		$this->assertTrue( ( new Cipher( $manifest['password'] ) )->verifySignature( $package->encryptedSignature() ) );
		$this->assertFalse( ( new Cipher( 'wrong' ) )->verifySignature( $package->encryptedSignature() ) );
	}

	public function test_own_signature_verifies(): void {
		$cipher = new Cipher( 'pw' );
		$this->assertTrue( $cipher->verifySignature( $cipher->signature() ) );
		$this->assertFalse( $cipher->verifySignature( 'not base64 !!' ) );
	}

	/**
	 * Fixed IV (16 x 0x01) . AES-256-CBC of WRONG_PASSWORD_PLAIN under the key derived from 'right'.
	 * A random payload/IV decrypts with valid padding under a wrong key ~0.4% of the time; this
	 * pair was checked to fail padding under 'wrong', so the test is deterministic.
	 */
	private const WRONG_PASSWORD_FIXTURE = '010101010101010101010101010101019899251405e54b0d6f8503bc9e322471c24b080147b6da98b3b00d19fe273667c39294c602b28809e54062c353bb98ce81d89d526e61444bb82a0f60dc444b9dfeeb84566787e3dd3a518fb15f38725a';

	private const WRONG_PASSWORD_PLAIN = 'LW Migration wrong-password fixture. LW Migration wrong-password fixture. ';

	public function test_wrong_password_fixture_is_valid_for_the_right_password(): void {
		$this->assertSame( self::WRONG_PASSWORD_PLAIN, ( new Cipher( 'right' ) )->decrypt( (string) hex2bin( self::WRONG_PASSWORD_FIXTURE ) ) );
	}

	public function test_wrong_password_throws_without_warnings(): void {
		$this->expectException( CorruptedEntryException::class );
		( new Cipher( 'wrong' ) )->decrypt( (string) hex2bin( self::WRONG_PASSWORD_FIXTURE ) );
	}

	public function test_malformed_ciphertext_throws(): void {
		$this->expectException( CorruptedEntryException::class );
		( new Cipher( 'x' ) )->decrypt( 'short' );
	}
}
