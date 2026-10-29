<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\LoadedElsewhere;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Reset\Steps\PluginsStep;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class PluginsStepTest extends MonkeyTestCase {

	private string $root;

	private JobStore $store;

	/** @var array<int, string> */
	private array $calls = [];

	/** @var array<int, string> Folders the step must never enter. */
	private array $sealedPaths = [];

	protected function setUp(): void {
		parent::setUp();
		$this->root  = TempDir::create();
		$this->store = new JobStore( $this->root . '/jobs' );
		foreach ( [ 'lw-migration/lw-migration.php', 'akismet/akismet.php', 'shop/shop.php', 'shop/inc/x.php' ] as $file ) {
			@mkdir( dirname( $this->root . '/wp-content/plugins/' . $file ), 0755, true );
			file_put_contents( $this->root . '/wp-content/plugins/' . $file, '<?php' );
		}
		file_put_contents( $this->root . '/wp-content/plugins/hello.php', '<?php' );
		file_put_contents( $this->root . '/wp-content/advanced-cache.php', '<?php' );
		file_put_contents( $this->root . '/wp-content/object-cache.php', '<?php' );
		mkdir( $this->root . '/lang/plugins', 0755, true );
		foreach ( [ 'akismet-hu_HU.mo', 'akismet-hu_HU.po', 'akismet-hu_HU-0123456789abcdef0123456789abcdef.json', 'akismetx-hu_HU.mo' ] as $file ) {
			file_put_contents( $this->root . '/lang/plugins/' . $file, 'x' );
		}
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( '_get_dropins' )->justReturn( [ 'advanced-cache.php' => [], 'object-cache.php' => [], 'db.php' => [] ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		parent::tearDown();
	}

	private function step( array $installed, bool $cli = false, ?\Closure $uninstallRan = null, ?\Closure $onUninstall = null, ?RootGuard $root = null ): PluginsStep {
		return new PluginsStep(
			$this->store,
			static fn(): array => $installed,
			fn(): array => FolderIdentity::ids( $this->sealedPaths ),
			$this->root . '/lang',
			function ( array $plugins, ?int $site ): void {
				$this->calls[] = 'deactivate:' . implode( ',', $plugins ) . ':' . var_export( $site, true );
			},
			function ( string $plugin ) use ( $onUninstall ): void {
				$this->calls[] = 'uninstall:' . $plugin;
				if ( null !== $onUninstall ) {
					$onUninstall();
				}
			},
			static fn( Site $site, array $plugins ): array => LoadedElsewhere::find( $plugins, LoadedElsewhere::loaders( $site->contentDir, $site->muPluginsDir, array_keys( _get_dropins() ), '' ) ),
			static fn(): bool => $cli,
			$uninstallRan ?? static fn(): bool => false,
			$root ?? new RootGuard( 33 )
		);
	}

	private function job(): Job {
		$job = ResetJobs::create( $this->store, new SiteLock(), SiteFixture::make( $this->root ), 'plugins', false, [ 'id' => 1, 'login' => 'admin' ], 1791363600 );

		return $this->store->load( $job->id );
	}

	private function runAll( PluginsStep $step, Job $job ): void {
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
	}

	/** @return array<int, string> */
	private function uninstalls(): array {
		return array_values( array_filter( $this->calls, static fn( string $c ): bool => str_starts_with( $c, 'uninstall:' ) ) );
	}

	public function test_every_plugin_but_this_one_is_deactivated_uninstalled_and_deleted(): void {
		FakeWpdb::install();
		$step = $this->step( [ 'akismet/akismet.php', 'hello.php', 'lw-migration/lw-migration.php', 'shop/shop.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( 'deactivate:akismet/akismet.php,hello.php,shop/shop.php:NULL', $this->calls[0] );
		$this->assertSame( [ 'uninstall:akismet/akismet.php', 'uninstall:hello.php', 'uninstall:shop/shop.php' ], array_slice( $this->calls, 1 ) );
		$this->assertSame( [ '.', '..', 'lw-migration' ], scandir( $this->root . '/wp-content/plugins' ), 'Only this plugin is left; hello.php went, the folder stayed.' );
		$this->assertSame( [ '.', '..', 'akismetx-hu_HU.mo' ], scandir( $this->root . '/lang/plugins' ), 'Only its own translations.' );
		$this->assertSame( [ 'count' => 3, 'names' => [ 'akismet/akismet.php', 'hello.php', 'shop/shop.php' ], 'leftovers' => 0 ], $job->state['removed']['plugins'] );
		$this->assertSame( [ 'advanced-cache.php', 'object-cache.php' ], $job->state['dropIns'], 'Only the drop-ins that are there.' );
		$this->assertSame( [], $job->warnings );
	}

	public function test_a_linked_plugin_folder_is_left_with_a_warning(): void {
		FakeWpdb::install();
		mkdir( $this->root . '/shared-shop', 0755 );
		file_put_contents( $this->root . '/shared-shop/keep.php', '<?php' );
		symlink( $this->root . '/shared-shop', $this->root . '/wp-content/plugins/linked' );
		$step = $this->step( [ 'linked/linked.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertFileExists( $this->root . '/shared-shop/keep.php' );
		$this->assertTrue( is_link( $this->root . '/wp-content/plugins/linked' ) );
		$this->assertNotContains( 'uninstall:linked/linked.php', $this->calls, 'Not uninstalled either: its files stay.' );
		$this->assertSame( [ 'The plugin linked/linked.php was deactivated but not deleted: its folder is a link to another place.' ], $job->warnings );
	}

	public function test_a_plugin_folder_that_is_this_plugin_under_another_name_is_never_deleted(): void {
		FakeWpdb::install();
		symlink( $this->root . '/wp-content/plugins/lw-migration', $this->root . '/wp-content/plugins/lw-copy' );
		$step = $this->step( [ 'lw-copy/lw-migration.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/lw-migration/lw-migration.php' );
		$this->assertSame( [], $this->uninstalls() );
	}

	public function test_this_plugin_under_a_case_variant_of_its_folder_is_found_by_identity(): void {
		$probe = $this->root . '/wp-content/plugins/LW-MIGRATION';
		if ( ! is_dir( $probe ) ) {
			$this->markTestSkipped( 'Needs a case-insensitive file system (one folder, two spellings, no link).' );
		}
		FakeWpdb::install();
		$step = $this->step( [ 'LW-Migration/lw-migration.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/lw-migration/lw-migration.php' );
		$this->assertSame( [], $this->uninstalls() );
		$this->assertSame( [ 'The plugin LW-Migration/lw-migration.php was deactivated but not deleted: it is LW Migration itself under another name.' ], $job->warnings );
	}

	public function test_another_copy_of_this_plugin_is_deleted_without_its_uninstall_routine(): void {
		FakeWpdb::install();
		foreach ( [ 'lw-migration-main/lw-migration.php', 'lw-migration-main/uninstall.php', 'renamed/main.php', 'renamed/uninstall.php' ] as $file ) {
			@mkdir( dirname( $this->root . '/wp-content/plugins/' . $file ), 0755, true );
			file_put_contents( $this->root . '/wp-content/plugins/' . $file, '<?php' );
		}
		file_put_contents( $this->root . '/wp-content/plugins/renamed/main.php', "<?php\r\n/**\r\n * Plugin Name: Renamed\r\n * Text Domain: lw-migration\r\n */\r\n" ); // Saved on Windows.
		$step = $this->step( [ 'akismet/akismet.php', 'lw-migration-main/lw-migration.php', 'renamed/main.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'uninstall:akismet/akismet.php' ], $this->uninstalls(), 'Our own uninstall.php would delete the running job and every setting.' );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/plugins/lw-migration-main' );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/plugins/renamed' );
		$this->assertFileExists( $this->root . '/wp-content/plugins/lw-migration/lw-migration.php' );
		$this->assertSame(
			[
				'The plugin lw-migration-main/lw-migration.php is another copy of LW Migration: its files were deleted without running its uninstall routine, which would remove the data of this reset.',
				'The plugin renamed/main.php is another copy of LW Migration: its files were deleted without running its uninstall routine, which would remove the data of this reset.',
			],
			$job->warnings
		);
	}

	public function test_a_backups_folder_inside_a_plugin_is_kept_with_a_warning(): void {
		FakeWpdb::install();
		mkdir( $this->root . '/wp-content/plugins/shop/backups' );
		file_put_contents( $this->root . '/wp-content/plugins/shop/backups/site.wpress', 'x' );
		$this->sealedPaths = [ $this->root . '/wp-content/plugins/shop/backups' ];
		$job               = $this->job();
		$this->runAll( $this->step( [ 'shop/shop.php' ] ), $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/shop/backups/site.wpress' );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/shop/shop.php' );
		$this->assertSame( [ 'shop/shop.php: 1 folder holding backups was kept.' ], $job->warnings );
	}

	public function test_as_root_or_as_an_unknown_user_no_plugin_is_touched(): void {
		foreach ( [ 0, null ] as $euid ) {
			FakeWpdb::install();
			$job = $this->job();
			try {
				$this->runAll( $this->step( [ 'akismet/akismet.php' ], root: new RootGuard( $euid ) ), $job );
				$this->fail( 'Refused.' );
			} catch ( JobException $e ) {
				$this->assertStringContainsString( 'never runs as root', $e->getMessage() );
			}
		}
		$this->assertSame( [], $this->calls, 'Not even deactivated.' );
		$this->assertFileExists( $this->root . '/wp-content/plugins/akismet/akismet.php' );
	}

	public function test_an_uninstall_routine_that_throws_is_told_and_the_plugin_still_goes(): void {
		FakeWpdb::install();
		$step = $this->step( [ 'akismet/akismet.php', 'shop/shop.php' ], cli: true, onUninstall: static function (): void {
			throw new \Error( 'Call to undefined function x()' );
		} );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'uninstall:akismet/akismet.php', 'uninstall:shop/shop.php' ], $this->uninstalls() );
		$this->assertDirectoryDoesNotExist( $this->root . '/wp-content/plugins/akismet' );
		$this->assertSame(
			[
				'The uninstall routine of akismet/akismet.php failed, so it was deleted without it.',
				'The uninstall routine of shop/shop.php failed, so it was deleted without it.',
			],
			$job->warnings
		);
	}

	public function test_two_plugins_in_one_folder_both_uninstall_before_the_folder_goes(): void {
		FakeWpdb::install();
		file_put_contents( $this->root . '/wp-content/plugins/shop/shop-addon.php', '<?php' );
		$present = [];
		$folder  = $this->root . '/wp-content/plugins/shop';
		$step    = $this->step( [ 'shop/shop.php', 'shop/shop-addon.php' ], cli: true, onUninstall: static function () use ( &$present, $folder ): void {
			$present[] = is_dir( $folder );
		} );
		$job     = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'uninstall:shop/shop-addon.php', 'uninstall:shop/shop.php' ], $this->uninstalls() );
		$this->assertSame( [ true, true ], $present, 'The second routine still finds its files.' );
		$this->assertDirectoryDoesNotExist( $folder );
		$this->assertSame( 2, $job->state['removed']['plugins']['count'] );
	}

	public function test_a_name_that_is_no_plain_plugin_file_is_never_deleted(): void {
		FakeWpdb::install();
		$step = $this->step( [ '../outside/x.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'The plugin ../outside/x.php was deactivated but not deleted: its file name is not a plain plugin file.' ], $job->warnings );
		$this->assertSame( [], $this->uninstalls() );
	}

	public function test_a_plugin_whose_uninstall_killed_the_request_is_deleted_without_it(): void {
		FakeWpdb::install();
		$step        = $this->step( [ 'akismet/akismet.php' ] );
		$job         = $this->job();
		$job->cursor = [
			'phase'   => 'delete',
			'list'    => [ 'akismet/akismet.php' ],
			'next'    => 0,
			'started' => 'akismet/akismet.php',
		];
		$this->runAll( $step, $job );
		$this->assertNotContains( 'uninstall:akismet/akismet.php', $this->calls );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/akismet' );
		$this->assertSame( [ 'The uninstall routine of akismet/akismet.php stopped the previous request, so it was deleted without running it again.' ], $job->warnings );
	}

	public function test_the_started_plugin_is_saved_before_its_uninstall_runs(): void {
		FakeWpdb::install();
		$job  = $this->job();
		$seen = null;
		$step = new PluginsStep(
			$this->store,
			static fn(): array => [ 'akismet/akismet.php' ],
			static fn(): array => [],
			$this->root . '/lang',
			static function (): void {},
			function () use ( $job, &$seen ): void {
				$seen = $this->store->load( $job->id )->cursor['started'] ?? null;
			},
			null,
			null,
			null,
			new RootGuard( 33 )
		);
		$this->runAll( $step, $job );
		$this->assertSame( 'akismet/akismet.php', $seen );
	}

	public function test_on_a_network_every_site_is_deactivated_in_pages(): void {
		FakeWpdb::install()->blogIds = [ 3, 1, 2 ];
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$step = $this->step( [ 'akismet/akismet.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'deactivate:akismet/akismet.php:NULL', 'deactivate:akismet/akismet.php:1', 'deactivate:akismet/akismet.php:2', 'deactivate:akismet/akismet.php:3' ], array_slice( $this->calls, 0, 4 ) );
	}

	public function test_a_plugin_a_drop_in_loads_is_kept(): void {
		FakeWpdb::install();
		mkdir( $this->root . '/wp-content/plugins/dbx', 0755 );
		file_put_contents( $this->root . '/wp-content/plugins/dbx/dbx.php', '<?php' );
		file_put_contents( $this->root . '/wp-content/db.php', "<?php require WP_CONTENT_DIR . '/plugins/dbx/load.php';" );
		Functions\when( '_get_dropins' )->justReturn( [ 'db.php' => [] ] );
		$step = $this->step( [ 'akismet/akismet.php', 'dbx/dbx.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/dbx/dbx.php' );
		$this->assertSame( 'deactivate:akismet/akismet.php:NULL', $this->calls[0], 'Not even deactivated.' );
		$this->assertContains( 'dbx/dbx.php was kept: db.php loads it directly.', $job->warnings );
		$this->assertSame(
			[
				[
					'plugin' => 'dbx/dbx.php',
					'by'     => 'db.php',
				],
			],
			$job->state['keptPlugins']
		);
	}

	public function test_sqlite_integration_is_never_touched(): void {
		FakeWpdb::install();
		mkdir( $this->root . '/wp-content/plugins/sqlite-database-integration', 0755 );
		file_put_contents( $this->root . '/wp-content/plugins/sqlite-database-integration/load.php', '<?php' );
		$step = $this->step( [ 'sqlite-database-integration/load.php' ] );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/sqlite-database-integration/load.php' );
		$this->assertSame( [], array_filter( $this->calls, static fn( string $c ): bool => str_contains( $c, 'sqlite' ) ) );
	}

	public function test_one_uninstall_routine_per_web_process(): void {
		FakeWpdb::install();
		$ran  = false;
		$step = $this->step( [ 'akismet/akismet.php', 'shop/shop.php' ], cli: false, uninstallRan: static function () use ( &$ran ): bool {
			return $ran;
		}, onUninstall: static function () use ( &$ran ): void {
			$ran = true;
		} );
		$job  = $this->job();
		while ( 0 === count( $this->uninstalls() ) ) {
			$step->run( $job, new NoDeadline() );
		}
		$result = $step->run( $job, new NoDeadline() );
		$this->assertFalse( $result->isDone(), 'A second routine waits for the next request.' );
		$this->assertTrue( $result->endsTick(), 'The runner ends the tick instead of calling it again.' );
		$this->assertCount( 1, $this->uninstalls() );
		$ran = false; // The next request.
		do {
			$result = $step->run( $job, new NoDeadline() );
			$ran    = false;
		} while ( ! $result->isDone() );
		$this->assertContains( 'uninstall:shop/shop.php', $this->calls );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/shop' );
	}

	public function test_the_cli_runs_the_next_routine_in_the_same_process(): void {
		FakeWpdb::install();
		$step = $this->step( [ 'akismet/akismet.php', 'shop/shop.php' ], cli: true, uninstallRan: static fn(): bool => true, onUninstall: static function (): void {
			trigger_error( 'Constant WP_UNINSTALL_PLUGIN already defined', E_USER_WARNING ); // What a second define() raises.
		} );
		$job  = $this->job();
		$this->runAll( $step, $job );
		$this->assertSame( [ 'uninstall:akismet/akismet.php', 'uninstall:shop/shop.php' ], $this->uninstalls() );
	}

	public function test_the_cli_handler_passes_every_other_warning_on(): void {
		FakeWpdb::install();
		$seen = [];
		set_error_handler(
			static function ( int $no, string $message ) use ( &$seen ): bool {
				$seen[] = $message;
				return true;
			}
		);
		try {
			$step = $this->step( [ 'akismet/akismet.php' ], cli: true, onUninstall: static function (): void {
				trigger_error( 'Something else went wrong', E_USER_WARNING );
			} );
			$this->runAll( $step, $this->job() );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( [ 'Something else went wrong' ], $seen );
	}

	public function test_a_core_folder_mounted_inside_a_plugin_is_kept_with_a_warning(): void {
		FakeWpdb::install();
		$job                                = $this->job();
		$job->state['site']['muPluginsDir'] = $this->root . '/wp-content/plugins/shop/inc'; // As a bind mount of the mu-plugins folder.
		$this->runAll( $this->step( [ 'shop/shop.php' ] ), $job );
		$this->assertFileExists( $this->root . '/wp-content/plugins/shop/inc/x.php' );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/shop/shop.php' );
		$this->assertSame( [ 'shop/shop.php: 1 folder of the site itself (WordPress, content, plugins or themes) was left as it is.' ], $job->warnings );
	}

	public function test_a_plugin_folder_of_unremovable_files_still_ends_each_tick_and_moves_on(): void {
		if ( 0 === ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1 ) ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		FakeWpdb::install();
		$locked = $this->root . '/wp-content/plugins/akismet/locked';
		mkdir( $locked, 0755 );
		for ( $i = 0; $i < 60; $i++ ) {
			file_put_contents( $locked . '/f' . $i . '.php', '<?php' );
		}
		chmod( $locked, 0555 );
		$step  = $this->step( [ 'akismet/akismet.php', 'lw-migration/lw-migration.php' ] );
		$job   = $this->job();
		$ticks = 0;
		try {
			do {
				AttemptGuard::record( $job, PluginsStep::KEY ); // As the Runner does before every call.
				$result = $step->run( $job, new CountdownDeadline( 5 ) );
				++$ticks;
			} while ( ! $result->isDone() && $ticks < 100 );
		} finally {
			chmod( $locked, 0755 );
		}
		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( AttemptGuard::MAX_ATTEMPTS, $ticks, 'Many passes without a removal, none stopped as stuck.' );
		$this->assertFileDoesNotExist( $this->root . '/wp-content/plugins/akismet/akismet.php' );
		$this->assertFileExists( $locked . '/f0.php' );
		$this->assertArrayNotHasKey( 'pass', $job->cursor, 'The folder\'s passes are forgotten once it is done.' );
		$this->assertSame( 1, $job->state['removed']['plugins']['leftovers'], 'Counted as deleted with files left.' );
	}
}
