<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\CLI\ImportSource;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ImportSourceTest extends MonkeyTestCase {

	private string $dir;

	private Repository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		file_put_contents( $this->dir . '/own/a.wpress', 'x' );
		file_put_contents( $this->dir . '/loose.wpress', 'x' );
		$this->repo = new Repository( $this->dir . '/own', '' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_an_address_is_a_url_source(): void {
		$options = ImportSource::options( 'https://example.com/dl/secret-token/site.wpress?sig=abcdef', $this->repo, true );

		$this->assertSame( ImportOptions::URL, $options->source );
		$this->assertSame( 'https://example.com/dl/secret-token/site.wpress?sig=abcdef', $options->location );
		$this->assertSame( 'https://example.com/…/site.wpress', ImportSource::shown( $options, 'ignored' ) );
	}

	public function test_a_backup_name_is_a_backup_source(): void {
		$options = ImportSource::options( 'a.wpress', $this->repo, false );

		$this->assertSame( ImportOptions::BACKUP, $options->source );
		$this->assertSame( 'a.wpress', $options->location );
		$this->assertFalse( $options->safetyBackup );
		$this->assertSame( 'a.wpress', ImportSource::shown( $options, 'a.wpress' ) );
	}

	public function test_an_existing_file_is_a_file_source_with_its_real_path(): void {
		$typed   = $this->dir . '/own/../loose.wpress';
		$options = ImportSource::options( $typed, $this->repo, true );

		$this->assertSame( ImportOptions::FILE, $options->source );
		$this->assertSame( (string) realpath( $this->dir . '/loose.wpress' ), $options->location );
		$this->assertSame( $typed, ImportSource::shown( $options, $typed ), 'Messages name the file the way it was typed.' );
	}

	public function test_a_missing_file_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing.wpress' );

		ImportSource::options( 'missing.wpress', $this->repo, true );
	}

	public function test_an_unusable_address_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		ImportSource::options( 'https:///no-host.wpress', $this->repo, true );
	}
}
