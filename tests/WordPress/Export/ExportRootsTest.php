<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ExportRootsTest extends MonkeyTestCase {

	public function test_default_roots_and_exclusions(): void {
		$roots = ExportRoots::build( SiteFixture::make( '/s' ), new ExportOptions(), '/s/wp-content/lw-migration-0123456789ab' );

		$this->assertSame( [ '', 'uploads', 'plugins', 'themes' ], array_column( $roots, 'entry' ) );
		$content = $roots[0]['filter'];
		foreach ( [ '/s/wp-content/uploads', '/s/wp-content/plugins', '/s/wp-content/themes', '/s/wp-content/lw-migration-0123456789ab/jobs/x', '/s/wp-content/ai1wm-backups/a.wpress', '/s/wp-content/database.sql', '/s/wp-content/w3tc-config/master.php' ] as $path ) {
			$this->assertTrue( $content->excludes( $path ), $path );
		}
		$this->assertFalse( $content->excludes( '/s/wp-content/mu-plugins/x.php' ) );
		$this->assertTrue( $content->excludes( '/s/wp-config.php' ) );
		$this->assertTrue( $content->excludes( '/wp-config.php' ) );
		$this->assertTrue( $roots[1]['filter']->excludes( '/s/wp-config.php' ) );
		$this->assertFalse( $roots[1]['filter']->excludes( '/s/wp-content/uploads/2026/a.jpg' ) );
		$this->assertTrue( $roots[2]['filter']->excludes( '/s/wp-content/plugins/lw-migration/lw-migration.php' ) );
		$this->assertTrue( $roots[2]['filter']->excludes( '/s/wp-content/plugins/sqlite-database-integration/load.php' ) );
		$this->assertFalse( $roots[2]['filter']->excludes( '/s/wp-content/plugins/inactive/inactive.php' ) );
	}

	public function test_options_remove_roots_and_inactive_software(): void {
		$options = ExportOptions::fromArray(
			[
				'noMedia'           => true,
				'noInactivePlugins' => true,
				'noInactiveThemes'  => true,
				'noCache'           => true,
				'noMustUsePlugins'  => true,
				'excludeFiles'      => [ 'big' ],
			]
		);
		$roots   = ExportRoots::build( SiteFixture::make( '/s' ), $options, '/st' );

		$this->assertSame( [ '', 'plugins', 'themes' ], array_column( $roots, 'entry' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/cache/page.html' ) );
		$this->assertFalse( $roots[0]['filter']->excludes( '/s/wp-content/cache2/page.html' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/mu-plugins/a.php' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/blogs.dir/2/x' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/big/file' ) );
		$this->assertTrue( $roots[1]['filter']->excludes( '/s/wp-content/plugins/inactive/inactive.php' ) );
		$this->assertTrue( $roots[1]['filter']->excludes( '/s/wp-content/plugins/hello.php' ) );
		$this->assertFalse( $roots[1]['filter']->excludes( '/s/wp-content/plugins/sample/sample.php' ) );
		$this->assertTrue( $roots[2]['filter']->excludes( '/s/wp-content/themes/old-theme/style.css' ) );
		$this->assertFalse( $roots[2]['filter']->excludes( '/s/wp-content/themes/twentytwentyfive/style.css' ) );
		$this->assertSame( [], ExportRoots::build( SiteFixture::make( '/s' ), ExportOptions::databaseOnly(), '/st' ) );
	}

	public function test_the_sqlite_database_file_is_left_out(): void {
		$site  = SiteFixture::make( '/s', [ 'sqliteDb' => '/s/wp-content/database/.ht.sqlite' ] );
		$roots = ExportRoots::build( $site, new ExportOptions(), '/st' );

		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/database/.ht.sqlite' ) );
		$this->assertFalse( $roots[0]['filter']->excludes( '/s/wp-content/database/other.txt' ) );
		$this->assertFalse( ExportRoots::build( SiteFixture::make( '/s' ), new ExportOptions(), '/st' )[0]['filter']->excludes( '/s/wp-content/database/.ht.sqlite' ) );
	}

	public function test_a_custom_backups_folder_inside_wp_content_is_left_out(): void {
		$inside = ExportRoots::build( SiteFixture::make( '/s', [ 'externalBackupsPath' => '/s/wp-content/my-backups/' ] ), new ExportOptions(), '/st' );
		$this->assertTrue( $inside[0]['filter']->excludes( '/s/wp-content/my-backups/a.wpress' ) );
		$this->assertTrue( $inside[1]['filter']->excludes( '/s/wp-content/my-backups/a.wpress' ) );

		foreach ( [ '/s/wp-content', '/s/wp-content/', '/s', '/', '/elsewhere/backups', 'relative/backups', '/s/wp-content-other/b' ] as $path ) {
			$roots = ExportRoots::build( SiteFixture::make( '/s', [ 'externalBackupsPath' => $path ] ), new ExportOptions(), '/st' );
			$this->assertFalse( $roots[0]['filter']->excludes( '/s/wp-content/index.php' ), $path );
			$this->assertFalse( $roots[0]['filter']->excludes( '/s/wp-content-other/b/x' ), $path );
		}
	}

	public function test_every_storage_folder_of_ours_is_left_out(): void {
		$dir = TempDir::create();
		try {
			foreach ( [ 'lw-migration-aaaaaaaaaaaa', 'lw-migration-bbbbbbbbbbbb', 'lw-migration-notours', 'lw-migration-ABCDEF012345' ] as $name ) {
				mkdir( $dir . '/wp-content/' . $name, 0755, true );
			}
			$roots  = ExportRoots::build( SiteFixture::make( $dir ), new ExportOptions(), $dir . '/wp-content/lw-migration-aaaaaaaaaaaa' );
			$filter = $roots[0]['filter'];

			$this->assertTrue( $filter->excludes( $dir . '/wp-content/lw-migration-aaaaaaaaaaaa/backups' ) );
			$this->assertTrue( $filter->excludes( $dir . '/wp-content/lw-migration-bbbbbbbbbbbb/jobs/x/job.json' ) );
			$this->assertFalse( $filter->excludes( $dir . '/wp-content/lw-migration-notours/a.txt' ) );
			$this->assertFalse( $filter->excludes( $dir . '/wp-content/lw-migration-ABCDEF012345/a.txt' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}
	public function test_a_network_backup_keeps_the_single_site_roots(): void {
		$network = [
			'mode'         => 'network',
			'chosen'       => [],
			'activeThemes' => [ 'twentytwentyfive' ],
		];
		$roots   = ExportRoots::build( SiteFixture::make( '/s' ), new ExportOptions(), '/st', $network );

		$this->assertSame( [ '', 'uploads', 'plugins', 'themes' ], array_column( $roots, 'entry' ) );
		$this->assertFalse( $roots[1]['filter']->excludes( '/s/wp-content/uploads/sites/2/a.jpg' ) );
		$this->assertFalse( $roots[0]['filter']->excludes( '/s/wp-content/blogs.dir/2/files/a.jpg' ) );
	}

	public function test_a_sites_backup_carries_uploads_sites_of_the_chosen_subsites(): void {
		$network = [
			'mode'         => 'sites',
			'chosen'       => [ 1, 3 ],
			'activeThemes' => [ 'twentytwentyfive' ],
		];
		$roots   = ExportRoots::build( SiteFixture::make( '/s' ), new ExportOptions(), '/st', $network );

		$this->assertSame( [ '', 'uploads', 'plugins', 'themes', 'uploads/sites/3', 'blogs.dir/3' ], array_column( $roots, 'entry' ) );
		$this->assertSame( [ '/s/wp-content/uploads/sites/3', '/s/wp-content/blogs.dir/3' ], array_column( array_slice( $roots, 4 ), 'dir' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/blogs.dir/2/files/a.jpg' ) );
		$this->assertTrue( $roots[1]['filter']->excludes( '/s/wp-content/uploads/sites/2/a.jpg' ) );
		$this->assertFalse( $roots[1]['filter']->excludes( '/s/wp-content/uploads/2026/a.jpg' ) );
		$this->assertFalse( $roots[4]['filter']->excludes( '/s/wp-content/uploads/sites/3/a.jpg' ), 'R11: an extra root does not exclude itself.' );
		$this->assertFalse( $roots[5]['filter']->excludes( '/s/wp-content/blogs.dir/3/files/a.jpg' ) );
		$this->assertTrue( $roots[4]['filter']->excludes( '/s/wp-config.php' ), 'The shared exclusions still apply.' );
		$this->assertContains( '/s/wp-content/uploads/sites/3', ExportRoots::territories( SiteFixture::make( '/s' ), $network ) );
	}

	public function test_a_sites_backup_without_the_main_site_skips_the_main_uploads(): void {
		$network = [
			'mode'         => 'sites',
			'chosen'       => [ 2 ],
			'activeThemes' => [ 'twentytwentyfive' ],
		];
		$roots   = ExportRoots::build( SiteFixture::make( '/s' ), new ExportOptions(), '/st', $network );

		$this->assertSame( [ '', 'plugins', 'themes', 'uploads/sites/2', 'blogs.dir/2' ], array_column( $roots, 'entry' ) );
		$this->assertTrue( $roots[0]['filter']->excludes( '/s/wp-content/uploads/2026/a.jpg' ), 'The main uploads stay a territory of their own.' );
		$media = ExportRoots::build( SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'noMedia' => true ] ), '/st', $network );
		$this->assertSame( [ '', 'plugins', 'themes' ], array_column( $media, 'entry' ), 'Without media no site brings uploads.' );
	}

	public function test_a_theme_active_on_any_exported_site_is_kept(): void {
		$network = [
			'mode'         => 'network',
			'chosen'       => [],
			'activeThemes' => [ 'twentytwentyfive', 'old-theme' ],
		];
		$options = ExportOptions::fromArray( [ 'noInactiveThemes' => true ] );

		$this->assertFalse( ExportRoots::build( SiteFixture::make( '/s' ), $options, '/st', $network )[3]['filter']->excludes( '/s/wp-content/themes/old-theme/style.css' ) );
		$this->assertTrue( ExportRoots::build( SiteFixture::make( '/s' ), $options, '/st' )[3]['filter']->excludes( '/s/wp-content/themes/old-theme/style.css' ) );
	}

	public function test_a_sites_backup_without_the_main_site_leaves_its_theme_out(): void {
		$network = [
			'mode'         => 'sites',
			'chosen'       => [ 2 ],
			'activeThemes' => [ 'old-theme' ],
		];
		$themes  = ExportRoots::build( SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'noInactiveThemes' => true ] ), '/st', $network )[2]['filter'];

		$this->assertTrue( $themes->excludes( '/s/wp-content/themes/twentytwentyfive/style.css' ), 'Only the chosen sites\' themes count, as the reference.' );
		$this->assertFalse( $themes->excludes( '/s/wp-content/themes/old-theme/style.css' ) );
	}

	public function test_chosen_backup_folders_are_never_archived_even_through_a_link(): void {
		$dir = TempDir::create();
		try {
			$chosen = $dir . '/outer/chosen';
			foreach ( [ $dir . '/site/wp-content/uploads/2026', $chosen, $dir . '/moving' ] as $folder ) {
				mkdir( $folder, 0755, true );
			}
			file_put_contents( $dir . '/site/wp-content/uploads/2026/a.jpg', 'x' );
			file_put_contents( $chosen . '/x.wpress', 'backup' );
			file_put_contents( $dir . '/outer/kept.txt', 'x' );
			file_put_contents( $dir . '/moving/y.wpress', 'backup' );
			symlink( $chosen, $dir . '/site/wp-content/uploads/to-chosen' );
			symlink( $chosen, $dir . '/site/wp-content/to-chosen' );
			symlink( $chosen . '/x.wpress', $dir . '/site/wp-content/uploads/x.wpress' );
			symlink( $dir . '/outer', $dir . '/site/wp-content/uploads/outer' );
			symlink( $dir . '/moving', $dir . '/site/wp-content/moving' );

			$entries = $this->enumerate( $dir . '/site', [ $chosen, $dir . '/moving' ] );

			$this->assertSame( [ 'uploads/2026/a.jpg', 'uploads/outer/kept.txt' ], $entries );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_chosen_backup_folders_are_compared_by_identity_not_by_spelling(): void {
		$dir = TempDir::create();
		try {
			$chosen = $dir . '/outer/chosen';
			mkdir( $dir . '/site/wp-content/uploads', 0755, true );
			mkdir( $chosen, 0755, true );
			if ( ! is_dir( $dir . '/OUTER/CHOSEN' ) ) {
				$this->markTestSkipped( 'Needs a case-insensitive file system.' );
			}
			file_put_contents( $chosen . '/x.wpress', 'backup' );
			symlink( $dir . '/OUTER/CHOSEN', $dir . '/site/wp-content/uploads/folder' );
			symlink( $dir . '/OUTER/CHOSEN/x.wpress', $dir . '/site/wp-content/uploads/file.wpress' );
			symlink( $dir . '/OUTER', $dir . '/site/wp-content/uploads/parent' );

			$this->assertSame( [], $this->enumerate( $dir . '/site', [ $chosen ] ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_a_hard_link_to_a_backup_is_never_archived(): void {
		$dir = TempDir::create();
		try {
			$chosen = $dir . '/chosen';
			mkdir( $dir . '/site/wp-content/uploads', 0755, true );
			mkdir( $chosen );
			file_put_contents( $chosen . '/x.wpress', 'backup' );
			file_put_contents( $dir . '/site/wp-content/uploads/own.txt', 'x' );
			link( $chosen . '/x.wpress', $dir . '/site/wp-content/uploads/copy.bin' );

			$this->assertSame( [ 'uploads/own.txt' ], $this->enumerate( $dir . '/site', [ $chosen ] ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_the_external_folder_and_leftover_folders_are_sealed_by_identity(): void {
		$dir = TempDir::create();
		try {
			$content = $dir . '/site/wp-content';
			foreach ( [ 'uploads', 'ai1wm-backups', 'lw-migration-backups-0123456789ab', 'lw-migration-aaaaaaaaaaaa/backups' ] as $folder ) {
				mkdir( $content . '/' . $folder, 0755, true );
			}
			file_put_contents( $content . '/ai1wm-backups/a.wpress', 'x' );
			file_put_contents( $content . '/lw-migration-backups-0123456789ab/b.wpress', 'x' );
			file_put_contents( $content . '/lw-migration-aaaaaaaaaaaa/backups/c.wpress', 'x' );
			$variants = [
				'external'  => $content . '/AI1WM-Backups',
				'suffixed'  => $content . "/lw-migration-backup\u{017f}-0123456789ab",
				'storage'   => $content . '/LW-MIGRATION-AAAAAAAAAAAA',
			];
			$variants = array_filter( $variants, 'is_dir' );
			if ( [] === $variants ) {
				$this->markTestSkipped( 'Needs a case-insensitive file system.' );
			}
			foreach ( $variants as $name => $variant ) {
				symlink( $variant, $content . '/uploads/' . $name );
			}

			$this->assertSame( [], $this->enumerate( $dir . '/site', [] ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_suffixed_backup_folders_in_wp_content_are_never_archived(): void {
		$dir = TempDir::create();
		try {
			foreach ( [ 'lw-migration-backups-0123456789ab', 'lw-migration-backups-notours', 'lw-migration-backups-ABCDEF012345' ] as $name ) {
				mkdir( $dir . '/site/wp-content/' . $name, 0755, true );
				file_put_contents( $dir . '/site/wp-content/' . $name . '/x.wpress', 'x' );
			}

			$this->assertSame( [ 'lw-migration-backups-ABCDEF012345/x.wpress', 'lw-migration-backups-notours/x.wpress' ], $this->enumerate( $dir . '/site', [] ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * @param string             $root       Site root.
	 * @param array<int, string> $backupDirs Site::$backupDirs.
	 * @return array<int, string> Listed entries.
	 */
	private function enumerate( string $root, array $backupDirs ): array {
		$site  = SiteFixture::make( $root, [ 'backupDirs' => $backupDirs ] );
		$roots = ExportRoots::build( $site, new ExportOptions(), $root . '/wp-content/lw-migration-0123456789ab' );
		$list  = dirname( $root ) . '/files.list';
		$this->assertTrue( ( new FileEnumerator( $roots, $list, ExportRoots::territories( $site ) ) )->run( new EnumCursor(), new NoDeadline(), static function (): void {} ) );
		$entries = [];
		foreach ( (array) file( $list ) as $line ) {
			$entries[] = FileEnumerator::parseLine( (string) $line )['entry'];
		}
		sort( $entries );

		return $entries;
	}
}
