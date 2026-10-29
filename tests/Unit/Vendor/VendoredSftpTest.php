<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Vendor;

use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\AES;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\EC;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

/**
 * Plan D30. phpseclib loads ciphers, key formats, curves and math engines
 * by name at run time, so a missing file shows only when that path runs:
 * every file must load, no unprefixed name may be left, and the crypto an
 * SSH login uses must work (AES-GCM needs Math/BinaryField — a subset
 * without it failed at connect). The real login is proven by Task 11.
 */
final class VendoredSftpTest extends TestCase {

	private static function root(): string {
		return (string) realpath( dirname( __DIR__, 3 ) . '/includes/Vendor' );
	}

	/**
	 * @return array<int, \SplFileInfo>
	 */
	private static function phpFiles(): array {
		$files = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	public function test_every_vendored_file_loads_through_the_plugin_namespace(): void {
		$failed = [];
		foreach ( self::phpFiles() as $file ) {
			$class = 'LightweightPlugins\\Migration\\Vendor\\' . str_replace( '/', '\\', substr( $file->getPathname(), strlen( self::root() ) + 1, -4 ) );
			if ( ! class_exists( $class ) && ! interface_exists( $class ) && ! trait_exists( $class ) ) {
				$failed[] = $class;
			}
		}

		$this->assertSame( [], $failed );
		$this->assertCount( 272, self::phpFiles(), 'The subset of bin/vendor-sftp.php; change this number together with the script.' );
	}

	public function test_no_unprefixed_name_is_left_in_code_or_strings(): void {
		$left = [];
		foreach ( self::phpFiles() as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ], true ) ) {
					continue;
				}
				$rest = (string) preg_replace( '/Vendor\\\\{1,2}(phpseclib3|ParagonIE)/', '', $token[1] );
				if ( 1 === preg_match( '/(phpseclib3|ParagonIE)\\\\/', $rest ) ) {
					$left[] = $file->getFilename() . ': ' . $token[1];
				}
			}
		}

		$this->assertSame( [], $left );
	}

	public function test_the_licenses_and_the_source_note_ship_with_it(): void {
		$this->assertFileExists( self::root() . '/phpseclib3/LICENSE' );
		$this->assertFileExists( self::root() . '/ParagonIE/ConstantTime/LICENSE.txt' );
		$source = (string) file_get_contents( self::root() . '/SOURCE.txt' );
		$this->assertStringContainsString( 'phpseclib/phpseclib 3.0.57', $source );
		$this->assertStringContainsString( 'constant_time_encoding 3.1.3', $source );
	}

	public function test_the_crypto_an_ssh_login_uses_works(): void {
		$aes = new AES( 'gcm' );
		$aes->setKey( str_repeat( 'k', 32 ) );
		$aes->setNonce( str_repeat( 'n', 12 ) );
		$sealed = $aes->encrypt( 'hello' );
		$aes->setTag( $aes->getTag() );
		$this->assertSame( 'hello', $aes->decrypt( $sealed ), 'AES-GCM (Math\BinaryField).' );

		$ed = EC::loadPrivateKey( EC::createKey( 'Ed25519' )->withPassword( 'pw' )->toString( 'OpenSSH' ), 'pw' );
		$this->assertInstanceOf( PrivateKey::class, $ed );
		$this->assertTrue( $ed->getPublicKey()->verify( 'm', $ed->sign( 'm' ) ), 'Ed25519 with a passphrase.' );

		$rsa = RSA::createKey( 1024 )->withPassword( 'pw' )->toString( 'PKCS1' );
		$this->assertStringContainsString( 'ENCRYPTED', $rsa );
		$this->assertInstanceOf( PrivateKey::class, RSA::loadPrivateKey( $rsa, 'pw' ), 'RSA in PEM with a passphrase.' );

		$this->assertNotSame( '', EC::createKey( 'nistp256' )->toString( 'OpenSSH' ), 'ECDSA P-256.' );
		$this->assertNotSame( '', EC::createKey( 'Curve25519' )->getPublicKey()->toString( 'MontgomeryPublic' ), 'curve25519-sha256 key exchange.' );
	}
}
