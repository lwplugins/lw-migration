<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class TableSwapTest extends DatabaseTestCase {

	public function test_swap_replaces_live_tables_and_keeps_others(): void {
		$this->exec(
			'CREATE TABLE wp_options (v INT)', 'INSERT INTO wp_options VALUES (1)',
			'CREATE TABLE wp_keep (v INT)',
			'CREATE TABLE lwtab12_options (v INT)', 'INSERT INTO lwtab12_options VALUES (2)',
			'CREATE TABLE lwtab12_posts (v INT)'
		);
		$swap   = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$result = $swap->swap();
		$finals = $result->finals;
		sort( $finals );
		$this->assertSame( [], $result->warnings );

		$this->assertSame( [ 'wp_options', 'wp_posts' ], $finals );
		$this->assertSame( [ [ 'v' => '2' ] ], self::db()->query( 'SELECT v FROM wp_options' ) );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'wp\\_keep'" ) );
		$this->assertSame( [], $swap->tablesWithPrefix( 'lwtab12_' ) );
		$this->assertSame( [], $swap->tablesWithPrefix( 'lwoab12_' ) );
	}

	public function test_deferred_views_and_cleanup(): void {
		$dir = TempDir::create();
		$this->exec( 'CREATE TABLE lwtab12_options (option_name VARCHAR(20))' );
		file_put_contents( $dir . '/v.sql', "DROP VIEW IF EXISTS `wp_v`;\nCREATE VIEW `wp_v` AS select `wp_options`.`option_name` AS `option_name` from `wp_options`;\nCREATE VIEW `wp_bad` AS select * from `wp_missing`;\n" );
		$swap = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$swap->swap();
		$warnings = $swap->applyDeferred( $dir . '/v.sql' );

		$this->assertCount( 1, $warnings );
		$this->assertCount( 1, self::db()->query( "SHOW FULL TABLES WHERE `Table_type` = 'VIEW'" ) );

		$this->exec( 'CREATE TABLE lwtab12_x (a INT)', 'CREATE TABLE lwoab12_y (a INT)' );
		$swap->cleanup();
		$this->assertSame( [], $swap->tablesWithPrefix( 'lwtab12_' ) );
		$this->assertSame( [], $swap->tablesWithPrefix( 'lwoab12_' ) );
		TempDir::remove( $dir );
	}

	public function test_apply_deferred_skips_statements_that_are_not_final_views(): void {
		$dir = TempDir::create();
		$this->exec( 'CREATE TABLE wp_options (option_name VARCHAR(20))', 'CREATE TABLE other_t (a INT)' );
		file_put_contents(
			$dir . '/v.sql',
			"DROP TABLE `wp_options`;\nDROP VIEW IF EXISTS `other_v`;\nCREATE OR REPLACE VIEW `wp_o` AS select 1;\nDELETE FROM `wp_options`;\nCREATE DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `wp_árvíz` AS select `option_name` from `wp_options`;\n"
		);
		$swap     = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$warnings = $swap->applyDeferred( $dir . '/v.sql' );

		$this->assertCount( 3, $warnings );
		$this->assertCount( 1, self::db()->query( "SHOW TABLES LIKE 'wp\\_options'" ) );
		$views = array_map( static fn( array $row ): string => (string) array_values( $row )[0], self::db()->query( "SHOW FULL TABLES WHERE `Table_type` = 'VIEW'" ) );
		sort( $views );
		$this->assertSame( [ 'wp_o', 'wp_árvíz' ], $views, 'A site view given as CREATE OR REPLACE is applied like any other.' );
		TempDir::remove( $dir );
	}

	public function test_apply_deferred_replaces_views_but_never_a_table_and_can_run_again(): void {
		$dir = TempDir::create();
		$this->exec(
			'CREATE TABLE wp_options (option_name VARCHAR(20))',
			"INSERT INTO wp_options VALUES ('imported')",
			'CREATE TABLE wp_t (a INT)',
			"CREATE VIEW wp_v AS SELECT 'live' AS option_name"
		);
		file_put_contents( $dir . '/v.sql', "CREATE VIEW `wp_v` AS select `wp_options`.`option_name` AS `option_name` from `wp_options`;\nCREATE VIEW `wp_t` AS select 1 AS `a`;\n" );
		$swap = new TableSwap( self::db(), 'lwtab12_', 'wp_' );

		$first  = $swap->applyDeferred( $dir . '/v.sql' );
		$replay = $swap->applyDeferred( $dir . '/v.sql' );

		$this->assertSame( [ [ 'option_name' => 'imported' ] ], self::db()->query( 'SELECT option_name FROM wp_v' ), 'The live view of the same name is replaced by the imported one.' );
		$this->assertCount( 1, $first, 'A base table of the same name is never replaced by a view.' );
		$this->assertSame( [ [ 'Table_type' => 'BASE TABLE' ] ], self::db()->query( "SELECT TABLE_TYPE AS Table_type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_t'" ) );
		$this->assertCount( 1, $replay );
		$this->assertStringNotContainsString( '1050', implode( ' ', array_merge( $first, $replay ) ) );
		TempDir::remove( $dir );
	}

	public function test_apply_deferred_warns_on_oversized_statement(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/v.sql', 'CREATE VIEW `wp_big` AS select ' . str_repeat( '1,', 9 * 1024 * 1024 ) . "1;\n" );
		$swap     = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$warnings = $swap->applyDeferred( $dir . '/v.sql' );

		$this->assertCount( 1, $warnings );
		$this->assertSame( [], self::db()->query( "SHOW FULL TABLES WHERE `Table_type` = 'VIEW'" ) );
		TempDir::remove( $dir );
	}

	public function test_check_constraint_still_enforced_after_swap(): void {
		$this->exec( 'CREATE TABLE lwtab12_c (n INT, CHECK (n > 0))' );
		$swap = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$swap->swap();

		$this->exec( 'INSERT INTO wp_c VALUES (1)' );
		try {
			$this->exec( 'INSERT INTO wp_c VALUES (-1)' );
			$this->fail( 'CHECK constraint was not enforced after the swap.' );
		} catch ( QueryException $e ) {
			$this->assertSame( [ [ 'n' => '1' ] ], self::db()->query( 'SELECT n FROM wp_c' ) );
		}
	}

	public function test_failed_drop_after_rename_is_a_warning_not_an_exception(): void {
		$this->exec(
			'CREATE TABLE wp_parent (id INT PRIMARY KEY, v INT)',
			'CREATE TABLE wp_child (pid INT, FOREIGN KEY (pid) REFERENCES wp_parent (id))',
			'CREATE TABLE lwtab12_parent (id INT PRIMARY KEY, v INT)',
			'INSERT INTO lwtab12_parent VALUES (1, 42)'
		);
		$swap   = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		$result = $swap->swap();

		$this->assertSame( [ 'wp_parent' ], $result->finals );
		$this->assertCount( 1, $result->warnings );
		$this->assertStringContainsString( 'lwoab12_parent', $result->warnings[0] );
		$this->assertSame( [ [ 'v' => '42' ] ], self::db()->query( 'SELECT v FROM wp_parent' ) );
		$this->assertSame( [ 'lwoab12_parent' ], $swap->tablesWithPrefix( 'lwoab12_' ) );
		$this->assertCount( 1, $swap->cleanup() );
	}

	public function test_failed_rename_throws_and_leaves_live_tables_untouched(): void {
		$this->exec(
			'CREATE TABLE wp_options (v INT)', 'INSERT INTO wp_options VALUES (1)',
			'CREATE VIEW wp_posts AS SELECT v FROM wp_options',
			'CREATE TABLE lwtab12_options (v INT)', 'INSERT INTO lwtab12_options VALUES (2)',
			'CREATE TABLE lwtab12_posts (v INT)'
		);
		$swap = new TableSwap( self::db(), 'lwtab12_', 'wp_' );
		try {
			$swap->swap();
			$this->fail( 'RENAME onto an existing view must fail.' );
		} catch ( QueryException $e ) {
			$this->assertSame( [ [ 'v' => '1' ] ], self::db()->query( 'SELECT v FROM wp_options' ) );
			$this->assertSame( [ 'lwtab12_options', 'lwtab12_posts' ], $swap->tablesWithPrefix( 'lwtab12_' ) );
		}
	}

	public function test_invalid_temp_prefix(): void {
		$this->expectException( DatabaseException::class );
		new TableSwap( self::db(), 'wp_', 'wp_' );
	}

	public function test_new_temp_prefix_shape(): void {
		$this->assertMatchesRegularExpression( '/^lwt[a-z0-9]{4}_$/', TableSwap::newTempPrefix() );
	}

	public function test_held_tables_are_never_swapped_or_dropped_by_the_swap(): void {
		$this->exec(
			'CREATE TABLE wp_options (v INT)',
			'CREATE TABLE wp_users (v INT)',
			'INSERT INTO wp_users VALUES (1)',
			'CREATE TABLE lwtabcd_7_posts (v INT)',
			'CREATE TABLE lwtabcd_lwmsrc_users (v INT)',
			'INSERT INTO lwtabcd_lwmsrc_users VALUES (2)'
		);
		$swap = new TableSwap( self::db(), 'lwtabcd_', 'wp_', [ 'wbk_' ], [ 'lwmsrc_' ] );

		$this->assertSame( [ 'lwtabcd_7_posts' ], $swap->pendingTables() );
		$this->assertSame( [ 'wp_7_posts' ], $swap->plannedFinals() );
		$this->assertSame( [ 'wp_7_posts' ], $swap->swap()->finals );
		$this->assertSame( [ 'lwtabcd_lwmsrc_users', 'wp_7_posts', 'wp_options', 'wp_users' ], $this->tables() );
		$this->assertSame( [ [ 'v' => '1' ] ], self::db()->query( 'SELECT v FROM wp_users' ), 'The live users are not touched.' );
		$this->assertSame( [], $swap->pendingTables(), 'A held table is never pending.' );
		$this->assertSame( [], $swap->cleanup() );
		$this->assertContains( 'lwtabcd_lwmsrc_users', $this->tables(), 'cleanup() keeps the held tables.' );

		$this->assertSame( [], $swap->dropHeld() );
		$this->assertSame( [ 'wp_7_posts', 'wp_options', 'wp_users' ], $this->tables() );

		$this->exec( 'CREATE TABLE lwtabcd_lwmsrc_usermeta (v INT)' );
		( new TableSwap( self::db(), 'lwtabcd_', 'wp_' ) )->cleanup();
		$this->assertSame( [ 'wp_7_posts', 'wp_options', 'wp_users' ], $this->tables(), 'A swap without held suffixes (the failure cleanup) drops the staging too.' );
	}

	public function test_a_subsite_swap_brings_in_only_its_sites_tables_and_views(): void {
		$dir = TempDir::create();
		$this->exec(
			'CREATE TABLE wp_users (v INT)',
			'INSERT INTO wp_users VALUES (1)',
			'CREATE TABLE wp_3_options (v INT)',
			'CREATE TABLE lwtabcd_7_posts (v INT)',
			'CREATE TABLE lwtabcd_users (v INT)',
			'CREATE TABLE lwtabcd_3_options (v INT)',
			'CREATE TABLE lwtabcd_lwmsrc_users (v INT)',
			'CREATE TABLE lwxabcd_wbk_a (v INT)',
			'CREATE VIEW wp_mainview AS SELECT 1 AS a',
			'CREATE VIEW wp_3_view AS SELECT 3 AS a'
		);
		file_put_contents( $dir . '/v.sql', "CREATE VIEW `wp_mainview` AS select 2 AS `a`;\nDROP VIEW IF EXISTS `wp_3_view`;\nCREATE VIEW `wp_lwmsrc_v` AS select 1 AS `a`;\nCREATE VIEW `wp_7_v` AS select `v` from `wp_7_posts`;\n" );
		$swap = new TableSwap( self::db(), 'lwtabcd_', 'wp_', [ 'wbk_' ], [ 'lwmsrc_' ], [ '7_' ] );

		$this->assertSame( [ 'lwtabcd_7_posts' ], $swap->pendingTables() );
		$this->assertSame( [ 'wp_7_posts' ], $swap->swap()->finals );
		$warnings = $swap->applyDeferred( $dir . '/v.sql' );

		$this->assertCount( 4, $warnings, 'A subsite restore creates no view at all, its own included.' );
		$this->assertSame( [ [ 'v' => '1' ] ], self::db()->query( 'SELECT v FROM wp_users' ) );
		$this->assertSame( [ [ 'a' => '1' ] ], self::db()->query( 'SELECT a FROM wp_mainview' ) );
		$this->assertSame( [ [ 'a' => '3' ] ], self::db()->query( 'SELECT a FROM wp_3_view' ) );
		$this->assertNotContains( 'wp_7_v', $this->tables() );
		$this->assertNotContains( 'wp_lwmsrc_v', $this->tables() );
		$swap->cleanup();
		$this->assertSame( [ 'lwtabcd_lwmsrc_users', 'wp_3_options', 'wp_3_view', 'wp_7_posts', 'wp_mainview', 'wp_users' ], $this->tables(), 'Tables of no mapped site are dropped, never swapped; the staging stays.' );
		TempDir::remove( $dir );
	}

	public function test_a_held_suffix_has_the_shape_of_a_prefix(): void {
		foreach ( [ [ [ 'x`' ], [] ], [ [], [ '7' ] ] ] as [ $held, $only ] ) {
			try {
				new TableSwap( self::db(), 'lwtabcd_', 'wp_', [], $held, $only );
				$this->fail( 'Expected a DatabaseException.' );
			} catch ( DatabaseException $e ) {
				$this->assertStringContainsString( 'suffix', $e->getMessage() );
			}
		}
	}

	/** @return array<int, string> */
	private function tables(): array {
		$tables = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $tables );

		return $tables;
	}
}
