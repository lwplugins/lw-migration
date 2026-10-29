<?php
/**
 * The failure cleanup of an import on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class ImportCleanupTest extends DatabaseTestCase {

	private string $dir = '';

	/** @var array<int, string> Run ids the cleanup forgot. */
	private array $forgot = [];

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		if ( '' !== $this->dir ) {
			TempDir::remove( $this->dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function failedJob(): Job {
		$job                     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$job->status             = Job::FAILED;
		$job->state['tmpPrefix'] = 'lwtab12_';

		return $job;
	}

	private function cleanup( Job $job ): void {
		ImportCleanup::afterFailure(
			$job,
			$this->store,
			static fn() => self::db(),
			$this->dir . '/backups',
			static fn( Job $j ): array => [],
			function ( string $run ): bool {
				$this->forgot[] = $run;
				return true;
			}
		);
	}

	/** @return array<int, string> */
	private function tables(): array {
		$tables = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $tables );

		return $tables;
	}

	public function test_failure_before_swap_drops_temporary_tables(): void {
		$this->exec( 'CREATE TABLE wp_x (v INT)', 'CREATE TABLE lwtab12_x (v INT)', 'CREATE TABLE lwxab12_wbk_y (v INT)', 'CREATE TABLE lwtcd34_other (v INT)' );
		$job                        = $this->failedJob();
		$job->state['archiveOwned'] = true;
		$work                       = $this->store->dir( $job->id );
		file_put_contents( $work . '/' . ImportFiles::DATABASE, 'x' );
		file_put_contents( $work . '/' . ImportFiles::DOWNLOAD, 'x' );

		$this->cleanup( $job );

		$this->assertSame( [ 'lwtcd34_other', 'wp_x' ], $this->tables(), 'Only this job\'s temporary tables are dropped.' );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DATABASE );
		$this->assertFileDoesNotExist( $work . '/' . ImportFiles::DOWNLOAD );
		$this->assertSame( [], $job->warnings );
	}

	public function test_a_reservation_never_registered_is_released_but_never_another_sites(): void {
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_', 'aaaaaaaaaaaaaaaa' ) );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtbbbb_', 'bbbbbbbbbbbbbbbb' ) );
		foreach ( [ 'lwtaaaa_' => 'aaaaaaaaaaaaaaaa', 'lwtbbbb_' => 'cccccccccccccccc' ] as $prefix => $token ) {
			$job = $this->failedJob();
			unset( $job->state['tmpPrefix'] );
			$job->state['tmpCandidate'] = [
				'prefix' => $prefix,
				'token'  => $token,
			];

			$this->cleanup( $job );
		}

		$this->assertSame( [ 'lwybbbb_lwm_run' ], $this->tables(), 'Its own marker goes; the one with another token stays.' );
		$this->assertSame( [ 'aaaa' ], $this->forgot, 'Forgotten first (the request may have died after registering it), then the marker goes.' );
	}

	public function test_a_table_that_cannot_be_dropped_is_named_without_the_server_text(): void {
		$this->exec( 'CREATE TABLE lwtab12_p (id INT PRIMARY KEY) ENGINE=InnoDB', 'CREATE TABLE wp_child (p INT, FOREIGN KEY (p) REFERENCES lwtab12_p (id)) ENGINE=InnoDB' );
		$job = $this->failedJob();

		$this->cleanup( $job );
		$this->exec( 'DROP TABLE wp_child', 'DROP TABLE lwtab12_p' );

		$this->assertCount( 1, $job->warnings );
		$this->assertMatchesRegularExpression( '/^Removing the temporary tables of this import: Could not drop the replaced table lwtab12_p \((3730|1451)\): another table references it — /', $job->warnings[0] );
		$this->assertStringNotContainsString( 'wp_child', $job->warnings[0], 'The server text names the other table; it is not shown.' );
	}

	public function test_after_the_swap_nothing_is_dropped_and_the_user_is_told(): void {
		$this->exec( 'CREATE TABLE lwtab12_late (v INT)', 'CREATE TABLE lwoab12_options (v INT)' );
		$job                   = $this->failedJob();
		$job->state['swapped'] = true;

		$this->cleanup( $job );

		$this->assertSame( [ 'lwoab12_options', 'lwtab12_late' ], $this->tables() );
		$this->assertStringContainsString( 'safety backup', implode( ' ', $job->warnings ) );
	}

	public function test_a_started_swap_keeps_every_table(): void {
		$this->exec( 'CREATE TABLE lwtab12_x (v INT)', 'CREATE TABLE lwoab12_options (v INT)' );
		$job = $this->failedJob();
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->cleanup( $job );

		$this->assertSame( [ 'lwoab12_options', 'lwtab12_x' ], $this->tables(), 'The swap may have happened: the old tables may be the previous database.' );
		$this->assertStringContainsString( 'may already run on the imported database', implode( ' ', $job->warnings ) );
	}

	public function test_after_the_swap_the_user_is_told_the_previous_tables_are_kept_for_a_day(): void {
		$this->exec( 'CREATE TABLE lwyab12_wbk_a (v INT)' );
		$job                        = $this->failedJob();
		$job->state['swapped']      = true;
		$job->state['safetyBackup'] = [ 'name' => 'safety.wpress' ];

		$this->cleanup( $job );

		$this->assertStringContainsString( 'The previous database tables are kept for about a day for manual recovery; the safety backup stays until you delete it.', implode( ' ', $job->warnings ) );
		$this->assertStringContainsString( 'Restore the safety backup safety.wpress if you need the previous database (it holds the database only, no files).', implode( ' ', $job->warnings ) );
	}

	public function test_without_replaced_tables_nothing_is_said_about_keeping_them(): void {
		$this->exec( 'CREATE TABLE lwtab12_x (v INT)' );
		$job = $this->failedJob();
		file_put_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->cleanup( $job );

		$this->assertStringNotContainsString( 'kept for about a day', implode( ' ', $job->warnings ) );
	}

	public function test_without_a_safety_backup_only_the_tables_are_mentioned(): void {
		$this->exec( 'CREATE TABLE lwoab12_options (v INT)' );
		$job                   = $this->failedJob();
		$job->state['swapped'] = true;

		$this->cleanup( $job );

		$warnings = implode( ' ', $job->warnings );
		$this->assertStringContainsString( 'The previous database tables are kept for about a day for manual recovery.', $warnings );
		$this->assertStringNotContainsString( 'the safety backup stays', $warnings );
	}

	public function test_a_clean_failure_before_the_swap_forgets_the_run_and_frees_its_id(): void {
		$this->exec( 'CREATE TABLE lwtab12_x (v INT)', 'CREATE TABLE lwyab12_lwm_run (id TINYINT)', 'CREATE TABLE lwycd34_lwm_run (id TINYINT)' );

		$this->cleanup( $this->failedJob() );

		$this->assertSame( [ 'ab12' ], $this->forgot );
		$this->assertSame( [ 'lwycd34_lwm_run' ], $this->tables(), 'Only this run\'s marker goes, after its tables.' );
	}

	public function test_after_the_swap_the_run_stays_reserved(): void {
		$this->exec( 'CREATE TABLE lwoab12_options (v INT)', 'CREATE TABLE lwyab12_lwm_run (id TINYINT)' );
		$job                   = $this->failedJob();
		$job->state['swapped'] = true;

		$this->cleanup( $job );

		$this->assertSame( [], $this->forgot );
		$this->assertSame( [ 'lwoab12_options', 'lwyab12_lwm_run' ], $this->tables() );
	}

	public function test_a_failed_subsite_import_before_the_swap_drops_its_held_staging_tables_too(): void {
		$this->exec( 'CREATE TABLE wp_users (v INT)', 'CREATE TABLE lwtab12_7_posts (v INT)', 'CREATE TABLE lwtab12_lwmsrc_users (v INT)', 'CREATE TABLE lwyab12_lwm_run (id TINYINT)' );
		$job                  = $this->failedJob();
		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'basePrefix' => 'wp_' ];
		$job->state['kind']   = 'sites';

		$this->cleanup( $job );

		$this->assertSame( [ 'wp_users' ], $this->tables(), 'Decision 15: the failure cleanup builds its swap without held suffixes.' );
		$this->assertSame( [ 'ab12' ], $this->forgot );
	}
}
