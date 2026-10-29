<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\Steps\MediaStep;
use LightweightPlugins\Migration\Reset\Steps\NotAsRoot;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class MediaStepTest extends MonkeyTestCase {

	private string $root;

	private string $uploads;

	private JobStore $store;

	/** @var array<int, array<int, int>> Attachment ids by site (0 = the single site). */
	private array $ids = [];

	/** @var array<int, string> */
	private array $log = [];

	/** @var array<string, mixed> The job as the step last saved it. */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();
		$this->root    = TempDir::create();
		$this->uploads = $this->root . '/wp-content/uploads';
		$this->store   = new JobStore( $this->root . '/jobs' );
		foreach ( [ '.htaccess', 'index.php', '2026/10/a.jpg', '2026/10/a-150x150.jpg', 'builder/css/post-1.css', 'sites/2/2026/10/b.jpg' ] as $file ) {
			@mkdir( dirname( $this->uploads . '/' . $file ), 0755, true );
			file_put_contents( $this->uploads . '/' . $file, 'x' );
		}
		Functions\when( 'is_multisite' )->justReturn( false );
		FakeWpdb::install();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		parent::tearDown();
	}

	private static function asRoot(): bool {
		return 0 === ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1 );
	}

	private function step( array $sites = [], ?string $unsafe = null, array $sealedPaths = [], ?string $uploads = null, ?\Closure $site = null ): MediaStep {
		$current = 0;

		return new MediaStep(
			function ( int $after, int $limit ) use ( &$current ): array {
				return array_slice( array_values( array_filter( $this->ids[ $current ] ?? [], static fn( int $id ): bool => $id > $after ) ), 0, $limit );
			},
			function ( int $id ) use ( &$current ): bool {
				$this->log[]           = $current . ':' . $id;
				$this->ids[ $current ] = array_values( array_diff( $this->ids[ $current ], [ $id ] ) );
				return true;
			},
			static function ( int $site, \Closure $work ) use ( &$current ) {
				$current = $site;
				try {
					return $work();
				} finally {
					$current = 0;
				}
			},
			static fn( int $after ): array => array_slice( array_values( array_filter( $sites, static fn( int $id ): bool => $id > $after ) ), 0, 100 ),
			static fn(): array => FolderIdentity::ids( $sealedPaths ),
			fn(): string => $uploads ?? $this->uploads,
			static fn(): ?string => $unsafe,
			$site,
			function ( Job $job ): void {
				$this->saved = $job->toArray();
			}
		);
	}

	private function job( string $type = 'media' ): Job {
		$job = ResetJobs::create( $this->store, new SiteLock(), SiteFixture::make( $this->root ), $type, false, [ 'id' => 1, 'login' => 'admin' ], 1791363600 );

		return $this->store->load( $job->id );
	}

	private function finish( MediaStep $step, Job $job ): void {
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
	}

	public function test_attachments_go_first_then_every_upload_but_the_root_protection(): void {
		$this->ids = [ 0 => [ 3, 9, 12 ] ];
		mkdir( $this->uploads . '/shop_uploads', 0755 );
		file_put_contents( $this->uploads . '/shop_uploads/.htaccess', 'deny from all' );
		file_put_contents( $this->uploads . '/shop_uploads/order.pdf', 'x' );
		$job = $this->job();
		$this->finish( $this->step(), $job );
		$this->assertSame( [ '0:3', '0:9', '0:12' ], $this->log );
		$this->assertSame( [ '.', '..', '.htaccess', 'index.php' ], scandir( $this->uploads ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/shop_uploads', 'An emptied folder loses its deny file last.' );
		$this->assertSame( 3, $job->state['removed']['media']['attachments'] );
		$this->assertGreaterThanOrEqual( 6, $job->state['removed']['media']['files'] );
		$this->assertSame( [], $job->warnings );
	}

	public function test_batches_resume_after_the_last_deleted_id(): void {
		$this->ids = [ 0 => range( 1, 450 ) ];
		$job       = $this->job();
		$step      = $this->step();
		$step->run( $job, new CountdownDeadline( 3 ) );
		$job = Job::fromArray( json_decode( (string) json_encode( $job->toArray() ), true ) ); // As the Runner saves it.
		$this->finish( $step, $job );
		$this->assertCount( 450, array_unique( $this->log ), 'Each attachment once.' );
		$this->assertCount( 450, $this->log );
		$this->assertSame( 450, $job->state['removed']['media']['attachments'] );
	}

	public function test_on_a_network_every_site_loses_its_attachments(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->ids = [ 1 => [ 5 ], 2 => [ 6, 7 ] ];
		$job       = $this->job();
		$this->finish( $this->step( [ 1, 2 ] ), $job );
		$this->assertSame( [ '1:5', '2:6', '2:7' ], $this->log );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/sites' );
	}

	public function test_a_site_cut_by_the_deadline_resumes_its_own_batch(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->ids = [
			1 => [ 5, 8, 11 ],
			2 => [ 3, 4 ],
		];
		$job       = $this->job();
		$step      = $this->step( [ 1, 2 ] );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 1 ) )->isDone() );
		$this->assertSame( [ '1:5', '1:8' ], $this->log );
		$this->finish( $step, $job );
		$this->assertSame( [ '1:5', '1:8', '1:11', '2:3', '2:4' ], $this->log, 'Site 1 finished before site 2, each id once.' );
	}

	public function test_our_backups_in_uploads_are_never_entered(): void {
		mkdir( $this->uploads . '/lw-migration-backups-0123456789ab', 0750 );
		file_put_contents( $this->uploads . '/lw-migration-backups-0123456789ab/site.wpress', 'backup' );
		$job = $this->job();
		$this->finish( $this->step( [], null, [ $this->uploads . '/lw-migration-backups-0123456789ab' ] ), $job );
		$this->assertFileExists( $this->uploads . '/lw-migration-backups-0123456789ab/site.wpress' );
		$this->assertSame( 1, $job->state['removed']['media']['kept'] );
		$this->assertSame( [ 'uploads: 1 folder holding backups was kept.' ], $job->warnings );
	}

	public function test_our_storage_inside_uploads_is_kept_under_another_spelling(): void {
		mkdir( $this->uploads . '/lw-migration-0123456789ab/backups', 0750, true );
		file_put_contents( $this->uploads . '/lw-migration-0123456789ab/backups/a.wpress', 'backup' );
		symlink( $this->uploads . '/lw-migration-0123456789ab', $this->root . '/storage-alias' );
		$job = $this->job();
		$this->finish( $this->step( [], null, [ $this->root . '/storage-alias' ] ), $job );
		$this->assertFileExists( $this->uploads . '/lw-migration-0123456789ab/backups/a.wpress', 'Found by identity, not by name.' );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/2026' );
	}

	public function test_a_linked_uploads_root_is_emptied_but_kept(): void {
		rename( $this->uploads, $this->root . '/shared-uploads' );
		symlink( $this->root . '/shared-uploads', $this->uploads );
		mkdir( $this->root . '/elsewhere', 0755 );
		file_put_contents( $this->root . '/elsewhere/keep.jpg', 'x' );
		symlink( $this->root . '/elsewhere', $this->root . '/shared-uploads/linked-dir' );
		$job = $this->job();
		$this->finish( $this->step(), $job );
		$this->assertTrue( is_link( $this->uploads ) );
		$this->assertSame( [ '.', '..', '.htaccess', 'index.php' ], scandir( $this->root . '/shared-uploads' ) );
		$this->assertFileExists( $this->root . '/elsewhere/keep.jpg', 'A link inside goes as a link.' );
	}

	public function test_uploads_that_became_unsafe_stop_before_any_file_goes(): void {
		$this->ids = [ 0 => [ 1 ] ];
		$job       = $this->job();
		try {
			$this->finish( $this->step( [], 'uploads_unsafe' ), $job );
			$this->fail( 'Refused.' );
		} catch ( JobException $e ) {
			$this->assertSame( [], $this->log, 'Not even the media library.' );
			$this->assertFileExists( $this->uploads . '/2026/10/a.jpg' );
			$this->assertStringContainsString( 'uploads folder holds', $e->getMessage() );
		}
	}

	public function test_as_root_or_as_an_unknown_user_nothing_is_touched_on_any_request(): void {
		$this->ids = [ 0 => [ 1, 2 ] ];
		foreach ( [ 0, null ] as $euid ) {
			foreach ( [ [ 'phase' => 'attachments' ], [ 'phase' => 'files' ] ] as $cursor ) {
				$job         = $this->job();
				$job->cursor = $cursor; // A request in the middle of the step, too.
				try {
					( new NotAsRoot( $this->step(), new RootGuard( $euid ) ) )->run( $job, new NoDeadline() ); // The pipeline wraps every step.
					$this->fail( 'Refused.' );
				} catch ( JobException $e ) {
					$this->assertStringContainsString( 'never runs as root', $e->getMessage() );
				}
				FakeWpdb::install();
				$this->store = new JobStore( $this->root . '/jobs-' . uniqid() );
			}
		}
		$this->assertSame( [], $this->log );
		$this->assertFileExists( $this->uploads . '/2026/10/a.jpg' );
	}

	public function test_an_attachment_core_refuses_is_counted_and_passed(): void {
		$this->ids = [ 0 => [ 1, 2 ] ];
		$job       = $this->job();
		$step      = new MediaStep(
			fn( int $after, int $limit ): array => array_values( array_filter( [ 1, 2 ], static fn( int $id ): bool => $id > $after ) ),
			static fn( int $id ): bool => 2 === $id,
			static fn( int $site, \Closure $work ) => $work(),
			static fn(): array => [],
			static fn(): array => [],
			fn(): string => $this->uploads,
			static fn(): ?string => null,
			null
		);
		$this->finish( $step, $job );
		$this->assertSame( 1, $job->state['removed']['media']['attachments'] );
		$this->assertSame( [ '1 media item could not be deleted from the media library.' ], $job->warnings );
	}

	public function test_a_huge_tree_of_unremovable_files_still_ends_each_tick_and_moves_on(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		mkdir( $this->uploads . '/locked', 0755 );
		for ( $i = 0; $i < 60; $i++ ) {
			file_put_contents( $this->uploads . '/locked/f' . $i . '.jpg', 'x' );
		}
		chmod( $this->uploads . '/locked', 0555 );
		$job   = $this->job();
		$step  = $this->step();
		$ticks = 0;
		try {
			do {
				AttemptGuard::record( $job, MediaStep::KEY ); // As the Runner does before every call.
				$result = $step->run( $job, new CountdownDeadline( 5 ) );
				++$ticks;
			} while ( ! $result->isDone() && $ticks < 100 );
		} finally {
			chmod( $this->uploads . '/locked', 0755 );
		}
		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( AttemptGuard::MAX_ATTEMPTS, $ticks, 'Many passes without a removal, none stopped as stuck.' );
		$this->assertSame( 60, $job->state['removed']['media']['failed'], 'Each unremovable file counted once.' );
		$this->assertSame( [ '60 uploaded files could not be removed (permissions).' ], $job->warnings );
	}

	public function test_a_core_folder_mounted_inside_uploads_is_never_emptied(): void {
		mkdir( $this->uploads . '/bindp/p', 0755, true );
		file_put_contents( $this->uploads . '/bindp/p/p.php', '<?php' );
		$job                              = $this->job();
		$job->state['site']['pluginsDir'] = $this->uploads . '/bindp'; // As a bind mount of the plugins folder (same file system).
		$this->finish( $this->step(), $job );
		$this->assertFileExists( $this->uploads . '/bindp/p/p.php' );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/2026' );
		$this->assertSame( [ 'uploads: 1 folder of the site itself (WordPress, content, plugins or themes) was left as it is.' ], $job->warnings );
	}

	public function test_core_is_told_which_folders_it_never_deletes_in(): void {
		mkdir( $this->uploads . '/backups-x', 0750 );
		mkdir( $this->root . '/wp-content/plugins', 0755 );
		$this->ids = [ 0 => [ 4 ] ];
		$seen      = [];
		$step      = new MediaStep(
			static fn( int $after, int $limit ): array => $after < 4 ? [ 4 ] : [],
			static function ( int $id, array $kept ) use ( &$seen ): bool {
				$seen = $kept;
				return true;
			},
			static fn( int $site, \Closure $work ) => $work(),
			static fn(): array => [],
			fn(): array => FolderIdentity::ids( [ $this->uploads . '/backups-x' ] ),
			fn(): string => $this->uploads,
			static fn(): ?string => null,
			null
		);
		$this->finish( $step, $this->job() );
		$this->assertArrayHasKey( (string) FolderIdentity::of( $this->uploads . '/backups-x' ), $seen, 'Our backups.' );
		$this->assertArrayHasKey( (string) FolderIdentity::of( $this->root . '/wp-content/plugins' ), $seen, 'The site\'s own folders.' );
	}

	public function test_the_kept_folders_come_from_the_site_as_it_is_now(): void {
		mkdir( $this->uploads . '/bindp/p', 0755, true );
		file_put_contents( $this->uploads . '/bindp/p/p.php', '<?php' );
		$this->ids = [ 0 => [ 1 ] ];
		$job       = $this->job(); // Its snapshot names the usual plugins folder.
		$this->finish( $this->step( [], null, [], null, fn(): Site => SiteFixture::make( $this->root, [ 'pluginsDir' => $this->uploads . '/bindp' ] ) ), $job );
		$this->assertFileExists( $this->uploads . '/bindp/p/p.php', 'The plugins folder moved since the job started.' );
	}

	public function test_an_attachment_that_ended_the_request_is_passed_with_a_warning(): void {
		$this->ids = [ 0 => [ 1, 2, 3 ] ];
		$job       = $this->job();
		$dies      = true;
		$tries     = [];
		$step      = new MediaStep(
			fn( int $after, int $limit ): array => array_values( array_filter( $this->ids[0], static fn( int $id ): bool => $id > $after ) ),
			function ( int $id ) use ( &$dies, &$tries ): bool {
				$tries[] = $id;
				if ( 2 === $id && $dies ) {
					throw new \Error( 'The request died inside core.' ); // As a fatal error or a timeout ends it.
				}
				$this->ids[0] = array_values( array_diff( $this->ids[0], [ $id ] ) );
				return true;
			},
			static fn( int $site, \Closure $work ) => $work(),
			static fn(): array => [],
			static fn(): array => [],
			fn(): string => $this->uploads,
			static fn(): ?string => null,
			null,
			function ( Job $job ): void {
				$this->saved = $job->toArray();
			}
		);
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The request died.' );
		} catch ( \Error $e ) {
			$dies = false;
		}
		$job = Job::fromArray( json_decode( (string) json_encode( $this->saved ), true ) ); // The next request reads what was saved.
		$this->finish( $step, $job );
		$this->assertSame( [ 1, 2, 3 ], $tries, 'The one that ended the request is never tried again.' );
		$this->assertSame( 2, $job->state['removed']['media']['attachments'] );
		$this->assertSame( [ '1 media item could not be deleted from the media library.' ], $job->warnings );
	}

	public function test_an_attachment_in_flight_on_a_site_that_is_gone_skips_nothing_on_the_next(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->ids   = [ 2 => [ 5, 60 ] ];
		$job         = $this->job();
		$job->cursor = [
			'phase'    => 'attachments',
			'sites'    => [
				'last' => 0,
				'done' => false,
			],
			'site'     => 1,
			'after'    => 49,
			'inflight' => 50, // Site 1 ended a request at id 50, then site 1 was removed.
		];
		$this->finish( $this->step( [ 2 ] ), $job );
		$this->assertSame( [ '2:5', '2:60' ], $this->log );
		$this->assertSame( [ '1 media item could not be deleted from the media library.' ], $job->warnings );
	}

	public function test_backups_folders_of_the_snapshot_and_of_the_live_site_are_both_kept(): void {
		foreach ( [ 'old-backups', 'new-backups' ] as $dir ) {
			mkdir( $this->uploads . '/' . $dir, 0750 );
			file_put_contents( $this->uploads . '/' . $dir . '/site.wpress', 'backup' );
		}
		$job                              = $this->job();
		$job->state['site']['backupDirs'] = [ $this->uploads . '/old-backups' ];
		$step                             = new MediaStep(
			static fn( int $after, int $limit ): array => [],
			static fn( int $id, array $kept ): bool => true,
			static fn( int $site, \Closure $work ) => $work(),
			static fn(): array => [],
			static fn( Site $site ): array => FolderIdentity::ids( $site->backupDirs ),
			fn(): string => $this->uploads,
			static fn(): ?string => null,
			fn(): Site => SiteFixture::make( $this->root, [ 'backupDirs' => [ $this->uploads . '/new-backups' ] ] )
		);
		$this->finish( $step, $job );
		$this->assertFileExists( $this->uploads . '/old-backups/site.wpress', 'The snapshot\'s folder (the setting changed meanwhile).' );
		$this->assertFileExists( $this->uploads . '/new-backups/site.wpress', 'The live folder.' );
	}
}
