<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpKey;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\EC;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\RSA;

/**
 * Review Focus 4. Keys are made here with the vendored library — no key
 * file is committed; the RSA key is made once per class and encrypted
 * OpenSSH keys use one bcrypt round (P-M18: pure PHP is slow; the loading
 * path is the same at any round count).
 */
final class SftpKeyTest extends MonkeyTestCase {

	private const PASSPHRASE = 'horse-battery-7';

	private static ?RSA\PrivateKey $rsa = null;

	private static function rsa(): RSA\PrivateKey {
		self::$rsa ??= RSA::createKey( 1024 ); // Pure PHP: 1024 bits for speed; the login path is the same at any size.

		return self::$rsa;
	}

	/**
	 * @return array<string, array{0: string, 1: string}> Key text, passphrase.
	 */
	public static function keys(): array {
		return [
			'Ed25519, OpenSSH, passphrase' => [ EC::createKey( 'Ed25519' )->withPassword( self::PASSPHRASE )->toString( 'OpenSSH', [ 'rounds' => 1 ] ), self::PASSPHRASE ],
			'ECDSA P-256, OpenSSH'         => [ EC::createKey( 'nistp256' )->toString( 'OpenSSH' ), '' ],
			'ECDSA P-384, PEM, passphrase' => [ EC::createKey( 'nistp384' )->withPassword( self::PASSPHRASE )->toString( 'PKCS8' ), self::PASSPHRASE ],
			'RSA, PEM, passphrase'         => [ self::rsa()->withPassword( self::PASSPHRASE )->toString( 'PKCS1' ), self::PASSPHRASE ],
			'RSA, OpenSSH'                 => [ self::rsa()->toString( 'OpenSSH' ), '' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'keys' )]
	public function test_loads_the_supported_keys_with_their_passphrase( string $text, string $passphrase ): void {
		$this->assertInstanceOf( PrivateKey::class, SftpKey::load( $text, $passphrase ) );
	}

	public function test_a_wrong_or_missing_passphrase_is_a_login_problem_and_never_echoes_the_key(): void {
		foreach ( [ EC::createKey( 'Ed25519' )->withPassword( self::PASSPHRASE )->toString( 'OpenSSH', [ 'rounds' => 1 ] ), self::rsa()->withPassword( self::PASSPHRASE )->toString( 'PKCS1' ) ] as $text ) {
			foreach ( [ 'wrong', '' ] as $passphrase ) {
				try {
					SftpKey::load( $text, $passphrase );
					$this->fail( 'Not opened.' );
				} catch ( PermanentFailure $e ) {
					$this->assertSame( PermanentFailure::AUTH, $e->reason );
					$this->assertStringContainsString( 'passphrase', $e->getMessage() );
					$this->assertStringNotContainsString( 'PRIVATE KEY', $e->getMessage() );
					$this->assertStringNotContainsString( self::PASSPHRASE, $e->getMessage() );
				}
			}
		}
	}

	public function test_a_public_key_or_garbage_is_not_a_usable_key(): void {
		foreach ( [ EC::createKey( 'Ed25519' )->getPublicKey()->toString( 'OpenSSH' ), 'not a key', '' ] as $text ) {
			try {
				SftpKey::load( $text, '' );
				$this->fail( 'Refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
		}
	}

	/**
	 * Task 8 carry: the curve classes of Ed448 (and secp256k1) are not in the
	 * bundled subset, so phpseclib fails with an Error, not an exception; it
	 * is still "not a usable key", in our words.
	 */
	public function test_a_key_of_a_curve_left_out_of_the_bundle_is_not_a_usable_key(): void {
		$der  = "\x30\x47\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x71\x04\x3b\x04\x39" . random_bytes( 57 ); // PKCS#8, OID 1.3.101.113 (Ed448).
		$text = "-----BEGIN PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PRIVATE KEY-----\n";

		try {
			SftpKey::load( $text, '' );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'Ed448', $e->getMessage() );
		}
	}

	public function test_tells_an_encrypted_key_from_a_plain_one(): void {
		$this->assertTrue( SftpKey::encrypted( EC::createKey( 'Ed25519' )->withPassword( 'x' )->toString( 'OpenSSH', [ 'rounds' => 1 ] ) ) );
		$this->assertFalse( SftpKey::encrypted( EC::createKey( 'Ed25519' )->toString( 'OpenSSH' ) ) );
		$this->assertTrue( SftpKey::encrypted( self::rsa()->withPassword( 'x' )->toString( 'PKCS1' ) ) );
		$this->assertTrue( SftpKey::encrypted( EC::createKey( 'nistp256' )->withPassword( 'x' )->toString( 'PKCS8' ) ) );
		$this->assertFalse( SftpKey::encrypted( 'nothing' ) );
	}
}
