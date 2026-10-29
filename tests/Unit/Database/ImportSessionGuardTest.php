<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Issue #14: the import runs archive statements (the dump, then the deferred
 * views) only on a session that reads them the way the import checks them,
 * and never creates a view that reads another database.
 */
final class ImportSessionGuardTest extends TestCase {

	private const DUMP = "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_t` VALUES ('a\\\\');\n";

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function import( FakeConnection $db, string $sql, ?ImportCursor $cursor = null ): bool {
		$path = $this->dir . '/db.sql';
		file_put_contents( $path, $sql );
		$plan = new ImportPlan( 'wp_', 'lwtab12_', PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', 1048576 );

		return ( new Importer( $db, new StatementReader( $path ), $plan ) )->run( $cursor ?? new ImportCursor(), new NoDeadline() );
	}

	public function test_an_unsafe_client_charset_stops_the_import_before_any_statement(): void {
		$db = new FakeConnection();
		$db->rows['SELECT @@character_set_client'] = [ [ 'cs' => 'sjis' ] ];

		try {
			$this->import( $db, self::DUMP );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( 'uses the sjis character set (character_set_client)', $e->getMessage() );
		}
		$this->assertSame( [], $db->log );
	}

	public function test_a_session_that_keeps_no_backslash_escapes_stops_the_import_before_any_statement(): void {
		$db          = new FakeConnection();
		$db->sqlMode = 'NO_BACKSLASH_ESCAPES'; // A server that ignores the SET.

		try {
			$this->import( $db, self::DUMP );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( 'NO_BACKSLASH_ESCAPES', $e->getMessage() );
		}
		$this->assertSame( [ "SET SESSION sql_mode = ''" ], $db->log, 'No transaction, no statement of the archive.' );
	}

	public function test_a_view_reading_another_database_is_skipped_with_a_warning_and_the_import_goes_on(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$sql    = self::DUMP
			. "CREATE VIEW `SERVMASK_PREFIX_mine` AS select `fake`.`SERVMASK_PREFIX_t`.`a` AS `a` from `fake`.`SERVMASK_PREFIX_t`;\n"
			. "CREATE VIEW `SERVMASK_PREFIX_leak` AS select `u`.`pw` AS `pw` from `other`.`users` `u`;\n";

		$this->assertTrue( $this->import( $db, $sql, $cursor ) );

		$this->assertSame( "CREATE OR REPLACE VIEW `wp_mine` AS select `fake`.`wp_t`.`a` AS `a` from `fake`.`wp_t`;\n", file_get_contents( $this->dir . '/views.sql' ) );
		$this->assertCount( 1, $cursor->warnings );
		$this->assertStringContainsString( 'Skipped the view `wp_leak`', $cursor->warnings[0] );
		$this->assertStringContainsString( 'another database', $cursor->warnings[0] );
		$this->assertContains( "INSERT INTO `lwtab12_t` VALUES ('a\\\\');", $db->log );
	}

	public function test_deferred_views_run_on_a_checked_relaxed_session_and_never_read_another_database(): void {
		$path = $this->dir . '/views.sql';
		file_put_contents( $path, "CREATE OR REPLACE VIEW `wp_mine` AS select 1 AS `a` from `wp_t`;\nCREATE OR REPLACE VIEW `wp_leak` AS select `u`.`pw` AS `pw` from other.users u;\n" );
		$db = new FakeConnection();

		$warnings = ( new TableSwap( $db, 'lwtab12_', 'wp_' ) )->applyDeferred( $path );

		$this->assertSame( [ "SET SESSION sql_mode = ''", 'CREATE OR REPLACE VIEW `wp_mine` AS select 1 AS `a` from `wp_t`;' ], $db->log );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'View statement skipped for `wp_leak`', $warnings[0] );
	}

	public function test_deferred_views_are_skipped_when_the_session_fails_its_check(): void {
		$path = $this->dir . '/views.sql';
		file_put_contents( $path, "CREATE OR REPLACE VIEW `wp_mine` AS select 1 AS `a`;\n" );
		$db          = new FakeConnection();
		$db->sqlMode = 'NO_BACKSLASH_ESCAPES';

		$warnings = ( new TableSwap( $db, 'lwtab12_', 'wp_' ) )->applyDeferred( $path );

		$this->assertSame( [ "SET SESSION sql_mode = ''" ], $db->log );
		$this->assertCount( 1, $warnings );
		$this->assertStringStartsWith( 'View statements skipped: ', $warnings[0] );
	}
}
