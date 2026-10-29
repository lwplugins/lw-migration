<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\SecretFiles;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SecretFilesTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_reads_a_multi_line_secret_from_its_file_flag(): void {
		file_put_contents( $this->dir . '/id', "-----BEGIN OPENSSH PRIVATE KEY-----\r\nAAAA\r\n-----END OPENSSH PRIVATE KEY-----\r\n" );
		$fields = [ new Field( 'privateKey', Field::SECRET_TEXT, 'Private key', true ) ];

		$this->assertSame( [ 'privateKey' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----" ], SecretFiles::read( $fields, [ 'private-key-file' => $this->dir . '/id' ] ) );
	}

	public function test_a_missing_required_file_and_an_unreadable_or_huge_one_stop_without_naming_the_path(): void {
		$fields = [ new Field( 'privateKey', Field::SECRET_TEXT, 'Private key', true ) ];
		file_put_contents( $this->dir . '/big', str_repeat( 'k', SecretFiles::MAX_BYTES + 1 ) );
		file_put_contents( $this->dir . '/empty', "\r\n" );

		foreach ( [ [], [ 'private-key-file' => $this->dir . '/nope' ], [ 'private-key-file' => $this->dir . '/big' ], [ 'private-key-file' => $this->dir ], [ 'private-key-file' => true ], [ 'private-key-file' => $this->dir . '/empty' ] ] as $assoc ) {
			try {
				SecretFiles::read( $fields, $assoc );
				$this->fail( 'Refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( '--private-key-file', $e->getMessage() );
				$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			}
		}
		$this->assertSame( [], SecretFiles::read( [ new Field( 'privateKey', Field::SECRET_TEXT, 'Private key' ) ], [] ), 'An optional one may be left out.' );
	}
}
