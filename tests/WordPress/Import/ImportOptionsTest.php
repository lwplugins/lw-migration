<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ImportOptionsTest extends MonkeyTestCase {

	private const UNKNOWN  = 'Unknown import source: %s';
	private const EMPTY    = 'Tell what to import: a backup name, a .wpress file or an address.';
	private const NOT_HTTP = 'Only http and https addresses can be imported.';
	private const BAD_URL  = 'This address cannot be imported: it has no host name or contains spaces or control characters.';

	public function test_round_trip(): void {
		$o = ImportOptions::fromArray( [ 'source' => 'url', 'location' => 'https://x.test/a.wpress', 'safetyBackup' => false ] );
		$this->assertSame( [ 'source' => 'url', 'location' => 'https://x.test/a.wpress', 'safetyBackup' => false ], $o->toArray() );
		$this->assertTrue( ImportOptions::fromArray( [ 'source' => 'file', 'location' => '/a.wpress' ] )->safetyBackup );
		$this->assertSame( 'backup', ImportOptions::fromArray( [ 'source' => 'backup', 'location' => 'b.wpress' ] )->source );
		$this->assertSame( 'url', ImportOptions::fromArray( [ 'source' => 'url', 'location' => 'HTTPS://x.test/a.wpress' ] )->source );
	}

	public function test_password_is_never_kept(): void {
		$data = ImportOptions::fromArray( [ 'source' => 'file', 'location' => '/a.wpress', 'password' => 'secret' ] )->toArray();
		$this->assertArrayNotHasKey( 'password', $data );
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $data ) );
	}

	public function test_safety_backup_is_parsed_as_a_boolean(): void {
		$cases = [
			[ false, false ],
			[ 'no', false ],
			[ '0', false ],
			[ 0, false ],
			[ 'off', false ],
			[ true, true ],
			[ 'yes', true ],
			[ '1', true ],
			[ 'garbage', true ],
			[ [ 'x' ], true ],
			[ null, true ],
		];
		foreach ( $cases as [ $value, $expected ] ) {
			$o = ImportOptions::fromArray( [ 'source' => 'file', 'location' => '/a.wpress', 'safetyBackup' => $value ] );
			$this->assertSame( $expected, $o->safetyBackup, 'safetyBackup ' . json_encode( $value ) );
		}
	}

	public function test_validation_messages(): void {
		$cases = [
			[ [ 'source' => 'ftp', 'location' => 'x' ], sprintf( self::UNKNOWN, 'ftp' ) ],
			[ [ 'source' => [ 'file' ], 'location' => 'x' ], sprintf( self::UNKNOWN, '' ) ],
			[ [], sprintf( self::UNKNOWN, '' ) ],
			[ [ 'source' => 'file', 'location' => '' ], self::EMPTY ],
			[ [ 'source' => 'file', 'location' => [ '/a.wpress' ] ], self::EMPTY ],
			[ [ 'source' => 'backup', 'location' => 5 ], self::EMPTY ],
			[ [ 'source' => 'url', 'location' => 'file:///etc/passwd' ], self::NOT_HTTP ],
			[ [ 'source' => 'url', 'location' => 'https://' ], self::BAD_URL ],
			[ [ 'source' => 'url', 'location' => 'https:///a.wpress' ], self::BAD_URL ],
			[ [ 'source' => 'url', 'location' => 'https://x.test/a b.wpress' ], self::BAD_URL ],
			[ [ 'source' => 'url', 'location' => "https://x.test/a.wpress\n" ], self::BAD_URL ],
			[ [ 'source' => 'url', 'location' => "https://x.test/\x00a.wpress" ], self::BAD_URL ],
			[ [ 'source' => 'url', 'location' => "https://x.test/\x7Fa.wpress" ], self::BAD_URL ],
		];
		foreach ( $cases as [ $bad, $message ] ) {
			try {
				ImportOptions::fromArray( $bad );
				$this->fail( 'Accepted: ' . json_encode( $bad ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage(), (string) json_encode( $bad ) );
			}
		}
	}

	public function test_upload_and_backup_folder(): void {
		$upload = ImportOptions::fromArray( [ 'source' => 'upload', 'location' => 'My Site (1).wpress' ] );
		$this->assertSame( [ 'source' => 'upload', 'location' => 'My Site (1).wpress', 'safetyBackup' => true ], $upload->toArray() );
		$backup = ImportOptions::fromArray( [ 'source' => 'backup', 'location' => 'b.wpress', 'folder' => 'external' ] );
		$this->assertSame( 'external', $backup->toArray()['folder'] );
		$this->assertSame( '', ImportOptions::fromArray( [ 'source' => 'url', 'location' => 'https://x.test/a.wpress', 'folder' => 'own' ] )->folder, 'A folder only means something for a backup.' );

		$bad = [
			[ 'source' => 'upload', 'location' => '../x.wpress' ],
			[ 'source' => 'upload', 'location' => 'x.zip' ],
			[ 'source' => 'backup', 'location' => 'b.wpress', 'folder' => 'elsewhere' ],
		];
		foreach ( $bad as $options ) {
			try {
				ImportOptions::fromArray( $options );
				$this->fail( 'Accepted: ' . json_encode( $options ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_a_link_import_names_a_connection(): void {
		$o = ImportOptions::fromArray( [ 'source' => 'link', 'location' => '0123456789abcdef', 'safetyBackup' => false ] );
		$this->assertSame( ImportOptions::LINK, $o->source );
		$this->assertSame( [ 'source' => 'link', 'location' => '0123456789abcdef', 'safetyBackup' => false ], $o->toArray() );
		$this->assertSame( $o->toArray(), ImportOptions::fromArray( $o->toArray() )->toArray() );
		foreach ( [ '0123456789ABCDEF', '0123456789abcde', '0123456789abcdef0', "0123456789abcdef\n", '../0123456789abcd', 'https://b.test' ] as $location ) {
			try {
				ImportOptions::fromArray( [ 'source' => 'link', 'location' => $location ] );
				$this->fail( 'A link import of ' . json_encode( $location ) . ' was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Unknown connection.', $e->getMessage() );
			}
		}
	}

	public function test_a_cloud_source_names_a_plain_backup(): void {
		$options = ImportOptions::fromArray( [ 'source' => ImportOptions::CLOUD, 'location' => 'a.wpress' ] );

		$this->assertSame( [ ImportOptions::CLOUD, 'a.wpress', true ], [ $options->source, $options->location, $options->safetyBackup ] );
		$this->assertSame( 'cloud_download', ImportPipeline::stepsFor( ImportOptions::CLOUD )[0] );
		$this->expectException( \InvalidArgumentException::class );
		ImportOptions::fromArray( [ 'source' => ImportOptions::CLOUD, 'location' => '../a.wpress' ] );
	}
}
