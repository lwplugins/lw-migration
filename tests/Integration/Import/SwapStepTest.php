<?php
/**
 * The swap step on a real MySQL/MariaDB: the imported tables go live in one RENAME.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class SwapStepTest extends DatabaseTestCase {

	private const NOW = 5000;

	private string $dir;

	private JobStore $store;

	private MysqliWpdb $wpdb;

	private int $flushes = 0;

	/** @var array<int, string> Run ids the step forgot. */
	private array $forgot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		FakeWpdb::stubCache();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->wpdb  = new MysqliWpdb();
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec(
			'CREATE TABLE wp_keep (v INT)',
			"INSERT INTO wp_options (option_name, option_value) VALUES ('a','1'),('lw_migration_retention','live-now'),('lw_migration_tmp_runs','{\"ab12\":1}'),('lw_migration_export_estimate','{\"result\":{}}')",
			// What the preserve step copied earlier: by now stale.
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('a','2'),('lw_migration_lock','stale'),('lw_migration_retention','stale')"
		);
	}

	protected function tearDown(): void {
		$this->wpdb->close();
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job(): Job {
		$job                       = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->steps                = [ 'swap' ];
		$job->state['hasDatabase'] = true;
		$job->state['tmpPrefix']   = 'lwtab12_';
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DEFERRED, "CREATE VIEW `wp_v` AS select `wp_options`.`option_value` AS `v` from `wp_options` where `wp_options`.`option_name` = 'a';\n" );
		$this->store->save( $job );

		return $job;
	}

	private function step(): SwapStep {
		return new SwapStep(
			$this->store,
			static fn() => self::db(),
			function (): void {
				++$this->flushes;
			},
			new SiteLock( $this->wpdb ),
			static fn(): int => self::NOW,
			function ( string $run ): bool {
				$this->forgot[] = $run;
				return true;
			}
		);
	}

	private function liveOption( string $name ): ?string {
		$rows = self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = '{$name}'" );
		return [] === $rows ? null : (string) $rows[0]['option_value'];
	}

	public function test_swaps_flushes_in_the_same_request_and_is_safe_to_repeat(): void {
		$job = $this->job();
		$this->exec( 'CREATE TABLE lwyab12_lwm_run (id TINYINT)' );
		$this->assertTrue( ( new SiteLock( $this->wpdb ) )->acquire( $job->id, 100 ) );
		$step = $this->step();

		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertTrue( $job->state['swapped'] );
		$this->assertSame( [ 'phase' => 'cleanup', 'deferred' => true ], $job->cursor, 'The cleanup call gets its own attempt fingerprint; the views are recorded as created.' );
		$this->assertSame( 1, $this->flushes );
		$this->assertSame( '2', $this->liveOption( 'a' ) );
		$this->assertSame( [ [ 'v' => '2' ] ], self::db()->query( 'SELECT v FROM wp_v' ) );
		$this->assertSame( 'live-now', $this->liveOption( 'lw_migration_retention' ), 'Our own options are copied again right before the RENAME.' );
		$this->assertSame( '{"ab12":1}', $this->liveOption( 'lw_migration_tmp_runs' ), 'The registry of temporary runs survives the swap.' );
		$this->assertNull( $this->liveOption( 'lw_migration_export_estimate' ), 'The size estimate of the replaced site is not carried.' );
		$this->assertSame( $job->id, ( new SiteLock( $this->wpdb ) )->holder( self::NOW + 1 ), 'The live lock row went in, refreshed.' );
		$this->assertSame( self::NOW + SiteLock::TTL, ( new SiteLock( $this->wpdb ) )->expiresAt( self::NOW ) );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'wp\\_keep'" ), 'Live tables outside the archive stay.' );
		$this->assertSame( [], $job->warnings );

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lw%'" ) );
		$this->assertTrue( $job->cursor['cleaned'] ?? false, 'The removal of the replaced tables is recorded.' );
		$this->assertSame( [ 'ab12' ], $this->forgot, 'Nothing of the run is left: it is forgotten and its id freed.' );

		$job->state['swapped'] = false; // A request died after the RENAME, before the job was saved.
		$job->cursor           = [];
		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [ 'phase' => 'cleanup', 'deferred' => true ], $job->cursor );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [], $job->warnings, 'The replay runs the deferred views again without an error.' );
		$this->assertSame( [ [ 'v' => '2' ] ], self::db()->query( 'SELECT v FROM wp_v' ) );
		$this->assertSame( '2', $this->liveOption( 'a' ) );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'wp\\_keep'" ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lw%'" ) );
	}

	public function test_a_lost_site_lock_fails_the_job_without_a_swap(): void {
		$job = $this->job();
		$this->assertTrue( ( new SiteLock( $this->wpdb ) )->acquire( 'another-job', self::NOW ) );
		$runner = new Runner( $this->store, [ 'swap' => $this->step() ] );

		$after = $runner->tick( $job->id, new NoDeadline(), self::NOW );

		$this->assertSame( Job::FAILED, $after->status );
		$this->assertStringContainsString( 'lock', $after->error );
		$this->assertSame( '1', $this->liveOption( 'a' ), 'The live tables were not replaced.' );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_options'" ) );
		$this->assertSame( 0, $this->flushes );
		$this->assertArrayNotHasKey( 'swapped', $after->state );
		$this->assertFalse( is_file( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED ) );
	}

	public function test_a_lost_lock_after_the_rename_reports_the_database_as_already_live(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		$this->step()->run( $job, new NoDeadline() );
		$job->state['swapped'] = false; // The request died after the RENAME, before the job was saved ...
		$job->cursor           = [];
		$this->assertTrue( ( new SiteLock( $this->wpdb ) )->acquire( 'another-job', self::NOW + SiteLock::TTL + 1 ) ); // ... and another job took the expired lock.
		$this->flushes = 0;

		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'Expected an ImportException.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'already live', $e->getMessage() );
		}
		$this->assertTrue( $job->state['swapped'] );
		$this->assertSame( [ 'phase' => 'cleanup' ], $job->cursor );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_a_request_that_died_before_the_rename_swaps_on_replay(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		touch( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED ); // Marker written, RENAME never ran.

		$this->assertFalse( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( '2', $this->liveOption( 'a' ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_%'" ) );
		$this->assertSame( 1, $this->flushes );
	}

	public function test_without_temporary_tables_and_without_a_started_swap_the_job_fails(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		$this->exec( 'DROP TABLE lwtab12_options', 'DROP TABLE lwtab12_users', 'DROP TABLE lwtab12_usermeta' );

		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'Expected an ImportException.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'temporary tables', $e->getMessage() );
		}
		$this->assertSame( '1', $this->liveOption( 'a' ) );
		$this->assertSame( 0, $this->flushes );
	}

	public function test_a_failing_view_is_named_without_the_server_text(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DEFERRED, "CREATE VIEW `wp_v` AS select `v` from `wp_missing_secret`;\n" );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 'Database note after the swap: View statement failed for `wp_v` (1146): missing table' ], $job->warnings );
	}

	public function test_swap_warnings_are_translated_and_name_no_path(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DEFERRED, "CREATE VIEW `other_v` AS select 1 AS `v`;\n" );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertCount( 1, $job->warnings );
		$this->assertStringStartsWith( 'Database note', $job->warnings[0] );
		$this->assertStringNotContainsString( $this->dir, $job->warnings[0] );
	}

	public function test_a_replay_after_the_rename_with_held_tables_left_marks_the_import_swapped(): void {
		$job = $this->networkJob( ArchiveKind::SITES );
		$this->exec( 'CREATE TABLE lwtab12_lwmsrc_users (ID INT)', 'INSERT INTO lwtab12_lwmsrc_users VALUES (5)', 'CREATE TABLE lwtab12_7_posts (ID INT)' );
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DEFERRED, "CREATE VIEW `wp_7_v` AS select `ID` from `wp_7_posts`;\n" );
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );
		$this->step()->run( $job, new NoDeadline() );
		$job->state['swapped'] = false; // The request died after the RENAME, before the job was saved.
		$job->cursor           = [];

		$this->assertFalse( $this->step()->run( $job, new NoDeadline() )->isDone(), 'Ruling R1: the held table is not "pending", so the replay is recognised.' );
		$this->assertTrue( $job->state['swapped'] );
		$this->assertSame( [ 'phase' => 'cleanup', 'deferred' => true ], $job->cursor );
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( array_fill( 0, 2, 'Database note after the swap: Statement skipped: not a view statement for this site.' ), $job->warnings, 'A subsite restore creates no view, even one left in the deferred file (refused in the first run and in the replay).' );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'wp\\_7\\_v'" ) );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_lwmsrc\\_users'" ), 'The held staging table survives the swap and its cleanup.' );
		$this->assertSame( [], $this->forgot, 'The run stays reserved while a held table exists.' );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'wp\\_7\\_posts'" ) );
		$this->assertSame( '1', $this->liveOption( 'a' ), 'Review C1: a temporary table of no mapped site (here lwtab12_options) never replaces a live one.' );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwtab12\\_options'" ), 'It is dropped with the leftovers.' );
	}

	public function test_the_swapped_table_names_are_saved_before_the_rename(): void {
		$job = $this->networkJob( ArchiveKind::NETWORK );
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );

		$this->step()->run( $job, new NoDeadline() );

		$saved = $this->store->load( $job->id )->state['swappedTables'] ?? null;
		$this->assertIsArray( $saved, 'Ruling R5: a replay after the RENAME still knows what the swap brought in.' );
		sort( $saved );
		$this->assertSame( [ 'wp_options', 'wp_usermeta', 'wp_users' ], $saved );
		$this->assertSame( $saved, self::sorted( $job->state['swappedTables'] ) );
	}

	public function test_a_single_site_import_records_no_swapped_tables(): void {
		$job = $this->job();
		( new SiteLock( $this->wpdb ) )->acquire( $job->id, self::NOW );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertArrayNotHasKey( 'swappedTables', $job->state );
		$this->assertArrayNotHasKey( 'swappedTables', $this->store->load( $job->id )->state );
	}

	private function networkJob( string $kind ): Job {
		$job                  = $this->job();
		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ];
		$job->state['kind']   = $kind;
		$job->state['blogs']  = [
			[
				'old'   => [ 'BlogID' => 2 ],
				'newId' => 7,
			],
		];
		$this->store->save( $job );

		return $job;
	}

	/**
	 * @param array<int, string> $names Table names.
	 * @return array<int, string>
	 */
	private static function sorted( array $names ): array {
		sort( $names );

		return $names;
	}
}
