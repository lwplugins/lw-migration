<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class UninstallTest extends MonkeyTestCase {

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_removes_settings_and_jobs_but_keeps_backups(): void {
		$root = WP_CONTENT_DIR . '/lw-migration-0123456789ab';
		mkdir( $root . '/jobs/0123456789abcdef', 0755, true );
		mkdir( $root . '/backups', 0755, true );
		file_put_contents( $root . '/jobs/0123456789abcdef/job.json', '{}' );
		file_put_contents( $root . '/backups/site.wpress', 'x' );
		$this->seedOptions( 'lw-migration-0123456789ab' );

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
		$this->assertArrayNotHasKey( Options::SHARES, FakeOptions::$values, 'The transfer links stop working.' );
		$this->assertDirectoryDoesNotExist( $root . '/jobs' );
		$this->assertFileExists( $root . '/backups/site.wpress' );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$query = $GLOBALS['wpdb']->queries[0];
		$this->assertStringStartsWith( 'DELETE FROM wp_options WHERE option_name LIKE ', $query );
		foreach ( [ Options::DUMP_EXCLUDED_PREFIXES[1], Options::DUMP_EXCLUDED_PREFIXES[2] ] as $prefix ) {
			$this->assertStringContainsString( "'" . addcslashes( $prefix, '_%\\' ) . "%'", $query );
		}
		$this->assertStringContainsString( "'" . addcslashes( Options::SCHEDULE_LOG_PREFIX, '_%\\' ) . "%'", $query );
	}

	public function test_removes_the_connection_rows_and_contacts_no_other_site(): void {
		$this->seedOptions( null );
		foreach ( [ 'wp_remote_request', 'wp_remote_get', 'wp_remote_post', 'wp_safe_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post' ] as $function ) {
			Functions\expect( $function )->never();
		}
		$this->assertSame( [ 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces' ], [ Options::LINKS, Options::LINK_CODE, Options::LINK_NONCES ] );
		$this->assertArrayHasKey( Options::LINKS, FakeOptions::$values );

		$this->runUninstall();

		foreach ( [ 'lw_migration_links', 'lw_migration_link_code', 'lw_migration_link_nonces' ] as $name ) {
			$this->assertArrayNotHasKey( $name, FakeOptions::$values, 'The credentials and the replay cache go with the plugin.' );
		}
		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
	}

	public function test_removes_the_reset_password_failures(): void {
		$this->seedOptions( null );
		$this->assertSame( 'lw_migration_reset_tries', Options::RESET_TRIES );
		$this->assertContains( Options::RESET_TRIES, Options::all() );
		$this->assertArrayHasKey( Options::RESET_TRIES, FakeOptions::$values );

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
	}

	public function test_removes_the_destination_profiles_secrets_and_cloud_copies(): void {
		$this->seedOptions( null );
		foreach ( [ Options::DESTINATIONS, Options::DESTINATION_SECRETS, Options::CLOUD_COPIES ] as $name ) {
			$this->assertArrayHasKey( $name, FakeOptions::$values );
		}

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values, 'The sealed credentials go with the plugin.' );
	}

	public function test_removes_the_run_logs_of_the_stored_schedules(): void {
		$this->seedOptions( null );
		FakeOptions::$values[ Options::SCHEDULES ]                        = [ [ 'id' => '0a1b2c3d' ], [ 'id' => '../evil' ], 'x' ];
		FakeOptions::$values[ Options::SCHEDULE_LOG_PREFIX . '0a1b2c3d' ] = [ [ 'status' => 'done' ] ];

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
	}

	public function test_the_log_prefix_matches_only_run_log_rows(): void {
		$this->seedOptions( null );

		$this->runUninstall();

		$query   = $GLOBALS['wpdb']->queries[0];
		$matches = [];
		preg_match_all( "/LIKE '((?:[^'\\\\]|\\\\.)*)'/", $query, $matches );
		$patterns = array_map(
			static fn( string $like ): string => '/^' . preg_replace_callback(
				'/\\\\(.)|%|_|[^\\\\%_]+/',
				static fn( array $m ): string => isset( $m[1] ) && '' !== $m[1] ? preg_quote( $m[1], '/' ) : ( '%' === $m[0] ? '.*' : ( '_' === $m[0] ? '.' : preg_quote( $m[0], '/' ) ) ),
				$like
			) . '\z/s',
			$matches[1]
		);
		$deleted = static function ( string $name ) use ( $patterns ): bool {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $name ) ) {
					return true;
				}
			}
			return false;
		};

		$this->assertCount( 3, $patterns );
		$this->assertTrue( $deleted( Options::SCHEDULE_LOG_PREFIX . '0a1b2c3d' ) );
		$this->assertTrue( $deleted( '_transient_lw_migration_secret_x' ) );
		foreach ( [ 'lw_migration_schedule_logX', 'lw_migration_schedulesXlogX', 'lw_migration_schedules', 'lw_migration_schedules_backup', 'some_other_plugin_lw_migration_schedule_log_x', '_transient_lw_migrationX' ] as $foreign ) {
			$this->assertFalse( $deleted( $foreign ), $foreign . ' is not ours.' );
		}
	}

	public function test_a_storage_name_with_a_trailing_newline_is_not_trusted(): void {
		$name = "lw-migration-0123456789ab\n";
		mkdir( WP_CONTENT_DIR . '/' . $name . '/jobs/0123456789abcdef', 0755, true );
		$this->seedOptions( $name );

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
		$this->assertDirectoryExists( WP_CONTENT_DIR . '/' . $name . '/jobs/0123456789abcdef' );
	}

	public function test_a_link_inside_jobs_is_removed_without_touching_its_target(): void {
		$root    = WP_CONTENT_DIR . '/lw-migration-0123456789ab';
		$outside = WP_CONTENT_DIR . '/outside';
		mkdir( $root . '/jobs/0123456789abcdef', 0755, true );
		mkdir( $outside, 0755, true );
		file_put_contents( $outside . '/keep.txt', 'keep' );
		symlink( $outside, $root . '/jobs/0123456789abcdef/link' );
		$this->seedOptions( 'lw-migration-0123456789ab' );

		$this->runUninstall();

		$this->assertFalse( is_link( $root . '/jobs/0123456789abcdef/link' ) );
		$this->assertDirectoryDoesNotExist( $root . '/jobs' );
		$this->assertSame( 'keep', file_get_contents( $outside . '/keep.txt' ) );
	}

	public function test_without_the_storage_option_no_folder_is_touched(): void {
		$jobs = WP_CONTENT_DIR . '/lw-migration-0123456789ab/jobs/0123456789abcdef';
		mkdir( $jobs, 0755, true );
		$this->seedOptions( null );

		$this->runUninstall();

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
		$this->assertDirectoryExists( $jobs );
	}

	public function test_a_linked_storage_root_is_not_followed(): void {
		$outside = WP_CONTENT_DIR . '/outside';
		mkdir( $outside . '/jobs/0123456789abcdef', 0755, true );
		symlink( $outside, WP_CONTENT_DIR . '/lw-migration-0123456789ab' );
		$this->seedOptions( 'lw-migration-0123456789ab' );

		$this->runUninstall();

		$this->assertDirectoryExists( $outside . '/jobs/0123456789abcdef' );
	}

	public function test_a_folder_it_cannot_list_stops_the_removal(): void {
		$locked = WP_CONTENT_DIR . '/lw-migration-0123456789ab/jobs/0123456789abcdef';
		mkdir( $locked, 0755, true );
		file_put_contents( $locked . '/job.json', '{}' );
		chmod( $locked, 0300 );
		$this->seedOptions( 'lw-migration-0123456789ab' );
		$warnings = [];
		set_error_handler(
			static function ( int $errno, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;
				return true;
			}
		);
		try {
			$this->runUninstall();
		} finally {
			restore_error_handler();
			chmod( $locked, 0755 );
		}

		$this->assertFileExists( $locked . '/job.json' );
		$this->assertNotEmpty( $warnings );
		foreach ( $warnings as $warning ) {
			$this->assertStringNotContainsString( $locked . '//', $warning, 'No recursion into the unlistable folder.' );
		}
	}

	public function test_on_a_network_the_main_site_options_go_and_stray_subsite_events_too(): void {
		$this->seedOptions( null );
		$log = [];
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( false ); // The request runs on site 3.
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'switch_to_blog' )->alias(
			static function ( int $id ) use ( &$log ): bool {
				$log[] = 'switch ' . $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			static function () use ( &$log ): bool {
				$log[] = 'restore';
				return true;
			}
		);
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ) use ( &$log ): array {
				$log[] = 'sites ' . $args['offset'];
				$all   = [ 2, 3, 5 ];
				return array_slice( array_values( array_diff( $all, $args['site__not_in'] ) ), $args['offset'], $args['number'] );
			}
		);
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( string $hook ) use ( &$log ): int {
				$log[] = 'clear ' . $hook;
				return 0;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( string $hook ) use ( &$log ): int {
				$log[] = 'unschedule ' . $hook;
				return 0;
			}
		);

		$this->runUninstall(
			false,
			static function () use ( &$log ): void {
				$log[] = 'query';
			}
		);

		$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
		$main = [ 'clear lw_migration_cleanup', 'unschedule lw_migration_schedule', 'unschedule lw_migration_schedule_continue' ];
		$this->assertSame(
			array_merge(
				[ 'switch 1' ],
				$main,
				[ 'query', 'restore', 'sites 0' ],
				[ 'switch 2' ],
				$main,
				[ 'restore', 'switch 3' ],
				$main,
				[ 'restore', 'switch 5' ],
				$main,
				[ 'restore' ]
			),
			$log
		);
	}

	public function test_the_backups_folder_setting_goes_and_the_chosen_folder_stays_untouched(): void {
		$base   = TempDir::create();
		$chosen = $base . '/chosen';
		try {
			BackupDirRules::prepare( $chosen, '0123456789abcdef' );
			file_put_contents( $chosen . '/site-20261009-120000-001.wpress', 'backup' );
			$before = $this->snapshot( $chosen );
			$this->seedOptions( 'lw-migration-0123456789ab' );
			FakeOptions::$values[ Options::BACKUP_DIR ] = '{"path":"' . $chosen . '","moving":null,"marker":"0123456789abcdef"}';

			$this->runUninstall();

			$this->assertArrayNotHasKey( Options::BACKUP_DIR, FakeOptions::$values );
			$this->assertSame( [ 'unrelated' => 1 ], FakeOptions::$values );
			$this->assertSame( $before, $this->snapshot( $chosen ) );
			$this->assertCount( 8, $before, 'The folder, the backup, the marker and five deny files.' );
		} finally {
			TempDir::remove( $base );
		}
	}

	/**
	 * @return array<string, string> File name => mode, mtime and content hash.
	 */
	private function snapshot( string $dir ): array {
		clearstatcache();
		$files = [];
		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $name ) {
			$path           = $dir . '/' . $name;
			$files[ $name ] = sprintf( '%o %d %s', fileperms( $path ), filemtime( $path ), md5_file( $path ) );
		}
		$files['.'] = sprintf( '%o %d', fileperms( $dir ), filemtime( $dir ) );

		return $files;
	}

	private function seedOptions( ?string $storageName ): void {
		$values = [ 'unrelated' => 1 ];
		foreach ( Options::all() as $name ) {
			$values[ $name ] = 'x';
		}
		$values[ Options::STORAGE_DIR ] = $storageName;
		if ( null === $storageName ) {
			unset( $values[ Options::STORAGE_DIR ] );
		}
		FakeOptions::install( $values );
	}

	/**
	 * @param bool          $singleSite Stub a single site with one removal of each event.
	 * @param \Closure|null $onQuery    fn( string $sql ): void, told of every query.
	 */
	private function runUninstall( bool $singleSite = true, ?\Closure $onQuery = null ): void {
		if ( $singleSite ) {
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'lw_migration_cleanup' );
			Functions\expect( 'wp_unschedule_hook' )->once()->with( 'lw_migration_schedule' );
			Functions\expect( 'wp_unschedule_hook' )->once()->with( 'lw_migration_schedule_continue' );
		}
		$GLOBALS['wpdb']          = new class() {
			public ?\Closure $onQuery = null;
			public string $options    = 'wp_options';
			/** @var array<int, string> */
			public array $queries = [];
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}
			/**
			 * @param mixed ...$args Placeholder values.
			 */
			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
			public function query( string $sql ): int {
				$this->queries[] = $sql;
				if ( null !== $this->onQuery ) {
					( $this->onQuery )( $sql );
				}
				return 0;
			}
		};
		$GLOBALS['wpdb']->onQuery = $onQuery;
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lw-migration/lw-migration.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
