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
use LightweightPlugins\Migration\Database\SqlEscape;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImporterUnitTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function run_import( FakeConnection $db, string $sql, ?ImportCursor $cursor = null, ?Deadline $deadline = null ): bool {
		$path = $this->dir . '/db.sql';
		file_put_contents( $path, $sql );
		$plan = new ImportPlan( 'wp_', 'lwtab12_', PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', 1048576 );

		return ( new Importer( $db, new StatementReader( $path ), $plan ) )->run( $cursor ?? new ImportCursor(), $deadline ?? new NoDeadline() );
	}

	private const DUMP = "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=InnoDB;\nSTART TRANSACTION;\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (2);\nCOMMIT;\n";

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function fatalErrnos(): array {
		$out = [];
		foreach ( [ 1213, 1205, 2013, 2006, 4031, 1114, 1021, 1813, 1226, 1030 ] as $errno ) {
			$out[ (string) $errno ] = [ $errno ];
		}

		return $out;
	}

	#[DataProvider( 'fatalErrnos' )]
	public function test_fatal_errors_abort_and_roll_back( int $errno ): void {
		$db = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (1)', $errno );
		try {
			$this->run_import( $db, self::DUMP );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( "stopped the import ({$errno})", $e->getMessage() );
		}
		$this->assertSame( [ 'INSERT INTO `lwtab12_t` VALUES (1);', 'ROLLBACK' ], $db->afterLastStart() );
		$this->assertNotContains( 'COMMIT', $db->log );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function recoverableErrnos(): array {
		return [ '1062' => [ 1062 ], '1146' => [ 1146 ] ];
	}

	#[DataProvider( 'recoverableErrnos' )]
	public function test_recoverable_errors_are_warnings( int $errno ): void {
		$db     = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (1)', $errno );
		$cursor = new ImportCursor();
		$this->assertTrue( $this->run_import( $db, self::DUMP, $cursor ) );
		$this->assertSame( 1, $cursor->warningCount );
		$this->assertStringContainsString( "failed ({$errno})", $cursor->warnings[0] );
		$this->assertSame( 'COMMIT', end( $db->log ) );
	}

	public function test_warnings_locate_a_statement_without_quoting_its_values(): void {
		$long   = str_repeat( 'x', 300 );
		$db     = ( new FakeConnection() )->failOn( "INSERT INTO `lwtab12_t` VALUES ('secret-1'", 1062, PHP_INT_MAX, "Duplicate entry 'secret-1' for key 'PRIMARY'" );
		$cursor = new ImportCursor();
		$dump   = "CREATE TABLE `SERVMASK_PREFIX_t` (`a` text);\n"
			. "INSERT INTO `SERVMASK_PREFIX_t` VALUES ('secret-1','{$long}');\n"
			. "INSERT INTO `other_t` VALUES ('secret-2','{$long}');\n";

		$this->assertTrue( $this->run_import( $db, $dump, $cursor ) );

		$this->assertSame(
			[
				'Statement at byte 45 failed (1062): duplicate entry — INSERT INTO `lwtab12_t`',
				'Skipped a statement that does not target the import tables (byte 401): INSERT INTO `other_t`',
			],
			$cursor->warnings
		);
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public static function serverMessages(): array {
		return [
			'escaped quote in near' => [ 1064, "You have an error in your SQL syntax; check the manual near 'it\\'s secret-1' AND x' at line 1", 'SQL syntax error' ],
			'unbalanced quotes'     => [ 1366, "Incorrect string value: '\\xF0' secret-2 '' for column 'a' at row 1", 'incorrect value' ],
			'unknown code'          => [ 1292, "Truncated incorrect DOUBLE value: 'secret-3'", 'server error' ],
		];
	}

	#[DataProvider( 'serverMessages' )]
	public function test_warnings_never_echo_the_server_message( int $errno, string $message, string $description ): void {
		$db     = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (1)', $errno, PHP_INT_MAX, $message );
		$cursor = new ImportCursor();

		$this->assertTrue( $this->run_import( $db, self::DUMP, $cursor ) );

		$this->assertSame( [ "Statement at byte 77 failed ({$errno}): {$description} — INSERT INTO `lwtab12_t`" ], $cursor->warnings );
	}

	public function test_fatal_errors_name_the_code_not_the_server_message(): void {
		$db = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (1)', 2006, PHP_INT_MAX, "MySQL server has gone away near 'secret-4'" );
		try {
			$this->run_import( $db, self::DUMP );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertSame( 'The database server stopped the import (2006): the connection to the database server was lost', $e->getMessage() );
		}
	}

	public function test_1030_on_create_table_is_fixed_not_fatal(): void {
		$db     = ( new FakeConnection() )->failOn( 'CREATE TABLE', 1030, 1 );
		$cursor = new ImportCursor();
		$this->assertTrue( $this->run_import( $db, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=MyISAM;\n", $cursor ) );
		$this->assertSame( 0, $cursor->warningCount );
	}

	public function test_importer_owns_the_transaction(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$this->assertTrue( $this->run_import( $db, self::DUMP, $cursor ) );
		$this->assertSame( 5, $cursor->statements );
		$this->assertSame(
			[ "SET SESSION sql_mode = ''", 'START TRANSACTION', 'CREATE TABLE `lwtab12_t` (`a` int) ENGINE=InnoDB;', 'START TRANSACTION', 'INSERT INTO `lwtab12_t` VALUES (1);', 'INSERT INTO `lwtab12_t` VALUES (2);', 'COMMIT' ],
			$db->log
		);
	}

	public function test_table_ddl_after_data_ends_the_tick(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$dump   = self::DUMP . "DROP TABLE IF EXISTS `SERVMASK_PREFIX_u`;\n";
		$this->assertFalse( $this->run_import( $db, $dump, $cursor ) );
		$this->assertSame( 5, $cursor->statements );
		$this->assertNotContains( 'DROP TABLE IF EXISTS `lwtab12_u`;', $db->log );
		$this->assertSame( 'COMMIT', end( $db->log ) );

		$db = new FakeConnection();
		$this->assertTrue( $this->run_import( $db, $dump, $cursor ) );
		$this->assertContains( 'DROP TABLE IF EXISTS `lwtab12_u`;', $db->log );
	}

	public function test_commit_failure_on_normal_return_is_fatal(): void {
		$db = ( new FakeConnection() )->failOn( 'COMMIT', 1180 );
		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'Could not commit the imported rows (1180)' );
		$this->run_import( $db, self::DUMP );
	}

	public function test_rollback_failure_does_not_mask_the_fatal_error(): void {
		$db = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (2)', 2006 )->failOn( 'ROLLBACK', 2006 );
		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( 'stopped the import (2006)' );
		$this->run_import( $db, self::DUMP );
	}

	public function test_too_long_temporary_table_name_is_fatal(): void {
		$db = new FakeConnection();
		try {
			$this->run_import( $db, 'CREATE TABLE `SERVMASK_PREFIX_' . str_repeat( 'n', 61 ) . "` (`a` int);\n" );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( 'too long', $e->getMessage() );
		}
		$this->assertSame( 'ROLLBACK', end( $db->log ) );
	}

	public function test_eum_logs_rows_are_skipped(): void {
		$db = new FakeConnection();
		$this->run_import( $db, "INSERT INTO `SERVMASK_PREFIX_eum_logs` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\n" );
		$this->assertNotContains( 'INSERT INTO `lwtab12_eum_logs` VALUES (1);', $db->log );
		$this->assertContains( 'INSERT INTO `lwtab12_t` VALUES (1);', $db->log );
	}

	public function test_create_table_is_retried_with_a_fix(): void {
		$db     = ( new FakeConnection() )->failOn( 'CREATE TABLE', 1071, 1 );
		$cursor = new ImportCursor();
		$this->run_import( $db, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` varchar(255), KEY (`a`)) ENGINE=InnoDB;\n", $cursor );
		$creates = array_values( array_filter( $db->log, static fn( string $s ): bool => str_starts_with( $s, 'CREATE TABLE' ) ) );
		$this->assertCount( 2, $creates );
		$this->assertStringContainsString( 'ROW_FORMAT=DYNAMIC', $creates[1] );
		$this->assertSame( 0, $cursor->warningCount );
	}

	public function test_out_of_scope_statements_are_skipped_with_warnings(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$dump   = "DROP TABLE IF EXISTS `SERVMASK_PREFIX_a`, `wp_live`;\nUPDATE wp_live SET v='x';\nDELETE FROM `SERVMASK_PREFIX_options`;\nINSERT INTO SERVMASK_PREFIX_options VALUES (1);\nDROP VIEW IF EXISTS `other_v`;\nCREATE VIEW `SERVMASK_PREFIX_v` AS select 1;\n";
		$this->run_import( $db, $dump, $cursor );
		$this->assertSame( 5, $cursor->warningCount );
		$this->assertSame( [ "SET SESSION sql_mode = ''", 'START TRANSACTION', 'START TRANSACTION', 'COMMIT' ], $db->log );
		$this->assertSame( "CREATE OR REPLACE VIEW `wp_v` AS select 1;\n", file_get_contents( $this->dir . '/views.sql' ) );
	}

	public function test_a_value_nested_too_deep_is_named_once_per_column_for_the_whole_import(): void {
		$value = 'http://old.test';
		for ( $i = 0; $i < 34; $i++ ) {
			$value = serialize( $value );
		}
		$deep = "'" . SqlEscape::escape( $value ) . "'";
		$dump = "CREATE TABLE `SERVMASK_PREFIX_t` (`a` longtext, `b` longtext) ENGINE=InnoDB;\n";
		for ( $i = 0; $i < 150; $i++ ) {
			$dump .= "INSERT INTO `SERVMASK_PREFIX_t` (`a`, `b`) VALUES ({$deep}, " . ( 0 === $i % 50 ? $deep : "'x'" ) . ");\n";
		}
		$dump .= "UPDATE wp_live SET v='x';\n";
		$path  = $this->dir . '/deep.sql';
		file_put_contents( $path, $dump );
		$plan     = new ImportPlan( 'wp_', 'lwtab12_', PrefixRules::importPairs( 'wp_' ), new Replacer( [ 'http://old.test' => 'https://new.test' ] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', 1048576 );
		$importer = new Importer( new FakeConnection(), new StatementReader( $path ), $plan );
		$cursor   = new ImportCursor();
		while ( ! $importer->run( $cursor, new CountdownDeadline( 20 ) ) ) {
			$cursor = ImportCursor::fromArray( $cursor->toArray() ); // Each tick starts from the saved cursor.
		}

		$deepWarnings = preg_grep( '/nested too deep/', $cursor->warnings );
		$this->assertCount( 2, $deepWarnings, 'One line per column, across ticks.' );
		$this->assertStringContainsString( '`wp_t`.`a`', (string) reset( $deepWarnings ) );
		$this->assertStringContainsString( '`wp_t`.`b`', (string) end( $deepWarnings ) );
		$this->assertCount( 1, preg_grep( '/does not target the import tables/', $cursor->warnings ), 'A later skip warning is still shown.' );
		$this->assertSame( 3, $cursor->warningCount );
	}

	public function test_a_tick_never_ends_between_drop_and_create_table(): void {
		$dump   = "DROP TABLE IF EXISTS `SERVMASK_PREFIX_t`;\n" . self::DUMP;
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$this->assertFalse( $this->run_import( $db, $dump, $cursor, new CountdownDeadline( 0 ) ) );
		$this->assertSame( [ "SET SESSION sql_mode = ''", 'START TRANSACTION', 'DROP TABLE IF EXISTS `lwtab12_t`;', 'START TRANSACTION', 'CREATE TABLE `lwtab12_t` (`a` int) ENGINE=InnoDB;', 'START TRANSACTION', 'COMMIT' ], $db->log );
		$this->assertSame( 2, $cursor->statements );
	}

	public function test_replay_after_a_failed_tick_starts_at_the_drop(): void {
		$dump  = "DROP TABLE IF EXISTS `SERVMASK_PREFIX_t`;\n" . self::DUMP;
		$saved = ( new ImportCursor() )->toArray();
		$db    = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (2)', 2013, 1 );
		$cursor = ImportCursor::fromArray( $saved );
		try {
			$this->run_import( $db, $dump, $cursor );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			unset( $e ); // The job does not save the cursor.
		}
		$replay = ImportCursor::fromArray( $saved );
		$this->assertTrue( $this->run_import( $db, $dump, $replay ) );
		$this->assertSame( 0, $replay->warningCount );
		$this->assertSame( 2, count( array_keys( $db->log, 'DROP TABLE IF EXISTS `lwtab12_t`;', true ) ) );
	}

	public function test_extra_prefix_tables_go_to_the_extra_temp_prefix(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$this->run_import( $db, "DROP TABLE IF EXISTS `wbk_b`;\nCREATE TABLE `wbk_b` (`a` varchar(9)) ENGINE=InnoDB;\nINSERT INTO `wbk_b` VALUES ('`wbk_q`');\n", $cursor );
		$this->assertSame( 0, $cursor->warningCount, implode( "\n", $cursor->warnings ) );
		$this->assertContains( 'DROP TABLE IF EXISTS `lwxab12_wbk_b`;', $db->log );
		$this->assertContains( 'CREATE TABLE `lwxab12_wbk_b` (`a` varchar(9)) ENGINE=InnoDB;', $db->log );
		$this->assertContains( "INSERT INTO `lwxab12_wbk_b` VALUES ('`wbk_q`');", $db->log );
	}

	public function test_hostile_extra_prefix_create_table_is_refused(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$this->run_import( $db, "CREATE TABLE `wbk_x` (`id` int) ENGINE=MERGE UNION=(`wp_users`) INSERT_METHOD=LAST;\n", $cursor );
		$this->assertSame( 1, $cursor->warningCount );
		$this->assertSame( [], array_values( array_filter( $db->log, static fn( string $s ): bool => str_starts_with( $s, 'CREATE' ) ) ) );
	}

	public function test_too_long_extra_temporary_table_name_is_fatal(): void {
		$this->expectException( DatabaseException::class );
		$this->run_import( new FakeConnection(), 'CREATE TABLE `wbk_' . str_repeat( 'n', 57 ) . "` (`a` int);\n" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function createSeparators(): array {
		return [
			'two spaces' => [ '  ' ],
			'tab'        => [ "\t" ],
			'newline'    => [ "\n" ],
			'crlf'       => [ "\r\n" ],
		];
	}

	#[DataProvider( 'createSeparators' )]
	public function test_create_table_with_any_whitespace_is_checked_and_fixed( string $sep ): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$dump   = "CREATE{$sep}TABLE `SERVMASK_PREFIX_m` (id int) ENGINE=MRG_MyISAM UNION=(`wp_live`) INSERT_METHOD=LAST;\n"
			. "CREATE{$sep}TABLE `SERVMASK_PREFIX_s` AS SELECT * FROM `wp_live`;\n"
			. "CREATE{$sep}TABLE `SERVMASK_PREFIX_ok` (`id` int AUTOINCREMENT) ENGINE=InnoDB;\n";
		$this->run_import( $db, $dump, $cursor );

		$creates = array_values( array_filter( $db->log, static fn( string $s ): bool => 0 === stripos( $s, 'CREATE' ) ) );
		$this->assertCount( 1, $creates, implode( "\n", $db->log ) );
		$this->assertStringContainsString( 'AUTO_INCREMENT', $creates[0] );
		$this->assertStringContainsString( '`lwtab12_ok`', $creates[0] );
		$this->assertSame( 2, $cursor->warningCount );
		foreach ( $cursor->warnings as $warning ) {
			$this->assertStringContainsString( 'reach outside the import tables', $warning );
		}
	}

	public function test_a_default_outside_the_allow_list_skips_the_table_with_a_warning_that_names_it(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$this->run_import( $db, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int DEFAULT (sleep(5))) ENGINE=InnoDB;\nCREATE TABLE `SERVMASK_PREFIX_ok` (`a` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;\n", $cursor );

		$creates = array_values( array_filter( $db->log, static fn( string $s ): bool => 0 === stripos( $s, 'CREATE' ) ) );
		$this->assertCount( 1, $creates, implode( "\n", $db->log ) );
		$this->assertStringContainsString( '`lwtab12_ok`', $creates[0] );
		$this->assertSame( 1, $cursor->warningCount );
		$this->assertStringContainsString( 'column default, generated column, CHECK constraint or key part', $cursor->warnings[0] );
		$this->assertStringContainsString( 'CREATE TABLE `lwtab12_t`', $cursor->warnings[0] );
	}

	public function test_a_final_statement_without_semicolon_is_reported_not_imported(): void {
		$db     = new FakeConnection();
		$cursor = new ImportCursor();
		$dump   = "INSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (2,'trunc";
		$this->assertTrue( $this->run_import( $db, $dump, $cursor ) );
		$this->assertNotContains( "INSERT INTO `lwtab12_t` VALUES (2,'trunc", $db->log );
		$this->assertSame( [ 'The database file ends with an incomplete statement (byte 44); it was not imported.' ], $cursor->warnings );
		$this->assertSame( strlen( $dump ), $cursor->offset );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>, 2: string}>
	 */
	public static function badPrefixes(): array {
		return [
			'short temp prefix'      => [ 'lwt_', [], 'Invalid temporary table prefix.' ],
			'live prefix as temp'    => [ 'wp_', [], 'Invalid temporary table prefix.' ],
			'upper-case temp prefix' => [ 'lwtAB12_', [], 'Invalid temporary table prefix.' ],
			'extra with a backtick'  => [ 'lwtab12_', [ 'wbk`_' ], 'Invalid extra table prefix.' ],
			'extra temp-like prefix' => [ 'lwtab12_', [ 'lwxab12_' ], 'Invalid extra table prefix.' ],
			'empty extra prefix'     => [ 'lwtab12_', [ '' ], 'Invalid extra table prefix.' ],
		];
	}

	/**
	 * @param string             $tmp     Temporary prefix.
	 * @param array<int, string> $extra   Extra prefixes.
	 * @param string             $message Expected error.
	 */
	#[DataProvider( 'badPrefixes' )]
	public function test_constructor_refuses_malformed_prefixes( string $tmp, array $extra, string $message ): void {
		$path = $this->dir . '/db.sql';
		file_put_contents( $path, '' );
		$plan = new ImportPlan( 'wp_', $tmp, PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', 1048576, $extra );
		$this->expectException( DatabaseException::class );
		$this->expectExceptionMessage( $message );
		new Importer( new FakeConnection(), new StatementReader( $path ), $plan );
	}

	public function test_default_extra_prefixes_are_shared_with_the_swap(): void {
		$plan = new ImportPlan( 'wp_', 'lwtab12_', [], new Replacer( [] ), new SchemaFixer( '8.0.36' ), '', 1 );
		$this->assertSame( ImportPlan::DEFAULT_EXTRA_PREFIXES, $plan->extraPrefixes );
		$this->assertSame( [ 'wbk_', 'civicrm_' ], ImportPlan::DEFAULT_EXTRA_PREFIXES );
	}

	public function test_a_statement_too_large_for_the_memory_limit_is_skipped_with_a_warning(): void {
		$path = $this->dir . '/big.sql';
		file_put_contents( $path, "INSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_t` VALUES ('" . str_repeat( 'x', 4194304 ) . "');\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (3);\n" );
		$plan     = new ImportPlan( 'wp_', 'lwtab12_', PrefixRules::importPairs( 'wp_' ), new Replacer( [ 'http://old.test' => 'https://new.test' ] ), new SchemaFixer( '8.0.36' ), $this->dir . '/views.sql', 5242880 );
		$db       = new FakeConnection();
		$cursor   = new ImportCursor();
		$importer = new Importer( $db, new StatementReader( $path ), $plan );
		$old      = (string) ini_get( 'memory_limit' );
		try {
			$this->assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 16777216 ) ) );
			$this->assertTrue( $importer->run( $cursor, new NoDeadline() ) );
		} finally {
			ini_set( 'memory_limit', $old );
		}

		$this->assertSame( [ 'INSERT INTO `lwtab12_t` VALUES (1);', 'INSERT INTO `lwtab12_t` VALUES (3);' ], array_values( array_filter( $db->log, static fn( string $s ): bool => str_starts_with( $s, 'INSERT' ) ) ) );
		$this->assertSame( 1, $cursor->warningCount );
		$this->assertMatchesRegularExpression( '/^Skipped a statement at byte 44: too large for the PHP memory limit \(\d+ MB needed\)$/', $cursor->warnings[0] );
	}

	public function test_without_a_memory_limit_large_statements_run(): void {
		$old = (string) ini_get( 'memory_limit' );
		$db  = new FakeConnection();
		try {
			$this->assertNotFalse( ini_set( 'memory_limit', '-1' ) );
			$this->assertTrue( $this->run_import( $db, "INSERT INTO `SERVMASK_PREFIX_t` VALUES ('" . str_repeat( 'x', 100000 ) . "');\n" ) );
		} finally {
			ini_set( 'memory_limit', $old );
		}
		$this->assertCount( 1, array_filter( $db->log, static fn( string $s ): bool => str_starts_with( $s, 'INSERT' ) ) );
	}

	public function test_session_sql_mode_is_restored_after_a_tick(): void {
		$db                                     = new FakeConnection();
		$db->rows['SELECT @@SESSION.sql_mode'] = [ [ 'm' => "STRICT_TRANS_TABLES,NO_ZERO_DATE" ] ];
		$this->assertTrue( $this->run_import( $db, self::DUMP ) );
		$this->assertSame( "SET SESSION sql_mode = ''", $db->log[0] );
		$this->assertSame( [ 'COMMIT', "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE'" ], array_slice( $db->log, -2 ) );
	}

	public function test_session_sql_mode_is_restored_when_the_tick_fails(): void {
		$db                                     = ( new FakeConnection() )->failOn( 'INSERT INTO `lwtab12_t` VALUES (1)', 2006 );
		$db->rows['SELECT @@SESSION.sql_mode'] = [ [ 'm' => 'ANSI_QUOTES' ] ];
		try {
			$this->run_import( $db, self::DUMP );
			$this->fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException $e ) {
			$this->assertStringContainsString( 'stopped the import (2006)', $e->getMessage() );
		}
		$this->assertSame( [ 'ROLLBACK', "SET SESSION sql_mode = 'ANSI_QUOTES'" ], array_slice( $db->log, -2 ) );
	}
}
