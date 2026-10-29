<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Package;

use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Package\PackageConfig;
use PHPUnit\Framework\TestCase;

final class PackageConfigTest extends TestCase {

	public function test_reads_fields(): void {
		$p = PackageConfig::fromJson( '{"SiteURL":"https://a.test","Compression":{"Enabled":true,"Type":"gzip"},"Encrypted":true,"EncryptedSignature":"c2ln"}' );

		$this->assertSame( 'https://a.test', $p->get( 'SiteURL' ) );
		$this->assertSame( 'gzip', $p->compression() );
		$this->assertTrue( $p->isEncrypted() );
		$this->assertSame( 'c2ln', $p->encryptedSignature() );
		$this->assertSame( 'x', $p->get( 'Missing.Key', 'x' ) );
	}

	public function test_defaults_when_absent(): void {
		$p = PackageConfig::fromJson( '{"SiteURL":"https://a.test"}' );
		$this->assertSame( '', $p->compression() );
		$this->assertFalse( $p->isEncrypted() );
		$this->assertSame( '', $p->encryptedSignature() );
	}

	public function test_disabled_compression_and_signature_less_encryption(): void {
		$p = PackageConfig::fromJson( '{"Compression":{"Enabled":false,"Type":"gzip"},"Encrypted":true}' );
		$this->assertSame( '', $p->compression() );
		$this->assertTrue( $p->isEncrypted(), 'Encrypted without a signature still needs a password.' );
		$this->assertSame( '', $p->encryptedSignature() );
	}

	public function test_unknown_compression_is_invalid(): void {
		$this->expectException( InvalidArchiveException::class );
		PackageConfig::fromJson( '{"Compression":{"Enabled":true,"Type":"zstd"}}' )->compression();
	}

	public function test_not_json_is_invalid(): void {
		$this->expectException( InvalidArchiveException::class );
		PackageConfig::fromJson( 'nope' );
	}

	public function test_round_trip_matches_plain_json_encode(): void {
		$data = [ 'SiteURL' => 'https://a.test/x', 'Plugins' => [ 'a/a.php' ] ];
		$this->assertSame( json_encode( $data ), PackageConfig::fromArray( $data )->toJson() );
		$this->assertSame( $data, PackageConfig::fromJson( (string) json_encode( $data ) )->toArray() );
	}

	public function test_multisite(): void {
		$m = MultisiteConfig::fromJson( '{"Network":false,"Sites":[{"BlogID":2}]}' );
		$this->assertFalse( $m->isNetwork() );
		$this->assertSame( [ [ 'BlogID' => 2 ] ], $m->sites() );
		$this->assertTrue( MultisiteConfig::fromJson( '{"Network":true}' )->isNetwork() );
		$this->assertSame( [], MultisiteConfig::fromJson( '{}' )->sites() );
	}

	/**
	 * @dataProvider provide_encryption_edge_cases
	 */
	public function test_encryption_with_various_signatures_and_enabled_values( mixed $encrypted, mixed $signature, bool $expected ): void {
		$data = array_filter(
			[ 'Encrypted' => $encrypted, 'EncryptedSignature' => $signature ],
			fn( $v ) => $v !== 'UNSET'
		);
		$p = PackageConfig::fromArray( $data );
		$this->assertSame( $expected, $p->isEncrypted() );
	}

	public static function provide_encryption_edge_cases(): array {
		return [
			'signature "0" with Encrypted true' => [ true, '0', true ], // The flag decides, not the signature.
			'no signature with Encrypted true' => [ true, 'UNSET', true ],
			'numeric signature 123 with Encrypted true' => [ true, 123, true ], // ! empty(123)
			'Encrypted "0" with valid signature' => [ '0', 'sig', false ], // empty("0") is false
			'Encrypted 0 with valid signature' => [ 0, 'sig', false ], // empty(0) is true
			'Encrypted "" with valid signature' => [ '', 'sig', false ], // empty("") is true
		];
	}

	/**
	 * @dataProvider provide_compression_enabled_edge_cases
	 */
	public function test_compression_with_various_enabled_values( mixed $enabled, string $expected ): void {
		$p = PackageConfig::fromArray( [ 'Compression' => [ 'Enabled' => $enabled, 'Type' => 'gzip' ] ] );
		$this->assertSame( $expected, $p->compression() );
	}

	public static function provide_compression_enabled_edge_cases(): array {
		return [
			'Enabled "0"' => [ '0', '' ], // empty("0") is false
			'Enabled 0' => [ 0, '' ], // empty(0) is true
			'Enabled ""' => [ '', '' ], // empty("") is true
		];
	}
}
