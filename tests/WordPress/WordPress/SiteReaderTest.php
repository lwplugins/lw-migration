<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteReader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SiteReaderTest extends MonkeyTestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	public function test_reads_the_site(): void {
		FakeOptions::install(
			[
				'upload_path'     => '',
				'upload_url_path' => '',
				'active_plugins'  => [ 'lw-migration/lw-migration.php', 'sample/sample.php' ],
				'template'        => 'parent',
				'stylesheet'      => 'child',
				'ai1wm_backups_path' => '/w/wp-content/custom-backups',
			]
		);
		$this->stubWordPress(
			[
				'siteurl' => 'https://a.test/wp',
				'home'    => 'https://a.test',
			]
		);
		FakeWpdb::install()->base_prefix = 'wpx_';
		$GLOBALS['wp_version']           = '7.1.2';

		$site = SiteReader::read();

		$this->assertSame( 'https://a.test/wp', $site->siteUrl );
		$this->assertSame( 'https://a.test', $site->internalHomeUrl );
		$this->assertSame( '/w/wp-content/uploads', $site->uploadsDir );
		$this->assertSame( 'https://a.test/wp-content/uploads/', $site->uploadsUrl );
		$this->assertSame(
			[
				'parent' => '/w/wp-content/themes',
				'child'  => '/w/wp-content/themes',
			],
			$site->themes
		);
		$this->assertSame( [ 'lw-migration/lw-migration.php', 'sample/sample.php', 'hello.php' ], $site->plugins );
		$this->assertSame( 'wpx_', $site->tablePrefix );
		$this->assertSame( '7.1.2', $site->wpVersion );
		$this->assertSame( 'child', $site->stylesheet );
		$this->assertSame( 'lw-migration/lw-migration.php', $site->ownPlugin );
		$this->assertSame( '/w/wp-content/custom-backups', $site->externalBackupsPath );
		$this->assertSame( '', $site->sqliteDb );
	}

	#[RunInSeparateProcess]
	public function test_reads_the_sqlite_database_file(): void {
		define( 'FQDB', '/w/wp-content/database/.ht.sqlite' );
		FakeOptions::install();
		FakeWpdb::install( [] );
		$this->stubWordPress( [] );

		$site = SiteReader::read();

		$this->assertSame( '/w/wp-content/database/.ht.sqlite', $site->sqliteDb );
		$this->assertSame( '', $site->externalBackupsPath );
	}

	public function test_internal_urls_missing_from_alloptions_are_read_from_the_table(): void {
		FakeOptions::install();
		$db              = FakeWpdb::install(
			[
				'siteurl' => 'https://db.test/wp',
				'home'    => 'https://db.test',
			]
		);
		$db->base_prefix = 'wpy_';
		$this->stubWordPress( [] );

		$site = SiteReader::read();

		$this->assertSame( 'https://db.test/wp', $site->internalSiteUrl );
		$this->assertSame( 'https://db.test', $site->internalHomeUrl );
		$this->assertSame( 'wpy_', $site->tablePrefix );
	}

	public function test_the_snapshot_names_the_chosen_backup_folders(): void {
		FakeOptions::install();
		FakeWpdb::install(
			[
				Options::STORAGE_DIR => 'lw-migration-0123456789ab',
				Options::BACKUP_DIR  => '{"path":"/x/b","moving":"","marker":"0123456789abcdef"}',
			]
		);
		$this->stubWordPress( [] );

		$site = SiteReader::read();

		$this->assertSame( [ '/x/b', WP_CONTENT_DIR . '/lw-migration-0123456789ab/backups' ], $site->backupDirs );
		$this->assertSame( $site->backupDirs, Site::fromArray( $site->toArray() )->backupDirs );
		$this->assertSame( [], Site::fromArray( [ 'abspath' => '/w/' ] )->backupDirs, 'A snapshot of a job started before the update has none.' );
	}

	public function test_without_a_stored_storage_folder_the_snapshot_names_none(): void {
		FakeOptions::install();
		$db             = FakeWpdb::install();
		$db->failWrites = true;
		$this->stubWordPress( [] );

		$this->assertSame( [], SiteReader::read()->backupDirs );
		$this->assertSame( [], $db->rows, 'Reading the site never names the storage folder.' );
	}

	/**
	 * @param array<string, string> $alloptions What wp_load_alloptions() returns.
	 */
	private function stubWordPress( array $alloptions ): void {
		Functions\when( 'site_url' )->justReturn( 'https://a.test/wp' );
		Functions\when( 'home_url' )->justReturn( 'https://a.test' );
		Functions\when( 'wp_load_alloptions' )->justReturn( $alloptions );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/w/wp-content/uploads/',
				'baseurl' => 'https://a.test/wp-content/uploads',
			]
		);
		Functions\when( 'get_theme_root' )->justReturn( '/w/wp-content/themes' );
		Functions\when( 'search_theme_directories' )->justReturn(
			[
				'parent' => [
					'theme_file' => 'parent/style.css',
					'theme_root' => '/w/wp-content/themes',
				],
				'child'  => [
					'theme_file' => 'child/style.css',
					'theme_root' => '/w/wp-content/themes',
				],
			]
		);
		Functions\when( 'get_plugins' )->justReturn(
			[
				'lw-migration/lw-migration.php' => [],
				'sample/sample.php'             => [],
				'hello.php'                     => [],
			]
		);
		Functions\when( 'plugin_basename' )->justReturn( 'lw-migration/lw-migration.php' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
	}
}
