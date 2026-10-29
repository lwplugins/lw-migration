<?php
/**
 * The tables a database reset that did not finish leaves behind, on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Reset;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\ResetCleanup;
use LightweightPlugins\Migration\Reset\ResetSwap;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class ResetCleanupTablesTest extends DatabaseTestCase {

	private const CORE = [ 'options', 'users', 'usermeta' ];

	private string $dir;

	private JobStore $store;

	/** @var array<int, string> Run ids forgotten. */
	private array $forgot = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec( "INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'Live')", 'CREATE TABLE wp_wc_orders (v INT)' );
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtab12_' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job(): Job {
		$job                     = $this->store->create( 'reset', [ 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ], [ 'type' => 'database' ], 1000 );
		$job->state['tmpPrefix'] = 'lwtab12_';
		$job->status             = Job::FAILED;

		return $job;
	}

	/** @var int Times the fresh site was settled. */
	private int $settled = 0;

	private function clean( Job $job, ?Connection $db = null ): void {
		ResetCleanup::tablesAfterFailure(
			$job,
			$db ?? self::db(),
			'wp_',
			self::CORE,
			function ( string $run ): bool {
				$this->forgot[] = $run;
				return true;
			},
			function (): void {
				++$this->settled;
			}
		);
	}

	/** @return array<int, string> */
	private function names(): array {
		$names = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $names );

		return $names;
	}

	public function test_a_marker_with_fresh_tables_pending_drops_the_fresh_tables_and_leaves_the_live_ones(): void {
		$job = $this->job();
		touch( $this->store->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED ); // Written, then the RENAME never ran.

		$this->clean( $job );

		$this->assertSame( [ 'wp_options', 'wp_usermeta', 'wp_users', 'wp_wc_orders' ], $this->names() );
		$this->assertSame( 'Live', (string) self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" )[0]['option_value'] );
		$this->assertSame( [ 'ab12' ], $this->forgot, 'Nothing of the run is left: forgotten, its marker gone.' );
		$this->assertSame( [], $job->warnings );
		$this->assertSame( 0, $this->settled, 'The live site was never replaced.' );
	}

	public function test_after_the_rename_the_replaced_tables_go(): void {
		$job = $this->job();
		( new ResetSwap( self::db(), 'lwtab12_', 'wp_' ) )->swap( self::CORE, [ 'wp_wc_orders' ] );

		$this->clean( $job );

		$this->assertSame( [ 'wp_options', 'wp_usermeta', 'wp_users' ], $this->names() );
		$this->assertSame( [], self::db()->query( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ), 'The fresh tables stay live.' );
		$this->assertSame( 1, $this->settled, 'The fresh site is settled although the finish step never ran.' );
	}

	public function test_a_reservation_whose_prefix_was_never_saved_is_released(): void {
		$this->exec( 'DROP TABLE lwyab12_lwm_run', 'DROP TABLE lwtab12_options', 'DROP TABLE lwtab12_users', 'DROP TABLE lwtab12_usermeta' );
		$token = RunMarker::newToken();
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtab12_', $token ) );
		$job = $this->job();
		unset( $job->state['tmpPrefix'] );
		$job->state[ TmpRun::CANDIDATE ] = [
			'prefix' => 'lwtab12_',
			'token'  => $token,
		];

		$this->clean( $job );

		$this->assertSame( [ 'wp_options', 'wp_usermeta', 'wp_users', 'wp_wc_orders' ], $this->names() );
		$this->assertSame( [ 'ab12' ], $this->forgot );
	}

	public function test_a_database_error_leaves_one_warning_for_the_daily_cleanup(): void {
		$job   = $this->job();
		$dying = new class( self::db() ) implements Connection {
			public function __construct( private Connection $db ) {
			}
			public function query( string $sql ): array {
				return $this->db->query( $sql );
			}
			public function stream( string $sql, callable $onRow ): int {
				return $this->db->stream( $sql, $onRow );
			}
			public function execute( string $sql ): void {
				if ( str_starts_with( $sql, 'DROP' ) ) {
					throw new QueryException( 'denied', 1142, $sql );
				}
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

		$this->clean( $job, $dying );

		$this->assertSame( [ 'Temporary tables of this reset could not all be removed; the daily cleanup removes them.' ], $job->warnings );
		$this->assertContains( 'lwtab12_options', $this->names() );
	}
}

