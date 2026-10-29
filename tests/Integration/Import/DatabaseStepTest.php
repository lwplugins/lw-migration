<?php
/**
 * The database import step on a real MySQL/MariaDB: temporary tables only.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Import;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\DatabaseStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\DyingConnection;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class DatabaseStepTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_import_writes_only_temporary_tables(): void {
		$this->exec(
			"CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://live.test')"
		);
		$store                     = new JobStore( $this->dir . '/jobs' );
		$job                       = ImportJobFixture::make( $store, $this->dir, '/unused.wpress' );
		$job->state['package']     = [
			'SiteURL' => 'https://old.test',
			'HomeURL' => 'https://old.test',
		];
		$job->state['hasDatabase'] = true;
		file_put_contents(
			$store->dir( $job->id ) . '/' . ImportFiles::DATABASE,
			"DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\n"
			. "CREATE TABLE `SERVMASK_PREFIX_options` (`option_id` bigint unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (`option_id`), UNIQUE KEY `option_name` (`option_name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n"
			. "START TRANSACTION;\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'siteurl','https://old.test','yes');\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'blogdescription','See https://old.test/about','yes');\n"
			. "COMMIT;\n"
			. "DROP VIEW IF EXISTS `SERVMASK_PREFIX_v`;\n"
			. "CREATE VIEW `SERVMASK_PREFIX_v` AS select `SERVMASK_PREFIX_options`.`option_name` AS `option_name` from `SERVMASK_PREFIX_options`;\n"
		);
		$step = new DatabaseStep( $store, static fn() => self::db(), static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 2, $runs, 'The import was resumed over several ticks.' );
		$tmp = (string) $job->state['tmpPrefix'];
		$this->assertTrue( PrefixRules::isValidTempPrefix( $tmp ) );
		$this->assertSame(
			[ [ 'option_value' => 'https://new.test' ], [ 'option_value' => 'See https://new.test/about' ] ],
			self::db()->query( "SELECT option_value FROM `{$tmp}options` ORDER BY option_id" )
		);
		$this->assertSame( [ [ 'option_value' => 'https://live.test' ] ], self::db()->query( 'SELECT option_value FROM wp_options' ), 'The live table is untouched.' );
		$this->assertCount( 3, self::db()->query( 'SHOW FULL TABLES' ), 'Only wp_options, the temporary table and the run marker exist; the view is deferred.' );
		$deferred = (string) file_get_contents( $store->dir( $job->id ) . '/' . ImportFiles::DEFERRED );
		$this->assertStringContainsString( 'CREATE OR REPLACE VIEW `wp_v`', $deferred, 'The view waits in the work folder until the swap.' );
		$this->assertSame( [], $job->warnings );
	}

	public function test_a_death_after_a_committed_run_replays_no_committed_rows(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job(
			$store,
			"CREATE TABLE `SERVMASK_PREFIX_log` (`v` int) ENGINE=InnoDB;\n"
			. "INSERT INTO `SERVMASK_PREFIX_log` VALUES (1);\n"
			. "INSERT INTO `SERVMASK_PREFIX_log` VALUES (2);\n"
			. "CREATE TABLE `SERVMASK_PREFIX_more` (`w` int) ENGINE=InnoDB;\n"
			. "INSERT INTO `SERVMASK_PREFIX_more` VALUES (3);\n"
		);
		$dying = new DyingConnection( self::db(), 'CREATE TABLE `lwt' );
		$step  = new DatabaseStep( $store, static fn() => $dying, static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );
		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone(), 'The first call only picks the prefix.' );
		$store->save( $job );
		$tmp = (string) $job->state['tmpPrefix'];

		$dying->armAfter( "CREATE TABLE `{$tmp}log`" );
		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone(), 'One run per call: it returns at the table boundary.' );
		$store->save( $job ); // The Runner saves the job before every call.
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The connection dies at the next table.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection lost.', $e->getMessage() );
		}

		$job    = $store->load( $job->id ); // The process died: only the saved job is left.
		$dying->disarm();
		$result = $step->run( $job, new NoDeadline() );
		$this->assertTrue( $result->isDone() );
		$this->assertSame( [ [ 'v' => '1' ], [ 'v' => '2' ] ], self::db()->query( "SELECT v FROM `{$tmp}log` ORDER BY v" ), 'No committed row is imported twice.' );
		$this->assertSame( [ [ 'w' => '3' ] ], self::db()->query( "SELECT w FROM `{$tmp}more`" ) );
	}

	public function test_a_view_with_the_candidate_prefix_makes_it_busy(): void {
		$this->exec( 'CREATE TABLE wp_options (option_id INT PRIMARY KEY)', 'CREATE VIEW lwtaaaa_v AS SELECT option_id FROM wp_options' );
		$store      = new JobStore( $this->dir . '/jobs' );
		$job        = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$candidates = [ 'lwtaaaa_', 'lwtbbbb_' ];
		$step       = new DatabaseStep(
			$store,
			static fn() => self::db(),
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			self::registerAny(),
			static function () use ( &$candidates ): string {
				return (string) array_shift( $candidates );
			}
		);

		$step->run( $job, new NoDeadline() );

		$this->assertSame( 'lwtbbbb_', $job->state['tmpPrefix'] );
	}

	public function test_the_run_is_registered_before_any_table_is_created(): void {
		$store      = new JobStore( $this->dir . '/jobs' );
		$job        = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$registered = [];
		$step       = new DatabaseStep(
			$store,
			static fn() => self::db(),
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			function ( string $prefix ) use ( &$registered ): bool {
				$this->assertSame( [ [ 'Tables_in_' . self::db()->databaseName() => 'lwy' . substr( $prefix, 3 ) . 'lwm_run' ] ], self::db()->query( 'SHOW TABLES' ), 'Registered after the reservation, before any other table.' );
				$registered[] = $prefix;
				return true;
			}
		);

		$step->run( $job, new NoDeadline() );
		$this->assertSame( [ $job->state['tmpPrefix'] ], $registered );
		$step->run( $job, new NoDeadline() );

		$this->assertCount( 1, $registered, 'Once per run.' );
		$this->assertCount( 2, self::db()->query( 'SHOW TABLES' ), 'The imported table and the run marker.' );
	}

	public function test_a_run_that_cannot_be_registered_creates_nothing(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$step  = new DatabaseStep( $store, static fn() => self::db(), static fn( string $p ): bool => false, static fn( string $t ): bool => false, static fn( string $prefix ): bool => false );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'An unregistered run must not start.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'temporary tables', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'tmpPrefix', $job->state );
		$this->assertSame( [], self::db()->query( 'SHOW TABLES' ) );
	}

	private function job( JobStore $store, string $sql ): Job {
		$job                       = ImportJobFixture::make( $store, $this->dir, '/unused.wpress' );
		$job->state['package']     = [
			'SiteURL' => 'https://old.test',
			'HomeURL' => 'https://old.test',
		];
		$job->state['hasDatabase'] = true;
		file_put_contents( $store->dir( $job->id ) . '/' . ImportFiles::DATABASE, $sql );

		return $job;
	}

	public function test_an_id_another_site_reserves_after_the_listing_is_skipped(): void {
		$store      = new JobStore( $this->dir . '/jobs' );
		$job        = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$candidates = [ 'lwtaaaa_', 'lwtbbbb_' ];
		$step       = new DatabaseStep(
			$store,
			static fn() => self::db(),
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			self::registerAny(),
			static function () use ( &$candidates ): string {
				$next = (string) array_shift( $candidates );
				if ( 'lwtaaaa_' === $next ) {
					self::db()->execute( 'CREATE TABLE lwyaaaa_lwm_run (id TINYINT)' ); // Another site, between the listing and the reservation.
				}
				return $next;
			}
		);

		$step->run( $job, new NoDeadline() );

		$this->assertSame( 'lwtbbbb_', $job->state['tmpPrefix'] );
		$this->assertCount( 2, self::db()->query( "SHOW TABLES LIKE 'lwy%lwm\\_run'" ) );
	}

	public function test_a_death_between_reserving_and_registering_is_recovered_on_replay(): void {
		$store      = new JobStore( $this->dir . '/jobs' );
		$job        = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$store->save( $job ); // The Runner saved the job before the call.
		$registered = [];
		$dies       = true;
		$step       = new DatabaseStep(
			$store,
			static fn() => self::db(),
			static fn( string $p ): bool => false,
			static fn( string $t ): bool => false,
			static function ( string $prefix ) use ( &$registered, &$dies ): bool {
				if ( $dies ) {
					throw new \RuntimeException( 'The request died.' ); // After the reservation, before the registry row.
				}
				$registered[] = $prefix;
				return true;
			}
		);
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The request dies.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The request died.', $e->getMessage() );
		}

		$job  = $store->load( $job->id ); // Only the saved job is left.
		$dies = false;
		$step->run( $job, new NoDeadline() );

		$markers = self::db()->query( "SHOW TABLES LIKE 'lwy%lwm\\_run'" );
		$this->assertCount( 1, $markers, 'The id reserved before the death is used, not a second one.' );
		$this->assertSame( [ $job->state['tmpPrefix'] ], $registered );
		$this->assertSame( RunMarker::name( (string) $job->state['tmpPrefix'] ), (string) array_values( $markers[0] )[0] );
		$this->assertArrayNotHasKey( 'tmpCandidate', $job->state );
	}

	public function test_a_saved_candidate_another_site_holds_is_not_taken_over(): void {
		$store                      = new JobStore( $this->dir . '/jobs' );
		$job                        = $this->job( $store, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int);\n" );
		$job->state['tmpCandidate'] = [
			'prefix' => 'lwtaaaa_',
			'token'  => 'aaaaaaaaaaaaaaaa',
		];
		$this->assertTrue( RunMarker::reserve( self::db(), 'lwtaaaa_', 'bbbbbbbbbbbbbbbb' ) ); // Another site's run.
		$step = new DatabaseStep( $store, static fn() => self::db(), static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );

		$step->run( $job, new NoDeadline() );

		$this->assertNotSame( 'lwtaaaa_', $job->state['tmpPrefix'] );
		$this->assertTrue( RunMarker::heldWith( self::db(), 'lwtaaaa_', 'bbbbbbbbbbbbbbbb' ), 'The other site keeps its id.' );
	}

	public function test_a_subsite_import_into_a_network_names_the_left_out_tables_once(): void {
		$store                = new JobStore( $this->dir . '/jobs' );
		$job                  = $this->job(
			$store,
			"CREATE TABLE `SERVMASK_PREFIX_2_posts` (`ID` int);\n"
			. "INSERT INTO `SERVMASK_PREFIX_2_posts` VALUES (1);\n"
			. "CREATE TABLE `wbk_a` (`id` int);\nINSERT INTO `wbk_a` VALUES (1);\nINSERT INTO `wbk_a` VALUES (2);\n"
			. "CREATE TABLE `civicrm_b` (`id` int);\n"
		);
		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https', 'basePrefix' => 'wp_' ];
		$job->state['kind']   = 'sites';
		$job->state['blogs']  = [
			[
				'old'           => [ 'BlogID' => 2 ],
				'newId'         => 7,
				'newSiteUrl'    => 'https://new.test/shop',
				'newHomeUrl'    => 'https://new.test/shop',
				'newUploadsUrl' => 'https://new.test/wp-content/uploads/sites/7/',
			],
		];
		$step                 = new DatabaseStep( $store, static fn() => self::db(), static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );
		for ( $runs = 0; $runs < 50 && ! $step->run( $job, new NoDeadline() )->isDone(); $runs++ ) {
			$store->save( $job );
		}

		$tmp = (string) $job->state['tmpPrefix'];
		$this->assertSame( [ [ 'ID' => '1' ] ], self::db()->query( "SELECT ID FROM `{$tmp}7_posts`" ) );
		$this->assertSame( [ 'These tables of the archive belong to no site of this network and were not restored: wbk_a, civicrm_b' ], $job->warnings );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwx%'" ) );
	}

	public function test_a_subsite_import_into_a_network_brings_no_view_and_names_them_once(): void {
		$store                = new JobStore( $this->dir . '/jobs' );
		$job                  = $this->job(
			$store,
			"CREATE TABLE `SERVMASK_PREFIX_2_posts` (`ID` int);\n"
			. "DROP VIEW IF EXISTS `SERVMASK_PREFIX_2_v`;\n"
			. "CREATE VIEW `SERVMASK_PREFIX_2_v` AS select 1 from `SERVMASK_PREFIX_2_posts`;\n"
			. "CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `SERVMASK_PREFIX_2_leak` AS select `u`.`user_pass` AS `p` from `SERVMASK_PREFIX_2_posts` `x` STRAIGHT_JOIN `wp_users` `u`;\n"
			. "CREATE VIEW `SERVMASK_PREFIX_2_leak2` AS select u.user_pass from (select 1) a, mysql.user u;\n"
		);
		$job->state['target'] = [ 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https', 'basePrefix' => 'wp_' ];
		$job->state['kind']   = 'sites';
		$job->state['blogs']  = [
			[
				'old'           => [ 'BlogID' => 2 ],
				'newId'         => 7,
				'newSiteUrl'    => 'https://new.test/shop',
				'newHomeUrl'    => 'https://new.test/shop',
				'newUploadsUrl' => 'https://new.test/wp-content/uploads/sites/7/',
			],
		];
		$step                 = new DatabaseStep( $store, static fn() => self::db(), static fn( string $p ): bool => false, static fn( string $t ): bool => false, self::registerAny() );
		for ( $runs = 0; $runs < 50 && ! $step->run( $job, new NoDeadline() )->isDone(); $runs++ ) {
			$store->save( $job );
		}

		$this->assertSame( [ 'The views of the archive were not restored (a site restored into a network brings no views): wp_7_v, wp_7_leak, wp_7_leak2' ], $job->warnings );
		$this->assertFileDoesNotExist( $store->dir( $job->id ) . '/' . ImportFiles::DEFERRED );
	}

	private static function registerAny(): \Closure {
		return static fn( string $prefix ): bool => true;
	}
}
