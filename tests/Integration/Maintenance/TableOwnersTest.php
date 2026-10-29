<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Maintenance;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\DatabaseStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Maintenance\TableOwners;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class TableOwnersTest extends DatabaseTestCase {

	/**
	 * Job store folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		FakeWpdb::install();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_only_registered_tables_no_job_owns_are_dropped(): void {
		$store = new JobStore( $this->dir );
		$now   = time();
		$this->import( $store, $now - 60, 'lwtaaaa_', Job::RUNNING );
		$swapped = $this->import( $store, $now - 60, 'lwtbbbb_', Job::FAILED );

		$swapped->state['swapped'] = true;
		$store->save( $swapped );
		$marked = $this->import( $store, $now - 90000, 'lwtcccc_', Job::RUNNING );
		file_put_contents( $store->dir( $marked->id ) . '/' . ImportFiles::SWAP_STARTED, '' );
		$this->import( $store, $now - 60, 'lwtdddd_', Job::FAILED );
		$registry = new TmpRunRegistry();
		foreach ( [ 'aaaa', 'bbbb', 'cccc', 'dddd', 'ffff', 'gggg' ] as $run ) {
			$registry->register( $run, $now - 90000 );
		}
		$registry->register( 'hhhh', $now - 60 );
		$tables = [ 'wp_options', 'lwtffff_gone_with_its_folder', 'lwoffff_gone_with_its_folder', 'lwtabcd_another_site', 'lwoabcd_another_site' ];
		foreach ( [ 'aaaa', 'bbbb', 'cccc', 'dddd' ] as $id ) {
			foreach ( [ 'lwt', 'lwx', 'lwo', 'lwy' ] as $family ) {
				$tables[] = $family . $id . '_t';
			}
		}
		foreach ( $tables as $table ) {
			$this->exec( 'CREATE TABLE `' . $table . '` (v INT)' );
		}

		$result = TableOwners::sweep( self::db(), $store, new SiteLock(), $registry, $now, 'wp_' );

		$this->assertNotNull( $result );
		$dropped = $result['dropped'];
		sort( $dropped );
		$this->assertSame( [ 'lwoffff_gone_with_its_folder', 'lwtdddd_t', 'lwtffff_gone_with_its_folder', 'lwxdddd_t' ], $dropped, 'An ended, not swapped import keeps only its replaced tables while its folder exists.' );
		$this->assertSame( [ 'lwoabcd_another_site', 'lwtabcd_another_site' ], $result['unregistered'], 'Another site in the same database may own them.' );
		$this->assertContains( 'lwtabcd_another_site', $this->left() );
		$this->assertSame( [ 'aaaa', 'bbbb', 'cccc', 'dddd', 'hhhh' ], $registry->ids(), 'Runs with no table and no job are forgotten; a fresh registration is kept.' );
		$this->assertNull( ( new SiteLock() )->holder( $now ), 'The sweep released the site lock.' );
	}

	public function test_uninstall_drops_every_table_of_this_sites_runs_and_frees_their_ids(): void {
		$registry = new TmpRunRegistry();
		$registry->register( 'aaaa', time() );
		$registry->register( 'bbbb', time() );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_' ) );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtbbbb_' ) );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtcccc_' ) ); // Another site's run.
		foreach ( [ 'wp_options', 'lwtaaaa_options', 'lwxaaaa_wbk_a', 'lwoaaaa_posts', 'lwyaaaa_wbk_b', 'lwobbbb_options', 'lwtcccc_options' ] as $table ) {
			$this->exec( 'CREATE TABLE `' . $table . '` (v INT)' );
		}
		$this->exec( 'CREATE VIEW lwtaaaa_v AS SELECT v FROM lwtaaaa_options' );

		$result = TableOwners::dropForUninstall( self::db(), new SiteLock(), $registry, 'wp_' );

		$this->assertNotNull( $result );
		$this->assertSame( [ 'lwtcccc_options', 'lwycccc_lwm_run', 'wp_options' ], $this->left(), 'Undo tables of this site go too; another site\'s run stays.' );
		$this->assertSame( [], $registry->ids() );
		$this->assertNull( ( new SiteLock() )->holder( time() ) );
	}

	public function test_uninstall_keeps_a_run_whose_table_cannot_be_dropped(): void {
		$registry = new TmpRunRegistry();
		$registry->register( 'aaaa', time() );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_' ) );
		$this->exec( 'CREATE TABLE lwtaaaa_parent (id INT PRIMARY KEY) ENGINE=InnoDB', 'CREATE TABLE wp_child (pid INT, FOREIGN KEY (pid) REFERENCES lwtaaaa_parent (id)) ENGINE=InnoDB' );

		$result = TableOwners::dropForUninstall( self::db(), new SiteLock(), $registry, 'wp_' );
		$this->exec( 'DROP TABLE wp_child' );

		$this->assertArrayHasKey( 'lwtaaaa_parent', (array) $result['failed'] );
		$this->assertSame( [ 'aaaa' ], $registry->ids(), 'Still registered: a later sweep may drop it.' );
		$this->assertContains( 'lwyaaaa_lwm_run', $this->left() );
	}

	public function test_uninstall_leaves_the_tables_of_a_running_job(): void {
		$registry = new TmpRunRegistry();
		$registry->register( 'aaaa', time() );
		$this->exec( 'CREATE TABLE lwtaaaa_options (v INT)' );
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'job-a', time() ) );

		$this->assertNull( TableOwners::dropForUninstall( self::db(), $lock, $registry, 'wp_' ) );
		$this->assertSame( [ 'lwtaaaa_options' ], $this->left() );
		$this->assertSame( [ 'aaaa' ], $registry->ids() );
	}

	public function test_nothing_is_dropped_while_the_site_lock_is_held(): void {
		$store    = new JobStore( $this->dir );
		$now      = time();
		$registry = new TmpRunRegistry();
		$registry->register( 'ffff', $now - 90000 );
		$this->exec( 'CREATE TABLE lwtffff_t (v INT)' );
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'someone', $now ) );

		$this->assertNull( TableOwners::sweep( self::db(), $store, $lock, $registry, $now, 'wp_' ) );
		$this->assertSame( [ 'lwtffff_t' ], $this->left() );
		$this->assertSame( 'someone', $lock->holder( $now ) );
	}

	public function test_a_job_that_appears_after_the_listing_keeps_its_tables(): void {
		$store    = new JobStore( $this->dir );
		$now      = time();
		$registry = new TmpRunRegistry();
		$registry->register( 'ffff', $now - 90000 );
		$this->exec( 'CREATE TABLE lwtffff_t (v INT)', 'CREATE TABLE lwoffff_t (v INT)' );
		$appear = function () use ( $store, $now ): void {
			$this->import( $store, $now, 'lwtffff_', Job::RUNNING );
		};
		$db     = new class( self::db(), $appear ) implements Connection {
			public function __construct( private Connection $db, private ?\Closure $onList ) {
			}
			public function query( string $sql ): array {
				$rows = $this->db->query( $sql );
				if ( null !== $this->onList && str_starts_with( $sql, 'SHOW FULL TABLES' ) ) {
					( $this->onList )();
					$this->onList = null;
				}
				return $rows;
			}
			public function stream( string $sql, callable $onRow ): int {
				return $this->db->stream( $sql, $onRow );
			}
			public function execute( string $sql ): void {
				$this->db->execute( $sql );
			}
			public function escape( string $value ): string {
				return $this->db->escape( $value );
			}
			public function serverVersion(): string {
				return $this->db->serverVersion();
			}
			public function variable( string $name ): ?string {
				return $this->db->variable( $name );
			}
			public function databaseName(): string {
				return $this->db->databaseName();
			}
			public function host(): string {
				return $this->db->host();
			}
		};

		$result = TableOwners::sweep( $db, $store, new SiteLock(), $registry, $now, 'wp_' );

		$this->assertNotNull( $result );
		$this->assertSame( [], $result['dropped'] );
		$this->assertSame( [ 'lwoffff_t', 'lwtffff_t' ], $this->left() );
		$this->assertSame( [ 'ffff' ], $registry->ids() );
	}

	public function test_another_site_cannot_take_an_id_this_site_still_holds(): void {
		$now      = time();
		$storeA   = new JobStore( $this->dir . '/a' );
		$registry = new TmpRunRegistry();
		$siteB    = new FakeWpdb();
		$regB     = new TmpRunRegistry( $siteB );
		$held     = $this->import( $storeA, $now - 90000, 'lwtaaaa_', Job::FAILED );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_' ) );
		$registry->register( 'aaaa', $now - 90000 ); // Site A: registered, no tables left, folder still there.

		$storeB = new JobStore( $this->dir . '/b' );
		$jobB   = ImportJobFixture::make( $storeB, $this->dir, '/unused.wpress' );
		$jobB->state['hasDatabase'] = true;
		$candidates = [ 'lwtaaaa_', 'lwtbbbb_' ];
		$step       = new DatabaseStep(
			$storeB,
			static fn() => self::db(),
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			static fn( string $tmp ): bool => $regB->register( OrphanTables::runId( $tmp ), $now ),
			static function () use ( &$candidates ): string {
				return (string) array_shift( $candidates );
			}
		);
		$step->run( $jobB, new NoDeadline() );
		$this->assertSame( 'lwtbbbb_', $jobB->state['tmpPrefix'], 'The id site A holds is refused.' );
		$this->assertSame( [ 'bbbb' ], $regB->ids() );
		$this->exec( 'CREATE TABLE lwtbbbb_options (v INT)', 'CREATE TABLE lwobbbb_posts (v INT)' );

		TableOwners::sweep( self::db(), $storeA, new SiteLock(), $registry, $now, 'wp_' );
		$this->assertSame( [ 'lwobbbb_posts', 'lwtbbbb_options', 'lwyaaaa_lwm_run', 'lwybbbb_lwm_run' ], $this->left(), 'Site A keeps its reservation while its folder exists.' );

		$storeA->delete( $held->id );
		TableOwners::sweep( self::db(), $storeA, new SiteLock(), $registry, $now, 'wp_' );

		$this->assertSame( [ 'lwobbbb_posts', 'lwtbbbb_options', 'lwybbbb_lwm_run' ], $this->left(), 'Site A frees its id; site B\'s run is untouched.' );
		$this->assertSame( [], $registry->ids() );
	}

	public function test_held_staging_tables_of_a_finished_import_stay_while_its_folder_exists(): void {
		$store                     = new JobStore( $this->dir );
		$now                       = time();
		$done                      = $this->import( $store, $now - 90000, 'lwtaaaa_', Job::DONE );
		$done->state['swapped']    = true;
		$store->save( $done );
		$registry = new TmpRunRegistry();
		$registry->register( 'aaaa', $now - 90000 );
		$this->exec( 'CREATE TABLE lwtaaaa_lwmsrc_users (v INT)', 'CREATE TABLE wp_options (v INT)' );

		TableOwners::sweep( self::db(), $store, new SiteLock(), $registry, $now, 'wp_' );
		$this->assertSame( [ 'lwtaaaa_lwmsrc_users', 'wp_options' ], $this->left(), 'The users of the archive wait for the merge after the swap.' );

		$store->delete( $done->id );
		TableOwners::sweep( self::db(), $store, new SiteLock(), $registry, $now, 'wp_' );
		$this->assertSame( [ 'wp_options' ], $this->left(), 'Without its job the daily sweep removes them.' );
	}

	public function test_a_reset_jobs_run_is_kept_like_an_imports(): void {
		$store   = new JobStore( $this->dir );
		$now     = time();
		$running = $this->import( $store, $now - 60, 'lwtab12_', Job::RUNNING, 'reset' );
		$this->assertSame( OrphanTables::runPrefixes( 'lwtab12_' ), TableOwners::keptPrefixes( $store, $now ), 'A fresh, unfinished reset owns its whole run.' );

		$running->status    = Job::FAILED;
		$running->updatedAt = $now - 90000;
		$store->save( $running );
		$this->assertSame( OrphanTables::undoPrefixes( 'lwtab12_' ), TableOwners::keptPrefixes( $store, $now ), 'An ended reset keeps its replaced tables while its folder exists.' );

		file_put_contents( $store->dir( $running->id ) . '/' . ImportFiles::SWAP_STARTED, '' );
		$this->assertSame( OrphanTables::runPrefixes( 'lwtab12_' ), TableOwners::keptPrefixes( $store, $now ), 'A reset whose swap may have run keeps everything.' );
	}

	private function import( JobStore $store, int $at, string $tmp, string $status, string $type = 'import' ): Job {
		$job = $store->create( $type, [ 'a' ], [], $at );

		$job->state['tmpPrefix'] = $tmp;
		$job->status             = $status;
		$store->save( $job );

		return $job;
	}

	/**
	 * @return array<int, string> Every table and view left, sorted.
	 */
	private function left(): array {
		$left = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $left );

		return $left;
	}
}
