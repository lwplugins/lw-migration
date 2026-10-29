<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Maintenance;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Maintenance\Cleanup;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class CleanupTest extends MonkeyTestCase {

	private const ROOT = 'lw-migration-0123456789ab';

	/**
	 * Scratch folder of the test.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		TempDir::remove( WP_CONTENT_DIR );
		parent::tearDown();
	}

	public function test_hook_name_matches_the_uninstall_literal(): void {
		$this->assertSame( 'lw_migration_cleanup', Cleanup::HOOK );
		$this->assertSame( 86400, Cleanup::MAX_AGE );
	}

	public function test_sweep_deletes_jobs_idle_for_a_day_and_broken_ones(): void {
		$store = new JobStore( $this->dir );
		$now   = 1000000;
		$old   = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$fresh = $store->create( 'export', [ 'a' ], [], $now - 100 );
		$done  = $store->create( 'export', [ 'a' ], [], $now - 90000 );

		$done->status = Job::DONE;
		$store->save( $done );
		$broken = $store->create( 'export', [ 'a' ], [], $now );
		file_put_contents( $store->dir( $broken->id ) . '/job.json', 'not json' );
		$busy = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$lock = $store->acquire( $busy->id );
		$this->assertNotNull( $lock );

		$deleted = Cleanup::sweep( $store, $now );
		sort( $deleted );
		$expected = [ $old->id, $done->id, $broken->id ];
		sort( $expected );

		$this->assertSame( $expected, $deleted );
		$this->assertDirectoryDoesNotExist( $store->dir( $old->id ) );
		$this->assertDirectoryExists( $store->dir( $fresh->id ) );
		$this->assertDirectoryExists( $store->dir( $busy->id ), 'A job someone is running is never deleted.' );
		$store->release( $lock );
	}

	public function test_sweep_never_deletes_a_running_job(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$owner = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$alive = $store->create( 'export', [ 'a' ], [], $now - 90000 );

		$alive->status    = Job::RUNNING;
		$alive->updatedAt = $now - 90000;
		$store->save( $alive );
		$dead            = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$dead->status    = Job::RUNNING;
		$dead->updatedAt = $now - 90000;
		$store->save( $dead );
		touch( $store->dir( $dead->id ) . '/job.json', $now - SiteLock::TTL - 10 );

		$deleted = Cleanup::sweep( $store, $now, static fn(): ?string => $owner->id );

		$this->assertSame( [ $dead->id ], $deleted );
		$this->assertDirectoryExists( $store->dir( $owner->id ), 'The job holding the site lock is never deleted.' );
		$this->assertDirectoryExists( $store->dir( $alive->id ), 'A running job touched within the lock TTL is never deleted.' );
	}

	public function test_a_job_refreshed_before_the_lock_is_taken_is_kept(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$job   = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$calls = 0;

		$deleted = Cleanup::sweep(
			$store,
			$now,
			static function () use ( $store, $job, $now, &$calls ): ?string {
				if ( 2 === ++$calls ) {
					$job->updatedAt = $now;
					$store->save( $job );
				}
				return null;
			}
		);

		$this->assertSame( 2, $calls, 'Checked again once the job lock is held.' );
		$this->assertSame( [], $deleted );
		$this->assertDirectoryExists( $store->dir( $job->id ) );
	}

	public function test_a_job_that_took_the_site_lock_before_the_job_lock_is_kept(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$job   = $store->create( 'export', [ 'a' ], [], $now - 90000 );
		$calls = 0;

		$deleted = Cleanup::sweep(
			$store,
			$now,
			static function () use ( $job, &$calls ): ?string {
				return 2 === ++$calls ? $job->id : null;
			}
		);

		$this->assertSame( [], $deleted );
		$this->assertDirectoryExists( $store->dir( $job->id ) );
	}

	public function test_leftover_id_folders_without_job_json_are_removed_when_old(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$old   = $this->dir . '/0123456789abcdef';
		$fresh = $this->dir . '/1123456789abcdef';
		$other = $this->dir . '/notajob';
		$keep  = $this->dir . '/target';
		foreach ( [ $old, $fresh, $other, $keep ] as $dir ) {
			mkdir( $dir );
			file_put_contents( $dir . '/archive.part', 'data' );
		}
		symlink( $keep, $this->dir . '/2123456789abcdef' );
		foreach ( [ $old, $other, $keep ] as $dir ) {
			touch( $dir, $now - Cleanup::MAX_AGE - 10 );
		}

		$deleted = Cleanup::sweep( $store, $now );

		$this->assertSame( [ '0123456789abcdef' ], $deleted );
		$this->assertDirectoryDoesNotExist( $old );
		$this->assertDirectoryExists( $fresh );
		$this->assertDirectoryExists( $other );
		$this->assertTrue( is_link( $this->dir . '/2123456789abcdef' ), 'A symlink is never deleted.' );
		$this->assertFileExists( $keep . '/archive.part' );
	}

	public function test_temps_are_swept_when_the_job_sweep_fails_and_the_log_has_no_absolute_paths(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$services = new Services();
		$services->storage()->ensure();
		$now   = time();
		$root  = $services->storage()->root();
		$stale = $services->storage()->backupsDir() . '/' . TempName::name();
		file_put_contents( $stale, 'data' );
		touch( $stale, $now - SiteLock::TTL - 10 );
		$job = $services->jobs()->create( 'export', [ 'a' ], [], $now - 90000 );

		$GLOBALS['wpdb'] = new class( $root ) {
			public string $options = 'wp_options';
			public function __construct( private string $root ) {
			}
			public function prepare( string $query, string ...$args ): string {
				return $query . ' ' . implode( ' ', $args );
			}
			public function get_var( string $query ): ?string {
				if ( str_ends_with( $query, ' ' . Options::BACKUP_DIR ) ) {
					return null; // Services::storage() reads the backups folder setting first (no chosen folder).
				}
				throw new \RuntimeException( 'Database gone while reading ' . $this->root . '/jobs' );
			}
		};
		$log      = $this->dir . '/php-errors.log';
		$previous = ini_set( 'error_log', $log );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertFileDoesNotExist( $stale, 'The temp sweep still runs.' );
		$this->assertDirectoryExists( $services->jobs()->dir( $job->id ) );
		$written = (string) file_get_contents( $log );
		$this->assertStringContainsString( 'RuntimeException', $written );
		$this->assertStringNotContainsString( $root, $written );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $written );
	}

	public function test_sweep_temps_deletes_only_our_stale_temps(): void {
		$now   = time();
		$stale = TempName::name();
		$fresh = TempName::name();
		$kept  = TempName::name();
		$link  = TempName::name();
		$dir   = TempName::name();
		$others = [ 'site.wpress', '.lwm-0123456789abcdef.part.bak', '.lwm-0123456789ABCDEF.part', '.lwm-0123.part', 'x' . $stale ];
		foreach ( array_merge( [ $stale, $fresh, $kept ], $others ) as $name ) {
			file_put_contents( $this->dir . '/' . $name, 'data' );
			touch( $this->dir . '/' . $name, $now - SiteLock::TTL - 10 );
		}
		touch( $this->dir . '/' . $fresh, $now - 10 );
		$target = $this->dir . '/target.bin';
		file_put_contents( $target, 'keep' );
		touch( $target, $now - SiteLock::TTL - 10 );
		symlink( $target, $this->dir . '/' . $link );
		mkdir( $this->dir . '/' . $dir );
		touch( $this->dir . '/' . $dir, $now - SiteLock::TTL - 10 );

		$deleted = Cleanup::sweepTemps( $this->dir, $now, [ $kept ] );

		$this->assertSame( [ $stale ], $deleted );
		$this->assertFileDoesNotExist( $this->dir . '/' . $stale );
		foreach ( array_merge( [ $fresh, $kept, $dir, 'target.bin' ], $others ) as $name ) {
			$this->assertFileExists( $this->dir . '/' . $name, $name );
		}
		$this->assertTrue( is_link( $this->dir . '/' . $link ), 'A symlink is never deleted.' );
	}

	public function test_sweep_temps_in_a_read_only_folder_prints_no_warning(): void {
		$stale = TempName::name();
		file_put_contents( $this->dir . '/' . $stale, 'data' );
		touch( $this->dir . '/' . $stale, time() - SiteLock::TTL - 10 );
		chmod( $this->dir, 0555 );
		if ( is_writable( $this->dir ) ) {
			chmod( $this->dir, 0755 );
			$this->markTestSkipped( 'Running with privileges that ignore folder permissions.' );
		}
		$warnings = [];
		$level    = error_reporting();
		set_error_handler(
			static function ( int $no, string $text ) use ( &$warnings, $level ): bool {
				if ( error_reporting() === $level ) {
					$warnings[] = $text; // Not silenced: PHP would print it with the path.
				}
				return true;
			}
		);
		try {
			$deleted = Cleanup::sweepTemps( $this->dir, time(), [] );
		} finally {
			restore_error_handler();
			chmod( $this->dir, 0755 );
		}

		$this->assertSame( [], $deleted );
		$this->assertSame( [], $warnings, 'A warning would carry the folder\'s path.' );
	}

	public function test_sweep_temps_of_a_missing_folder_is_empty(): void {
		$this->assertSame( [], Cleanup::sweepTemps( $this->dir . '/missing', time(), [] ) );
	}

	public function test_clean_keeps_the_lock_holder_and_its_staged_temp(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db       = FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$services = new Services();
		$services->storage()->ensure();
		$jobs  = $services->jobs();
		$now   = time();
		$owner = $jobs->create( 'export', [ 'a' ], [], $now - 90000 );
		$temp  = TempName::name();

		$owner->cursor = [ 'temp' => $temp ];
		$jobs->save( $owner );
		$stale = TempName::name();
		foreach ( [ $temp, $stale ] as $name ) {
			$path = $services->storage()->backupsDir() . '/' . $name;
			file_put_contents( $path, 'data' );
			touch( $path, $now - SiteLock::TTL - 10 );
		}
		$idle = $jobs->create( 'export', [ 'a' ], [], $now - 90000 );
		$this->assertTrue( $services->lock()->acquire( $owner->id, $now ) );
		$this->assertNotEmpty( $db->rows );
		$log      = $this->dir . '/php-errors.log';
		$previous = ini_set( 'error_log', $log );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$written = (string) file_get_contents( $log );
		$this->assertStringContainsString( 'the table sweep failed', $written, 'No database link here: logged, the other sweeps still ran.' );
		$this->assertStringNotContainsString( $services->storage()->root(), $written );
		$this->assertDirectoryExists( $jobs->dir( $owner->id ) );
		$this->assertDirectoryDoesNotExist( $jobs->dir( $idle->id ) );
		$this->assertFileExists( $services->storage()->backupsDir() . '/' . $temp );
		$this->assertFileDoesNotExist( $services->storage()->backupsDir() . '/' . $stale );
	}

	public function test_stale_temps_are_swept_in_every_backups_folder(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$chosen = $this->dir . '/chosen';
		$moving = $this->dir . '/moving';
		mkdir( $chosen );
		mkdir( $moving );
		FakeWpdb::install(
			[
				Options::STORAGE_DIR => self::ROOT,
				Options::BACKUP_DIR  => (string) json_encode( [ 'path' => $chosen, 'moving' => $moving, 'marker' => '0123456789abcdef' ], JSON_UNESCAPED_SLASHES ),
			]
		);
		$services = new Services();
		$services->storage()->ensure();
		$now   = time();
		$named = TempName::name();
		$move  = $services->jobs()->create( 'move', [ 'move' ], [], $now );

		$move->cursor = [ 'temp' => $named ];
		$services->jobs()->save( $move );
		$folders = [ $services->storage()->defaultBackupsDir(), $chosen, $moving ];
		$stale   = [];
		foreach ( $folders as $folder ) {
			$stale[ $folder ] = TempName::name();
			foreach ( [ $stale[ $folder ] => 'old', 'site.wpress' => 'old', TempName::name() => 'new' ] as $name => $age ) {
				file_put_contents( $folder . '/' . $name, 'data' );
				touch( $folder . '/' . $name, 'old' === $age ? $now - SiteLock::TTL - 10 : $now );
			}
		}
		file_put_contents( $moving . '/' . $named, 'data' );
		touch( $moving . '/' . $named, $now - SiteLock::TTL - 10 );
		$previous = ini_set( 'error_log', $this->dir . '/php-errors.log' );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		foreach ( $folders as $folder ) {
			$this->assertFileDoesNotExist( $folder . '/' . $stale[ $folder ], $folder );
			$this->assertFileExists( $folder . '/site.wpress' );
			$this->assertCount( 2 + ( $moving === $folder ? 1 : 0 ), array_diff( (array) scandir( $folder ), [ '.', '..', '.htaccess', 'web.config', 'index.php', 'index.html' ] ), 'The fresh temp and the backup stay.' );
		}
		$this->assertFileExists( $moving . '/' . $named, 'A temp a live move job names stays.' );
	}

	public function test_the_daily_run_prunes_expired_transfer_links(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$now    = time();
		$shares = new Shares();
		$gone   = $shares->create( Backup::OWN, 'site-20261009-120000-001.wpress', 1, $now - 1000, 300 );
		$live   = $shares->create( Backup::OWN, 'site-20261009-120000-001.wpress', 1, $now - 999, 3600 );
		$log    = $this->dir . '/php-errors.log';
		$this->assertCount( 2, $shares->all(), 'The expired link is still stored.' );

		$previous = ini_set( 'error_log', $log );
		try {
			Cleanup::clean( new Services(), $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertSame( [ $live['id'] ], array_column( $shares->all(), 'id' ) );
		$this->assertNotSame( $gone['id'], $live['id'] );
	}

	public function test_the_daily_run_prunes_expired_connection_rows(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db    = FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$now   = time();
		$owner = [
			'id'    => 7,
			'login' => 'boss',
		];
		$box   = new SecretBox( str_repeat( 'k', 32 ) );
		$links = new Links( null, $box );
		foreach ( [ 'aaaaaaaaaaaaaaaa' => false, 'bbbbbbbbbbbbbbbb' => true ] as $id => $confirmed ) {
			$links->add( $this->link( $id, $owner ), random_bytes( 32 ), $now - 1000, $confirmed );
		}
		( new Codes( null, $box ) )->issue( $owner, [ 'pull' ], $now - 1000 );
		( new Nonces() )->remember( '0123456789abcdef', sprintf( '%032x', 1 ), $now - 1000, $now - 1000 );

		$this->runClean( $now );

		$this->assertSame( [ 'bbbbbbbbbbbbbbbb' ], array_column( $links->all( $now ), 'id' ), 'The confirmed link stays.' );
		$this->assertArrayNotHasKey( Options::LINK_CODE, $db->rows );
		$this->assertSame( [], (array) json_decode( (string) $db->value( Options::LINK_NONCES ), true ) );
	}

	public function test_a_failing_connection_sweep_does_not_stop_the_others(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$db    = FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$now   = time();
		$owner = [
			'id'    => 7,
			'login' => 'boss',
		];
		$box   = new SecretBox( str_repeat( 'k', 32 ) );
		$links = new Links( null, $box );
		$links->add( $this->link( 'aaaaaaaaaaaaaaaa', $owner ), random_bytes( 32 ), $now - 1000, false );
		( new Codes( null, $box ) )->issue( $owner, [ 'pull' ], $now - 1000 );
		( new Nonces() )->remember( '0123456789abcdef', sprintf( '%032x', 1 ), $now - 1000, $now - 1000 );

		// The first write of the links sweep dies; the code and nonce sweeps still run.
		$db->beforeWrite = static function () {
			throw new \RuntimeException( 'boom' );
		};
		$this->runClean( $now );

		$this->assertArrayNotHasKey( Options::LINK_CODE, $db->rows );
		$this->assertSame( [], (array) json_decode( (string) $db->value( Options::LINK_NONCES ), true ) );
		$this->assertSame( 1, count( (array) json_decode( (string) $db->value( Options::LINKS ), true ) ), 'The failed sweep left its row.' );
	}

	public function test_a_failing_code_sweep_and_a_failing_nonce_sweep_do_not_stop_the_rest(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		$owner = [
			'id'    => 7,
			'login' => 'boss',
		];
		$box   = new SecretBox( str_repeat( 'k', 32 ) );
		$now   = time();
		foreach ( [ 'code', 'nonces' ] as $failing ) {
			$db = FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
			( new Links( null, $box ) )->add( $this->link( 'aaaaaaaaaaaaaaaa', $owner ), random_bytes( 32 ), $now - 1000, false );
			( new Codes( null, $box ) )->issue( $owner, [ 'pull' ], $now - 1000 );
			( new Nonces() )->remember( '0123456789abcdef', sprintf( '%032x', 1 ), $now - 1000, $now - 1000 );
			// Writes in sweep order: links, code, nonces. Let the chosen one die.
			$skip = 'code' === $failing ? 1 : 2;
			$db->beforeWrite = null;
			$count           = 0;
			$arm             = static function () use ( $db, &$arm, &$count, $skip ): void {
				if ( $count++ === $skip ) {
					throw new \RuntimeException( 'boom' );
				}
				$db->beforeWrite = $arm;
			};
			$db->beforeWrite = $arm;

			$this->runClean( $now );

			$this->assertSame( 'code' === $failing, isset( $db->rows[ Options::LINK_CODE ] ), $failing . ': the failed sweep left its row' );
			$this->assertSame( 'nonces' === $failing, '{}' !== $db->value( Options::LINK_NONCES ), $failing );
			$this->assertSame( '[]', (string) $db->value( Options::LINKS ), $failing . ': the links sweep ran' );
		}
	}

	public function test_the_connection_rows_are_pruned_even_without_a_storage_folder(): void {
		$db    = FakeWpdb::install();
		$now   = time();
		$owner = [
			'id'    => 7,
			'login' => 'boss',
		];
		$box   = new SecretBox( str_repeat( 'k', 32 ) );
		( new Links( null, $box ) )->add( $this->link( 'aaaaaaaaaaaaaaaa', $owner ), random_bytes( 32 ), $now - 1000, false );
		( new Codes( null, $box ) )->issue( $owner, [ 'pull' ], $now - 1000 );
		$db->failOn = Options::STORAGE_DIR; // The storage folder name cannot be saved: the file sweeps stop early.

		$this->runClean( $now );

		$this->assertArrayNotHasKey( Options::STORAGE_DIR, $db->rows );
		$this->assertSame( '[]', (string) $db->value( Options::LINKS ) );
		$this->assertArrayNotHasKey( Options::LINK_CODE, $db->rows );
	}

	/**
	 * @param string                        $id    Link id.
	 * @param array{id: int, login: string} $owner Owner.
	 * @return array<string, mixed>
	 */
	private function link( string $id, array $owner ): array {
		return [
			'id'         => $id,
			'direction'  => Links::IN,
			'address'    => 'https://a.example',
			'name'       => 'Site A',
			'allow'      => [ 'pull' ],
			'createdBy'  => $owner,
			'pairedFrom' => '203.0.113.5',
		];
	}

	private function runClean( int $now ): void {
		$log      = $this->dir . '/php-errors.log';
		$previous = ini_set( 'error_log', $log );
		try {
			Cleanup::clean( new Services(), $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}
	}

	public function test_clean_without_a_storage_folder_does_nothing(): void {
		$db             = FakeWpdb::install();
		$db->failWrites = true;

		Cleanup::clean( new Services(), time() );

		$this->assertSame( [], $db->rows );
		$this->assertSame( [], is_dir( WP_CONTENT_DIR ) ? array_diff( (array) scandir( WP_CONTENT_DIR ), [ '.', '..' ] ) : [], 'Nothing is created on disk.' );
	}

	public function test_schedule_only_when_missing(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Cleanup::HOOK );
		Cleanup::schedule();

		Functions\when( 'wp_next_scheduled' )->justReturn( 12345 );
		Cleanup::schedule();
	}

	public function test_a_subsite_schedules_nothing_and_runs_nothing(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( false );
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_schedule_event' )->never();
		Functions\expect( 'get_option' )->never(); // Not even the storage folder is read.

		Cleanup::schedule();
		Cleanup::run();
	}

	public function test_the_main_site_of_a_network_schedules_the_cleanup(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Cleanup::HOOK );

		Cleanup::schedule();
	}

	public function test_register_and_unschedule(): void {
		Functions\expect( 'add_action' )->once()->with( Cleanup::HOOK, [ Cleanup::class, 'run' ] );
		Functions\expect( 'add_action' )->once()->with( 'init', [ Cleanup::class, 'schedule' ] );
		Cleanup::register();

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cleanup::HOOK );
		Cleanup::unschedule();
	}

	public function test_sweep_runs_the_discard_hook_before_each_folder_goes(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$this->import( $store, $now - 90000, 'lwtab12_', Job::FAILED );
		$store->create( 'export', [ 'a' ], [], $now - 90000 );
		$this->import( $store, $now - 60, 'lwtcd34_', Job::FAILED );
		$seen = [];

		$deleted = Cleanup::sweep(
			$store,
			$now,
			null,
			function ( string $id ) use ( $store, &$seen ): void {
				$this->assertDirectoryExists( $store->dir( $id ) );
				$seen[] = $id;
			}
		);

		sort( $deleted );
		sort( $seen );
		$this->assertCount( 2, $deleted );
		$this->assertSame( $deleted, $seen );
	}

	public function test_clean_discards_the_safety_temp_of_a_crashed_import_and_keeps_a_named_one(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$services = new Services();
		$services->storage()->ensure();
		$jobs    = $services->jobs();
		$now     = time();
		$backups = $services->storage()->backupsDir();
		$crashed = $this->import( $jobs, $now - 90000, 'lwtab12_', Job::RUNNING );
		$failed  = $this->import( $jobs, $now - 60, 'lwtcd34_', Job::FAILED );
		$gone    = TempName::name();
		$named   = TempName::name();

		$crashed->cursor = [ 'safety' => [ 'cursor' => [ 'temp' => $gone ] ] ];
		$jobs->save( $crashed );
		$failed->cursor = [ 'safety' => [ 'cursor' => [ 'temp' => $named ] ] ];
		$jobs->save( $failed );
		touch( $jobs->dir( $crashed->id ) . '/job.json', $now - 90000 );
		foreach ( [ $gone, $named ] as $name ) {
			file_put_contents( $backups . '/' . $name, 'data' );
		}
		touch( $backups . '/' . $named, $now - SiteLock::TTL - 10 );
		$log      = $this->dir . '/php-errors.log';
		$previous = ini_set( 'error_log', $log );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertDirectoryDoesNotExist( $jobs->dir( $crashed->id ) );
		$this->assertFileDoesNotExist( $backups . '/' . $gone, 'Discarded before the folder went.' );
		$this->assertFileExists( $backups . '/' . $named, 'A job still names it (cursor.safety.cursor.temp).' );
	}

	public function test_clean_removes_the_empty_reservation_of_a_swept_job_and_never_a_backup(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$services = new Services();
		$services->storage()->ensure();
		$jobs     = $services->jobs();
		$now      = time();
		$backups  = $services->storage()->backupsDir();
		$export   = $jobs->create( 'export', [ 'a' ], [], $now - 90000 );
		$import   = $this->import( $jobs, $now - 90000, 'lwtab12_', Job::FAILED );
		$export->cursor = [ 'reserving' => 'site-20260101-000000-001.wpress' ];
		$jobs->save( $export );
		$import->cursor = [ 'safety' => [ 'cursor' => [ 'reserving' => 'pre-import-20260101-000000.wpress' ] ] ];
		$jobs->save( $import );
		foreach ( [ $export, $import ] as $job ) {
			touch( $jobs->dir( $job->id ) . '/job.json', $now - 90000 );
		}
		touch( $backups . '/site-20260101-000000-001.wpress' );
		file_put_contents( $backups . '/pre-import-20260101-000000.wpress', 'a published backup' );
		$previous = ini_set( 'error_log', $this->dir . '/php-errors.log' );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertDirectoryDoesNotExist( $jobs->dir( $export->id ) );
		$this->assertFileDoesNotExist( $backups . '/site-20260101-000000-001.wpress', 'The empty reservation went with its job.' );
		$this->assertFileExists( $backups . '/pre-import-20260101-000000.wpress', 'A name holding bytes is a backup.' );
	}

	public function test_a_running_resets_backup_temp_is_kept_and_an_abandoned_reservation_removed(): void {
		$temp = TempName::name();
		$a    = TempName::name();
		$b    = TempName::name();
		$this->assertSame( [ 'temps' => [ $temp ], 'reserving' => [] ], NestedExport::namedTemps( [ 'backup' => [ 'cursor' => [ 'temp' => $temp ] ] ] ) );
		$this->assertSame( [ 'temps' => [ $a, $b ], 'reserving' => [ 'pre-reset-20261007-090000.wpress' ] ], NestedExport::namedTemps( [ 'safety' => [ 'cursor' => [ 'temp' => $a ] ], 'backup' => [ 'cursor' => [ 'temp' => $b, 'reserving' => 'pre-reset-20261007-090000.wpress' ] ] ] ) );

		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		$services = new Services();
		$services->storage()->ensure();
		$jobs      = $services->jobs();
		$now       = time();
		$backups   = $services->storage()->backupsDir();
		$running   = $jobs->create( 'reset', [ 'reset_backup', 'reset_media' ], [ 'type' => 'media' ], $now - 60 );
		$abandoned = $jobs->create( 'reset', [ 'reset_backup', 'reset_media' ], [ 'type' => 'media' ], $now - 90000 );
		$reserved  = 'pre-reset-20261007-090000.wpress';

		$running->status = Job::RUNNING;
		$running->cursor = [ 'backup' => [ 'cursor' => [ 'temp' => $temp ] ] ];
		$jobs->save( $running );
		$abandoned->status = Job::RUNNING;
		$abandoned->cursor = [ 'backup' => [ 'cursor' => [ 'reserving' => $reserved ] ] ];
		$jobs->save( $abandoned );
		touch( $jobs->dir( $abandoned->id ) . '/job.json', $now - 90000 );
		file_put_contents( $backups . '/' . $temp, 'half a backup before a reset' );
		touch( $backups . '/' . $temp, $now - SiteLock::TTL - 10 );
		touch( $backups . '/' . $reserved );
		$previous = ini_set( 'error_log', $this->dir . '/php-errors.log' );
		try {
			Cleanup::clean( $services, $now );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertFileExists( $backups . '/' . $temp, 'A running reset still names it (cursor.backup.cursor.temp).' );
		$this->assertDirectoryDoesNotExist( $jobs->dir( $abandoned->id ) );
		$this->assertFileDoesNotExist( $backups . '/' . $reserved, 'The empty reservation went with its job: never a never-pruned empty backup.' );
	}
	/**
	 * @param JobStore $store  Job store.
	 * @param int      $at     Created/updated at.
	 * @param string   $tmp    state.tmpPrefix.
	 * @param string   $status Job status.
	 */
	private function import( JobStore $store, int $at, string $tmp, string $status = Job::PENDING ): Job {
		$job = $store->create( 'import', [ 'a' ], [], $at );

		$job->state['tmpPrefix'] = $tmp;
		$job->status             = $status;
		$store->save( $job );

		return $job;
	}
}
