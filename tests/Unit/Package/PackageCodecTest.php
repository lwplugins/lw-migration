<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Package;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Package\WrongPasswordException;
use PHPUnit\Framework\TestCase;

final class PackageCodecTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/wpress/';

	public function test_reads_encrypted_compressed_fixture_with_the_right_password(): void {
		$package = PackageCodec::read( self::FIXTURES . 'encrypted-gzip.wpress' );
		$reader  = new Reader( self::FIXTURES . 'encrypted-gzip.wpress', PackageCodec::forPackage( $package, 'lw-fixture-pass' ) );
		$this->assertSame( "Hello .wpress\n", $reader->readContents( $reader->find( 'hello.txt' ) ) );
		$reader->close();
	}

	public function test_wrong_and_missing_password(): void {
		$package = PackageCodec::read( self::FIXTURES . 'encrypted.wpress' );
		try {
			PackageCodec::forPackage( $package, 'nope' );
			$this->fail( 'Wrong password accepted.' );
		} catch ( WrongPasswordException $e ) {
			$this->assertStringContainsString( 'password', $e->getMessage() );
		}
		$this->expectException( ArchiveException::class );
		PackageCodec::forPackage( $package, null );
	}

	public function test_plain_fixture_needs_no_password(): void {
		$path   = self::FIXTURES . 'plain.wpress';
		$reader = new Reader( $path, PackageCodec::forPackage( PackageCodec::read( $path ), null ) );
		$this->assertSame( "Hello .wpress\n", $reader->readContents( $reader->find( 'hello.txt' ) ) );
		$reader->close();
	}

	public function test_encrypted_without_a_signature_needs_a_password_but_cannot_check_it(): void {
		$package = PackageConfig::fromArray( [ 'Encrypted' => true ] );
		$this->assertInstanceOf( \LightweightPlugins\Migration\Archive\ChunkCodec::class, PackageCodec::forPackage( $package, 'anything' ) );
		$this->expectException( ArchiveException::class );
		PackageCodec::forPackage( $package, '' );
	}
}
