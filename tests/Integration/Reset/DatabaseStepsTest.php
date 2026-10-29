<?php
/**
 * The three database steps of a reset on a real MySQL/MariaDB: build, swap, finish.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Reset;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Reset\ResetCleanup;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\Steps\DatabaseBuildStep;
use LightweightPlugins\Migration\Reset\Steps\DatabaseFinishStep;
use LightweightPlugins\Migration\Reset\Steps\DatabaseSwapStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;
use LightweightPlugins\Migration\Tests\Support\DyingConnection;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class DatabaseStepsTest extends DatabaseTestCase {

	private const NOW = 5000;

	private string $dir;

	private JobStore $store;

	private MysqliWpdb $wpdb;

	/** @var array<int, string> Run ids the finish step forgot. */
	private array $forgot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		FakeWpdb::stubCache();
		$this->dir       = TempDir::create();
		$this->store     = new JobStore( $this->dir . '/jobs' );
		$this->wpdb      = new MysqliWpdb();
		$GLOBALS['wpdb'] = $this->wpdb; // SiteLock's row.
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec(
			'CREATE TABLE wp_wc_orders (v INT)',
			"INSERT INTO wp_users (ID, user_login, user_pass) VALUES (7, 'anna', 'h')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'Live'), ('lw_migration_schedules', 'before')"
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$this->wpdb->close();
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job(): Job {
		$job = ResetJobs::create( $this->store, new SiteLock(), SiteFixture::make( $this->dir ), 'database', false, [
			'id'    => 7,
			'login' => 'anna',
		], self::NOW );

		return $this->store->load( $job->id );
	}

	/**
	 * A fake fresh install: the three core tables under the run's prefix, one fresh row.
	 *
	 * @param int $builds Counts the builds.
	 */
	private function fakeInstall( int &$builds ): \Closure {
		return function ( string $tmp ) use ( &$builds ): void {
			++$builds;
			$this->exec( ...WpCoreTables::create( $tmp ) );
			$this->exec( "INSERT INTO `{$tmp}options` (option_name, option_value) VALUES ('blogname', 'Fresh')" );
		};
	}

	private function buildStep( \Closure $install ): DatabaseBuildStep {
		return new DatabaseBuildStep(
			static fn() => self::db(),
			new TmpRun( $this->store, static fn( string $prefix ): bool => true ),
			static function ( string $tmp, Job $job ) use ( $install ): void {
				$install( $tmp );
			},
			static function (): void {},
			static fn(): array => [ 'options', 'users', 'usermeta' ]
		);
	}

	/**
	 * @param Connection|null    $db    Connection (default: the test's).
	 * @param \Closure|null      $flush fn(): void.
	 * @param array<int, string> $core  What $wpdb->tables() lists (plugins may add to it).
	 */
	private function swapStep( ?Connection $db = null, ?\Closure $flush = null, array $core = [ 'options', 'users', 'usermeta' ] ): DatabaseSwapStep {
		return new DatabaseSwapStep( $this->store, static fn() => $db ?? self::db(), new SiteLock(), static fn(): int => self::NOW, static fn(): array => $core, $flush ?? static function (): void {} );
	}

	private function killedAfterTheRename( Job $job ): Job {
		try {
			$this->swapStep(
				null,
				static function (): void {
					throw new \RuntimeException( 'Killed.' );
				}
			)->run( $job, new NoDeadline() );
			$this->fail( 'Killed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Killed.', $e->getMessage() );
		}

		return $this->store->load( $job->id ); // What the next request reads.
	}

	private function finishStep(): DatabaseFinishStep {
		return new DatabaseFinishStep(
			static fn() => self::db(),
			function ( string $run ): bool {
				$this->forgot[] = $run;
				return true;
			},
			static function (): void {}
		);
	}

	private function value( string $sql ): ?string {
		$rows = self::db()->query( $sql );

		return [] === $rows ? null : (string) array_values( $rows[0] )[0];
	}

	/** @return array<int, string> */
	private function names(): array {
		return array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
	}

	private function finish( StepInterface $step, Job $job ): void {
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
	}

	public function test_a_build_that_died_halfway_starts_over_clean(): void {
		$job    = $this->job();
		$builds = 0;
		$step   = $this->buildStep( $this->fakeInstall( $builds ) );
		$step->run( $job, new NoDeadline() ); // Reserves the run.
		$tmp = (string) $job->state['tmpPrefix'];
		$this->exec( "CREATE TABLE `{$tmp}half` (v INT)", "CREATE TABLE `{$tmp}options` (v INT)" ); // What a dying build left.
		$this->finish( $step, $job );
		$this->assertNotContains( $tmp . 'half', $this->names() );
		$this->assertSame( 'Fresh', $this->value( "SELECT option_value FROM `{$tmp}options` WHERE option_name = 'blogname'" ) );
		$this->assertSame( 1, $builds );
		$this->assertTrue( $job->cursor['built'] );
		$this->assertArrayNotHasKey( TmpRun::CANDIDATE, $job->state );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ), 'The live site is untouched until the swap.' );
	}

	public function test_a_replay_after_the_rename_finishes_without_renaming_again(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$this->finish( $this->swapStep(), $job );
		$job->state['swapped'] = false; // The request died after the RENAME, before the job was saved.
		$this->finish( $this->swapStep(), $job );
		$this->assertTrue( $job->state['swapped'] );
		$this->assertSame( 'Fresh', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
		$old = 'lwo' . substr( (string) $job->state['tmpPrefix'], 3 );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM `{$old}options` WHERE option_name = 'blogname'" ), 'Renamed once: the live rows are the old ones.' );
		$this->assertContains( $old . 'r0', $this->names(), 'wp_wc_orders was retired.' );
		$this->assertSame( [ 'wp_wc_orders' ], $job->state['retired'] );
		$this->assertSame( [
			'replaced' => 3,
			'retired'  => 1,
			'kept'     => 0,
			'views'    => [],
		], $job->state['removed']['database'] );
		$this->finish( $this->finishStep(), $job );
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, $old ) ) ) );
		$this->assertSame( [ substr( (string) $job->state['tmpPrefix'], 3, 4 ) ], $this->forgot, 'Nothing of the run is left: it is forgotten.' );
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, 'lw' ) ) ), 'Its marker went last.' );
	}

	public function test_a_request_killed_right_after_the_rename_finishes_on_the_next_run(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$saved = $this->killedAfterTheRename( $job ); // The job as the step saved it right before the RENAME.
		$this->assertSame( 'Fresh', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ), 'The RENAME ran.' );
		$this->assertArrayNotHasKey( 'swapped', $saved->state );
		$this->assertSame( [ 'wp_wc_orders' ], $saved->state['retired'], 'The retired names were saved before the RENAME.' );
		$this->assertTrue( ResetJobs::pastNoReturn( $saved, $this->store->dir( $saved->id ) ), 'The marker says so.' );

		$this->finish( $this->swapStep(), $saved );
		$this->assertTrue( $saved->state['swapped'] );
		$old = 'lwo' . substr( (string) $saved->state['tmpPrefix'], 3 );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM `{$old}options` WHERE option_name = 'blogname'" ), 'Not renamed twice.' );
		$this->assertSame( 3, $saved->state['removed']['database']['replaced'] ?? null, 'The replay records what the RENAME did.' );
		$this->finish( $this->finishStep(), $saved );
		$this->assertSame( 'Fresh', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
	}

	public function test_a_lost_lock_stops_before_the_rename_and_changes_nothing(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$this->exec( "UPDATE wp_options SET option_value = '{\"job\":\"ffffffffffffffff\",\"expires\":99999}' WHERE option_name = 'lw_migration_lock'" );
		try {
			$this->swapStep()->run( $job, new NoDeadline() );
			$this->fail( 'Lost lock.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'This reset lost the site lock, so the database was not replaced. The site was not changed.', $e->getMessage() );
		}
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
	}

	public function test_our_rows_written_between_build_and_swap_go_live(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$this->exec( "UPDATE wp_options SET option_value = 'after' WHERE option_name = 'lw_migration_schedules'" );
		$this->finish( $this->swapStep(), $job );
		$this->assertSame( 'after', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'lw_migration_schedules'" ) );
		$this->assertStringContainsString( $job->id, (string) $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'lw_migration_lock'" ), 'The current lock went live with it.' );
	}

	public function test_a_rename_that_never_ran_runs_on_replay(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$dying = new DyingConnection( self::db(), 'RENAME TABLE' );
		$dying->armAfter( 'INSERT INTO' ); // Our rows are copied, then the connection "dies" at the RENAME.
		try {
			$this->swapStep( $dying )->run( $job, new NoDeadline() );
			$this->fail( 'Died.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection lost.', $e->getMessage() );
		}
		$this->assertFileExists( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, 'Marker first.' );
		$this->assertArrayNotHasKey( 'database', (array) ( $this->store->load( $job->id )->state['removed'] ?? [] ), 'Nothing was replaced: the result names nothing.' );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
		$this->finish( $this->swapStep(), $job );
		$this->assertSame( 'Fresh', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ), 'Temp tables pending + marker: the RENAME runs now.' );
	}

	public function test_a_rename_that_cannot_run_fails_before_the_marker(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$old = 'lwo' . substr( (string) $job->state['tmpPrefix'], 3 );
		$this->exec( "CREATE TABLE `{$old}options` (v INT)" ); // A target name already taken.
		try {
			$this->swapStep()->run( $job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'The site was not changed.', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
		$this->assertFalse( ResetJobs::pastNoReturn( $job, $this->store->dir( $job->id ) ), 'Still cancellable.' );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
	}

	public function test_a_live_table_without_a_fresh_twin_is_retired(): void {
		$this->exec( 'CREATE TABLE wp_shopx_itemmeta (v VARCHAR(20))', "INSERT INTO wp_shopx_itemmeta VALUES ('customer')" );
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$this->assertSame( [ 'options', 'usermeta', 'users' ], $job->state['built'], 'What the fresh install built is recorded.' );
		$this->finish( $this->swapStep(), $job );
		$this->assertSame( [ 'wp_shopx_itemmeta', 'wp_wc_orders' ], $job->state['retired'], 'No fresh twin: not core.' );
		$this->finish( $this->finishStep(), $job );
		$this->assertNotContains( 'wp_shopx_itemmeta', $this->names() );
	}

	public function test_a_fresh_table_lost_after_the_build_stops_the_swap_before_the_marker(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$tmp = (string) $job->state['tmpPrefix'];
		$this->exec( "DROP TABLE `{$tmp}usermeta`" );
		try {
			$this->swapStep()->run( $job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( $tmp . 'usermeta', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
	}

	public function test_the_views_warning_survives_a_replay_after_the_rename(): void {
		$this->exec( 'CREATE VIEW wp_report AS SELECT option_name FROM wp_options' );
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$saved = $this->killedAfterTheRename( $job );
		$this->finish( $this->swapStep(), $saved );
		$this->assertSame( [ 'These database views were left as they are: wp_report' ], $saved->warnings );
	}

	public function test_an_old_table_another_table_holds_is_named_as_the_site_knew_it(): void {
		$this->exec(
			'CREATE TABLE wp_parent (id INT PRIMARY KEY) ENGINE=InnoDB',
			'CREATE TABLE wp_shop_options (v INT)',
			'CREATE TABLE wp_shop_posts (v INT)',
			'CREATE TABLE wp_shop_child (pid INT, FOREIGN KEY (pid) REFERENCES wp_parent (id)) ENGINE=InnoDB'
		);
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$this->finish( $this->swapStep(), $job );
		$this->finish( $this->finishStep(), $job );
		$old = 'lwo' . substr( (string) $job->state['tmpPrefix'], 3 );
		$this->assertSame( [ 'wp_parent', 'wp_wc_orders' ], $job->state['retired'] );
		$this->assertSame( [ "The table wp_parent could not be removed (another table still references it), so it was kept as {$old}r0; the daily cleanup tries again." ], $job->warnings );
	}

	public function test_a_core_table_the_build_did_not_make_stops_the_reset_with_the_site_unchanged(): void {
		$job  = $this->job();
		$step = $this->buildStep(
			function ( string $tmp ): void {
				$this->exec( ...array_slice( WpCoreTables::create( $tmp ), 0, 2 ) ); // dbDelta never throws: usermeta is just missing.
			}
		);
		$step->run( $job, new NoDeadline() );
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Incomplete.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'The fresh database could not be built completely, so the database was not reset. The site was not changed.', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'built', $job->state );
		$tmp = (string) $job->state['tmpPrefix'];
		ResetCleanup::tablesAfterFailure( $job, self::db(), 'wp_', [ 'options', 'users', 'usermeta' ], static fn( string $run ): bool => true );
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, $tmp ) ) ), 'The failure cleanup drops the fresh tables.' );
		$this->assertContains( 'wp_usermeta', $this->names() );
	}

	public function test_the_swap_refuses_a_record_without_every_core_table(): void {
		$job    = $this->job();
		$builds = 0;
		$this->finish( $this->buildStep( $this->fakeInstall( $builds ) ), $job );
		$job->state['built'] = [ 'options', 'users' ];
		try {
			$this->swapStep()->run( $job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( (string) $job->state['tmpPrefix'] . 'usermeta', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
		$this->assertSame( 'Live', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
	}

	public function test_a_site_prefix_a_reset_cannot_use_fails_with_a_readable_text(): void {
		$job  = $this->job();
		$step = $this->buildStep( static function (): void {} );
		$step->run( $job, new NoDeadline() );
		$job->state['site']['tablePrefix'] = '';
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'This site\'s table prefix cannot be used for a database reset, so the database was not reset. The site was not changed.', $e->getMessage() );
		}
	}
}
