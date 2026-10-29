<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportTemps;
use LightweightPlugins\Migration\Import\OwnershipNotice;
use LightweightPlugins\Migration\Import\Steps\CleanStep;
use LightweightPlugins\Migration\Import\Steps\DoneStep;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\PostImportStep;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\PostImport\Fixes;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FinishingStepsTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_without_database_only_cache_and_rewrite_fixes_run(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		Functions\expect( 'wp_cache_flush' )->once();
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );

		$this->assertTrue( ( new PostImportStep( new Fixes() ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertArrayNotHasKey( 'rewrite_rules', FakeOptions::$values );
		$this->assertSame( count( Fixes::NO_DATABASE_TASKS ), $job->cursor['task'] );
	}

	public function test_fixes_resume_at_the_saved_task(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		Functions\expect( 'wp_cache_flush' )->never();
		$job                 = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->cursor['task'] = 1; // flushCaches already ran.

		$this->assertTrue( ( new PostImportStep( new Fixes() ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertArrayNotHasKey( 'rewrite_rules', FakeOptions::$values );
	}

	public function test_fixes_stop_at_the_deadline_between_tasks(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );

		$result = ( new PostImportStep( new Fixes() ) )->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 1, $job->cursor['task'] );
		$this->assertArrayHasKey( 'rewrite_rules', FakeOptions::$values );
	}

	public function test_must_use_plugins_move_in_after_the_swap(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging . '/dir', 0755, true );
		file_put_contents( $staging . '/custom.php', '<?php // custom' );
		file_put_contents( $staging . '/dir/inner.php', '<?php // inner' );
		$temp = TempName::name();
		file_put_contents( $staging . '/dir/' . $temp, 'partial' );

		$this->assertTrue( ( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( '<?php // custom', file_get_contents( $this->dir . '/wp-content/mu-plugins/custom.php' ) );
		$this->assertFileExists( $this->dir . '/wp-content/mu-plugins/dir/inner.php' );
		$this->assertFileDoesNotExist( $this->dir . '/wp-content/mu-plugins/dir/' . $temp, 'An interrupted extraction is never moved in.' );
		$this->assertDirectoryDoesNotExist( $staging );
	}

	public function test_must_use_plugins_replace_existing_files_and_skip_links(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins', 0755, true );
		file_put_contents( $this->dir . '/wp-content/mu-plugins/custom.php', 'old' );
		file_put_contents( $this->dir . '/secret.txt', 'secret' );
		file_put_contents( $staging . '/custom.php', 'new' );
		symlink( $this->dir . '/secret.txt', $staging . '/link.php' );

		( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() );

		$this->assertSame( 'new', file_get_contents( $this->dir . '/wp-content/mu-plugins/custom.php' ) );
		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'Replaced these must-use plugins of this site with the archive\'s copies: custom.php', $job->warnings[0] );
		$this->assertFalse( file_exists( $this->dir . '/wp-content/mu-plugins/link.php' ) || is_link( $this->dir . '/wp-content/mu-plugins/link.php' ) );
		$this->assertSame( 'secret', file_get_contents( $this->dir . '/secret.txt' ) );
	}

	public function test_replaced_must_use_plugins_are_named_across_requests(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins', 0755, true );
		foreach ( [ 'a.php', 'b.php', 'c.php' ] as $name ) {
			file_put_contents( $this->dir . '/wp-content/mu-plugins/' . $name, 'old' );
			file_put_contents( $staging . '/' . $name, 'new' );
		}
		$step  = new MuPluginsStep( $this->store );
		$calls = 0;
		do {
			$result      = $step->run( $job, new CountdownDeadline( 0 ) ); // Each request ends after one file.
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true ); // Saved between requests.
			++$calls;
		} while ( ! $result->isDone() && $calls < 10 );

		$this->assertSame( 3, $calls );
		$this->assertSame( [ 'Replaced these must-use plugins of this site with the archive\'s copies: a.php, b.php, c.php' ], $job->warnings );
		$this->assertSame( 'new', file_get_contents( $this->dir . '/wp-content/mu-plugins/c.php' ) );
	}

	public function test_many_new_must_use_plugins_finish_under_the_attempt_guard(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins', 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins/blocked.php' ); // Cannot be moved: warned once, not retried.
		file_put_contents( $staging . '/blocked.php', 'x' );
		for ( $i = 0; $i < 10; $i++ ) {
			file_put_contents( $staging . "/n{$i}.php", 'new' );
		}
		$step  = new MuPluginsStep( $this->store );
		$calls = 0;
		do {
			AttemptGuard::record( $job, MuPluginsStep::KEY ); // As Runner::countAttempt() does before every call.
			$result      = $step->run( $job, new CountdownDeadline( 0 ) );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true );
			++$calls;
		} while ( ! $result->isDone() && $calls < 30 );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( 11, $calls, 'One file per request, every request moves the cursor.' );
		$this->assertSame( 'new', file_get_contents( $this->dir . '/wp-content/mu-plugins/n9.php' ) );
		$this->assertCount( 1, preg_grep( '/blocked\.php/', $job->warnings ) );
	}

	public function test_a_copied_file_whose_source_stayed_is_not_copied_again(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins', 0755, true );
		file_put_contents( $this->dir . '/wp-content/mu-plugins/a.php', 'old' );
		file_put_contents( $staging . '/a.php', 'new' );
		file_put_contents( $staging . '/b.php', 'new' );
		chmod( $staging, 0555 ); // The sources cannot leave the staging folder: copied, not moved.
		$step = new MuPluginsStep( $this->store );
		try {
			$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true );
			file_put_contents( $this->dir . '/wp-content/mu-plugins/a.php', 'changed after the copy' );
			$this->assertTrue( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		} finally {
			chmod( $staging, 0755 );
		}

		$this->assertSame( 'changed after the copy', file_get_contents( $this->dir . '/wp-content/mu-plugins/a.php' ), 'Not copied a second time.' );
		$this->assertSame( [ 'Replaced these must-use plugins of this site with the archive\'s copies: a.php' ], $job->warnings, 'Counted once.' );
	}

	public function test_a_file_that_cannot_be_moved_is_skipped_with_a_warning(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins/blocked.php', 0755, true );
		file_put_contents( $staging . '/blocked.php', 'x' );
		file_put_contents( $staging . '/zz.php', 'z' );

		$this->assertTrue( ( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( 'z', file_get_contents( $this->dir . '/wp-content/mu-plugins/zz.php' ), 'The other files are still restored.' );
		$this->assertStringContainsString( 'Could not restore the must-use plugin blocked.php', implode( "\n", $job->warnings ) );
		$this->assertStringNotContainsString( $this->dir, implode( "\n", $job->warnings ) );
	}

	public function test_a_copy_left_by_a_dead_request_is_swept_on_replay(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$work    = $this->store->dir( $job->id );
		$staging = $work . '/' . ImportFiles::STAGING;
		mkdir( $staging . '/dir', 0755, true );
		mkdir( $this->dir . '/wp-content/mu-plugins/dir', 0755, true );
		file_put_contents( $staging . '/dir/inner.php', 'inner' );
		// The request journaled the folder, started the copy and died.
		ImportTemps::record( $work, MuPluginsStep::BASE, 'dir' );
		$orphan = TempName::generate( $this->dir . '/wp-content/mu-plugins/dir' );
		file_put_contents( $orphan, 'half' );

		( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() );

		$this->assertFileDoesNotExist( $orphan );
		$this->assertSame( 'inner', file_get_contents( $this->dir . '/wp-content/mu-plugins/dir/inner.php' ) );
		$this->assertStringContainsString( MuPluginsStep::BASE . '|dir', (string) file_get_contents( $work . '/' . ImportFiles::EXTRACT_DIRS ), 'Every target folder is journaled before a copy can start.' );
	}

	public function test_a_staged_copy_that_cannot_be_deleted_gives_no_php_warning(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;
		mkdir( $staging, 0755, true );
		file_put_contents( $staging . '/a.php', 'new' );
		chmod( $staging, 0555 ); // The rename out of it fails; the copy works, the source stays.
		try {
			$this->assertTrue( ( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		} finally {
			chmod( $staging, 0755 );
		}

		$this->assertSame( 'new', file_get_contents( $this->dir . '/wp-content/mu-plugins/a.php' ) );
		$this->assertStringNotContainsString( $this->dir, implode( "\n", $job->warnings ) );
	}

	public function test_work_files_that_cannot_be_deleted_are_reported_without_a_path(): void {
		$job  = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$work = $this->store->dir( $job->id );
		file_put_contents( $work . '/' . ImportFiles::DATABASE, 'x' );
		chmod( $work, 0555 );
		try {
			$this->assertTrue( ( new CleanStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		} finally {
			chmod( $work, 0755 );
		}

		$this->assertFileExists( $work . '/' . ImportFiles::DATABASE );
		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'could not be removed', $job->warnings[0] );
		$this->assertStringNotContainsString( $this->dir, $job->warnings[0] );
	}

	public function test_the_ownership_warning_is_shared_and_added_once(): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );

		OwnershipNotice::once( $job );
		OwnershipNotice::once( $job );

		$this->assertCount( 1, $job->warnings );
		$this->assertTrue( $job->state['ownershipWarned'] );
	}

	public function test_without_staged_plugins_nothing_happens(): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );

		$this->assertTrue( ( new MuPluginsStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertDirectoryDoesNotExist( $this->dir . '/wp-content/mu-plugins' );
	}

	public function test_done_and_clean(): void {
		$job                        = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->state['safetyBackup'] = [ 'name' => 'pre-import-20261007-000000.wpress' ];
		$job->state['plugins']      = [
			'active'  => [],
			'removed' => [ 'a/a.php' => 'lockout' ],
		];
		$this->assertStringContainsString( 'pre-import-20261007-000000.wpress', ( new DoneStep() )->run( $job, new NoDeadline() )->message() );
		$this->assertSame( [ 'a/a.php' ], $job->state['summary']['deactivated'] );

		$work = $this->store->dir( $job->id );
		$temp = TempName::name();
		foreach ( [ ImportFiles::DATABASE, ImportFiles::DEFERRED, ImportFiles::DOWNLOAD, ImportFiles::DOWNLOAD . RangeDownloader::PART_SUFFIX, $temp ] as $file ) {
			file_put_contents( $work . '/' . $file, 'x' );
		}
		mkdir( $work . '/' . ImportFiles::SAFETY );
		$job->state['archiveOwned'] = false;
		$result                     = ( new CleanStep( $this->store ) )->run( $job, new NoDeadline() );
		$this->assertSame( '', $result->message(), 'The Done message stays the last one.' );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DATABASE );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DEFERRED );
		$this->assertFileDoesNotExist( $work . '/' . $temp );
		$this->assertDirectoryDoesNotExist( $work . '/' . ImportFiles::SAFETY );
		$this->assertFileExists( $work . '/' . ImportFiles::DOWNLOAD, 'A local archive is never deleted.' );
		$job->state['archiveOwned'] = true;
		( new CleanStep( $this->store ) )->run( $job, new NoDeadline() );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DOWNLOAD );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DOWNLOAD . RangeDownloader::PART_SUFFIX );
	}

	public function test_clean_forgets_the_download_address_of_a_url_import(): void {
		$job                    = ImportJobFixture::make(
			$this->store,
			$this->dir,
			'',
			[
				'source'   => 'url',
				'location' => 'https://files.test/share/abc/site.wpress?token=tok3n',
			]
		);
		$job->state['download'] = [
			'url'  => 'https://cdn.files.test/x/site.wpress?sig=s1gn',
			'size' => 10,
		];

		$this->assertTrue( ( new CleanStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( 'https://files.test/…/site.wpress', $job->options['location'] );
		$this->assertSame( [ 'size' => 10 ], $job->state['download'] );
	}

	public function test_done_without_database_or_safety_backup(): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );

		$result = ( new DoneStep() )->run( $job, new NoDeadline() );

		$this->assertSame( 'Import complete.', $result->message() );
		$this->assertSame(
			[
				'safetyBackup' => null,
				'deactivated'  => [],
			],
			$job->state['summary']
		);
	}

	public function test_a_sites_restore_into_a_network_only_flushes_the_caches_of_the_main_site(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		Functions\expect( 'wp_cache_flush' )->once();
		Functions\when( 'wp_cache_flush_runtime' )->justReturn( true );
		Functions\expect( 'delete_post_meta_by_key' )->never();
		$job = $this->networkJob( ArchiveKind::SITES );

		$this->assertTrue( ( new PostImportStep( new Fixes() ) )->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( 1, $job->cursor['task'] );
		$this->assertArrayHasKey( 'rewrite_rules', FakeOptions::$values, 'The main site was not imported (ruling R12).' );
	}

	public function test_a_network_restore_runs_every_fix_on_the_main_site(): void {
		$job = $this->networkJob( ArchiveKind::NETWORK );
		$job->cursor['task'] = count( Fixes::TASKS ) - 1; // Only the rewrite fix is left.
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );

		$this->assertTrue( ( new PostImportStep( new Fixes() ) )->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( count( Fixes::TASKS ), $job->cursor['task'] );
		$this->assertArrayNotHasKey( 'rewrite_rules', FakeOptions::$values );
	}

	public function test_done_on_a_network_lists_the_restored_sites(): void {
		$job                        = $this->networkJob( ArchiveKind::SITES );
		$job->state['safetyBackup'] = [ 'name' => 'pre-import-20261008-000000.wpress' ];

		$result = ( new DoneStep() )->run( $job, new NoDeadline() );

		$this->assertSame( 'Import complete: 2 sites restored. The previous database is saved as pre-import-20261008-000000.wpress.', $result->message() );
		$this->assertSame(
			[
				[
					'url'     => 'https://new.test/shop',
					'created' => true,
				],
				[
					'url'     => 'https://new.test/b3',
					'created' => false,
				],
			],
			$job->state['summary']['sites']
		);
		$this->assertSame( 'pre-import-20261008-000000.wpress', $job->state['summary']['safetyBackup'] );
	}

	public function test_done_on_a_network_without_safety_backup(): void {
		$job = $this->networkJob( ArchiveKind::SITES );
		$map = BlogMap::fromArray( $job->state['blogs'] );
		$job->state['blogs'] = BlogMap::fromArray( [ $map->get( 0 ) ] )->toArray();

		$this->assertSame( 'Import complete: 1 site restored.', ( new DoneStep() )->run( $job, new NoDeadline() )->message() );
	}

	/**
	 * A swapped restore of two sites (old 2 and 3) into the network https://new.test.
	 *
	 * @param string $kind ArchiveKind value.
	 */
	private function networkJob( string $kind ): Job {
		$target  = TargetNetwork::fromArray(
			[
				'domain' => 'new.test',
				'path'   => '/',
				'scheme' => 'https',
			]
		);
		$entries = [];
		foreach ( [ 2, 3 ] as $id ) {
			$entries[] = SiteEntry::fromArray(
				[
					'BlogID'  => $id,
					'HomeURL' => 'http://old.test/b' . $id,
					'Domain'  => 'old.test',
					'Path'    => '/b' . $id . '/',
				]
			);
		}
		$map = BlogMap::plan( $kind, $entries, 'http://old.test', $target )
			->resolved(
				0,
				[
					'newId'      => 9,
					'created'    => true,
					'newHomeUrl' => 'https://new.test/shop',
				]
			)
			->resolved(
				1,
				[
					'newId'      => 3,
					'newHomeUrl' => 'https://new.test/b3',
				]
			);
		$job                       = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->state['target']      = $target->toArray();
		$job->state['kind']        = $kind;
		$job->state['blogs']       = $map->toArray();
		$job->state['hasDatabase'] = true;

		return $job;
	}
}
