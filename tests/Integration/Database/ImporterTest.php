<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

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
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class ImporterTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function dump(): string {
		$roles = 'a:1:{s:13:\"administrator\";a:1:{s:4:\"name\";s:13:\"Administrator\";}}';
		$ser   = serialize( [ 'u' => 'http://old.test/x' ] );
		$lines = [
			'-- LW Migration SQL Dump',
			'--',
			'',
			'DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;',
			'CREATE TABLE `SERVMASK_PREFIX_options` (',
			'  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,',
			'  `option_name` varchar(191) NOT NULL,',
			'  `option_value` longtext NOT NULL,',
			'  PRIMARY KEY (`option_id`)',
			') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;',
			'',
			'START TRANSACTION;',
			"INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'siteurl','http://old.test');",
			"INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'SERVMASK_PREFIX_user_roles','{$roles}');",
			"INSERT INTO `SERVMASK_PREFIX_options` VALUES (3,'_transient_x','1');",
			"INSERT INTO `SERVMASK_PREFIX_options` VALUES (4,'w','" . \LightweightPlugins\Migration\Database\SqlEscape::escape( $ser ) . "');",
			'COMMIT;',
			'',
			'DROP VIEW IF EXISTS `SERVMASK_PREFIX_v`;',
			'CREATE VIEW `SERVMASK_PREFIX_v` AS select `SERVMASK_PREFIX_options`.`option_name` AS `option_name` from `SERVMASK_PREFIX_options`;',
			'',
		];
		$path = $this->dir . '/database.sql';
		file_put_contents( $path, implode( "\n", $lines ) );

		return $path;
	}

	private function plan( int $maxPacket = 1048576, string $tmpPrefix = 'lwt1a2b_' ): ImportPlan {
		$fixer = new SchemaFixer( self::db()->serverVersion() );

		return new ImportPlan( 'wp_', $tmpPrefix, PrefixRules::importPairs( 'wp_' ), new Replacer( [ 'http://old.test' => 'https://new.test' ] ), $fixer, $this->dir . '/views.sql', $maxPacket );
	}

	public function test_imports_into_temporary_tables_with_final_prefix_in_data(): void {
		$path     = $this->dump();
		$importer = new Importer( self::db(), new StatementReader( $path ), $this->plan() );
		$cursor   = new ImportCursor();
		$this->assertTrue( $importer->run( $cursor, new NoDeadline() ) );

		$rows = self::db()->query( 'SELECT option_id, option_name, option_value FROM lwt1a2b_options ORDER BY option_id' );
		$this->assertSame( [ '1', '2', '4' ], array_column( $rows, 'option_id' ) );
		$this->assertSame( 'https://new.test', $rows[0]['option_value'] );
		$this->assertSame( 'wp_user_roles', $rows[1]['option_name'] );
		$this->assertSame( [ 'u' => 'https://new.test/x' ], unserialize( $rows[2]['option_value'] ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'wp\\_%'" ) );

		$views = (string) file_get_contents( $this->dir . '/views.sql' );
		$this->assertStringContainsString( 'CREATE OR REPLACE VIEW `wp_v` AS select `wp_options`.`option_name`', $views );
		$this->assertSame( 0, $cursor->warningCount );
	}

	public function test_resume_mid_transaction_keeps_rows(): void {
		$path   = $this->dump();
		$cursor = new ImportCursor();
		$ticks  = 0;
		do {
			$cursor   = ImportCursor::fromArray( $cursor->toArray() );
			$importer = new Importer( self::db(), new StatementReader( $path ), $this->plan() );
			$done     = $importer->run( $cursor, new CountdownDeadline( 0 ) );
			++$ticks;
		} while ( ! $done );

		$this->assertGreaterThan( 5, $ticks );
		$this->assertCount( 3, self::db()->query( 'SELECT 1 FROM lwt1a2b_options' ) );
	}

	public function test_oversized_and_failing_statements_become_warnings(): void {
		$path = $this->dir . '/bad.sql';
		file_put_contents( $path, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_t` VALUES ('" . str_repeat( 'x', 3000 ) . "');\nINSERT INTO `SERVMASK_PREFIX_nope` VALUES (1);\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (7);\n" );
		$cursor = new ImportCursor();
		( new Importer( self::db(), new StatementReader( $path ), $this->plan( 1024 ) ) )->run( $cursor, new NoDeadline() );

		$this->assertSame( 2, $cursor->warningCount );
		$this->assertStringContainsString( 'larger than', $cursor->warnings[0] );
		$this->assertStringContainsString( '1146', $cursor->warnings[1] );
		$this->assertSame( [ [ 'a' => '7' ] ], self::db()->query( 'SELECT a FROM lwt1a2b_t' ) );
	}

	public function test_server_errors_are_reported_without_the_values_they_quote(): void {
		$path = $this->dir . '/errors.sql';
		file_put_contents(
			$path,
			"CREATE TABLE `SERVMASK_PREFIX_t` (`a` varchar(20) PRIMARY KEY, `n` int) ENGINE=InnoDB;\n"
			. "INSERT INTO `SERVMASK_PREFIX_t` VALUES ('secret-1', 1);\n"
			. "INSERT INTO `SERVMASK_PREFIX_t` VALUES ('secret-1', 2);\n" // 1062 Duplicate entry.
			. "CREATE TABLE `SERVMASK_PREFIX_u` (`a` varchar(20) DEFAULT 'secret-2' NOT NULL 'secret-3');\n" // 1064 near '...'.
		);
		$cursor = new ImportCursor();
		for ( $runs = 0; $runs < 10 && ! ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() ); $runs++ ) {
			continue; // One run per table boundary.
		}

		$this->assertSame( 2, $cursor->warningCount, implode( ' | ', $cursor->warnings ) );
		$this->assertSame(
			[
				'Statement at byte 143 failed (1062): duplicate entry — INSERT INTO `lwt1a2b_t`',
				'Statement at byte 199 failed (1064): SQL syntax error — CREATE TABLE `lwt1a2b_u`',
			],
			$cursor->warnings,
			'The server\'s own text (with the values it quotes) never reaches a warning.'
		);
	}

	public function test_refuses_unsafe_connection_charset(): void {
		$path = $this->dump();
		$this->exec( 'SET NAMES gbk' );
		try {
			$this->expectException( DatabaseException::class );
			$this->expectExceptionMessage( 'uses the gbk character set' );
			( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( new ImportCursor(), new NoDeadline() );
		} finally {
			$this->exec( 'SET NAMES utf8mb4' );
		}
	}

	public function test_double_quoted_values_are_kept_and_single_quoted_ones_replaced(): void {
		$path = $this->dir . '/literal.sql';
		file_put_contents( $path, "CREATE TABLE `SERVMASK_PREFIX_t` (`a` int, `b` varchar(40), `c` varchar(40), `d` varchar(40)) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_t` VALUES (5, \"it's http://old.test\", 'http://old.test/a', \"don't\");\n" );
		$cursor = new ImportCursor();
		$this->assertTrue( ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() ) );

		$this->assertSame( 0, $cursor->warningCount, implode( "\n", $cursor->warnings ) );
		$this->assertSame( [ [ 'a' => '5', 'b' => "it's http://old.test", 'c' => 'https://new.test/a', 'd' => "don't" ] ], self::db()->query( 'SELECT a, b, c, d FROM lwt1a2b_t' ) );
	}

	public function test_warnings_with_binary_bytes_stay_short_and_json_safe(): void {
		$binary = "\xff\xfe\x80\xc3" . str_repeat( 'y', 400 );
		$path   = $this->dir . '/binary.sql';
		file_put_contents( $path, "INSERT INTO `SERVMASK_PREFIX_nope` VALUES ('{$binary}');\nINSERT INTO `SERVMASK_PREFIX_nope` VALUES ('{$binary}' '{$binary}' x;\n" );
		$cursor = new ImportCursor();
		( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() );

		$this->assertSame( 2, $cursor->warningCount );
		$json = json_encode( $cursor->toArray(), JSON_THROW_ON_ERROR );
		$this->assertIsString( $json );
		foreach ( $cursor->warnings as $warning ) {
			$this->assertSame( 1, preg_match( '//u', $warning ) );
			$this->assertLessThan( 1200, strlen( $warning ) );
		}
	}

	public function test_statements_outside_the_import_tables_never_run(): void {
		$this->exec( 'CREATE TABLE wp_live (id int primary key, v varchar(20))', "INSERT INTO wp_live VALUES (1,'orig'),(2,'keep')" );
		$path = $this->dir . '/hostile.sql';
		file_put_contents( $path, "CREATE TABLE `SERVMASK_PREFIX_x` (a int);\nUPDATE wp_live SET v='pwned' WHERE id=1;\nDELETE FROM `wp_live` WHERE id=2;\nCREATE TABLE wp_new (a int);\nINSERT INTO lwm_test.wp_live VALUES (3,'injected');\nINSERT INTO SERVMASK_PREFIX_live VALUES (4,'unquoted');\nINSERT INTO `SERVMASK_PREFIX_x` VALUES (1);\n" );
		$cursor = new ImportCursor();
		$plan   = $this->plan();
		do {
			$done = ( new Importer( self::db(), new StatementReader( $path ), $plan ) )->run( $cursor, new NoDeadline() );
		} while ( ! $done );

		$this->assertSame( [ [ 'id' => '1', 'v' => 'orig' ], [ 'id' => '2', 'v' => 'keep' ] ], self::db()->query( 'SELECT id, v FROM wp_live ORDER BY id' ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'wp\\_new'" ) );
		$this->assertSame( [ [ 'a' => '1' ] ], self::db()->query( 'SELECT a FROM lwt1a2b_x' ) );
		$this->assertSame( 5, $cursor->warningCount );
		foreach ( $cursor->warnings as $warning ) {
			$this->assertStringContainsString( 'does not target the import tables', $warning );
		}
	}

	public function test_create_table_options_that_reach_outside_are_rejected(): void {
		$this->exec(
			'CREATE TABLE wp_live (id int, v varchar(20)) ENGINE=MyISAM',
			"INSERT INTO wp_live VALUES (1,'orig')",
			'CREATE DATABASE IF NOT EXISTS lwm_other',
			'DROP TABLE IF EXISTS lwm_other.secret',
			'CREATE TABLE lwm_other.secret (id int, v varchar(20)) ENGINE=MyISAM',
			"INSERT INTO lwm_other.secret VALUES (1,'s3cret')"
		);
		$names = [ 'm', 'n', 'c', 'l', 'p', 'f', 'd', 'h', 'q' ];
		$lines = [
			'CREATE TABLE `SERVMASK_PREFIX_m` (id int, v varchar(20)) ENGINE=MERGE UNION=(wp_live) INSERT_METHOD=LAST;',
			"INSERT INTO `SERVMASK_PREFIX_m` VALUES (2,'via-merge');",
			'CREATE TABLE `SERVMASK_PREFIX_n` (id int, v varchar(20)) ENGINE=MRG_MyISAM UNION=(lwm_other.secret) INSERT_METHOD=LAST;',
			"INSERT INTO `SERVMASK_PREFIX_n` VALUES (3,'other-db');",
			'CREATE TABLE `SERVMASK_PREFIX_c` AS SELECT * FROM lwm_other.secret;',
			'CREATE TABLE `SERVMASK_PREFIX_l` LIKE wp_live;',
			'CREATE TABLE `SERVMASK_PREFIX_p` (LIKE wp_live);',
			"CREATE TABLE `SERVMASK_PREFIX_f` (id int) ENGINE=FEDERATED CONNECTION='mysql://root:lwm@127.0.0.1:3306/lwm_test/wp_live';",
			"CREATE TABLE `SERVMASK_PREFIX_d` (id int) ENGINE=MyISAM DATA DIRECTORY='/tmp';",
			'CREATE TABLE `SERVMASK_PREFIX_h` (id int, v varchar(20)) COMMENT "\'" ENGINE=MERGE UNION=(wp_live) INSERT_METHOD=LAST COMMENT "\'";',
			"INSERT INTO `SERVMASK_PREFIX_h` VALUES (4,'hidden');",
			'CREATE TABLE `SERVMASK_PREFIX_q`.`wp_live` (id int);',
			'CREATE TABLE `SERVMASK_PREFIX_e` (id int, v varchar(20)) /*!50000 ENGINE=MERGE UNION=(wp_live) INSERT_METHOD=LAST */;',
			"INSERT INTO `SERVMASK_PREFIX_e` VALUES (5,'comment');",
		];
		$path = $this->dir . '/outside.sql';
		file_put_contents( $path, implode( "\n", $lines ) . "\n" );
		$cursor = new ImportCursor();
		try {
			do {
				$done = ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() );
			} while ( ! $done );

			$this->assertSame( [ [ 'id' => '1', 'v' => 'orig' ] ], self::db()->query( 'SELECT id, v FROM wp_live' ) );
			$this->assertSame( [ [ 'id' => '1', 'v' => 's3cret' ] ], self::db()->query( 'SELECT id, v FROM lwm_other.secret' ) );
			foreach ( $names as $name ) {
				$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwt1a2b\\_{$name}'" ), $name );
			}
			$this->assertSame( [ [ 'id' => '5', 'v' => 'comment' ] ], self::db()->query( 'SELECT id, v FROM lwt1a2b_e' ) );
			$rejected = array_filter( $cursor->warnings, static fn( string $w ): bool => str_contains( $w, 'reach outside the import tables' ) );
			$this->assertCount( 9, $rejected );
		} finally {
			$this->exec( 'DROP DATABASE IF EXISTS lwm_other' );
		}
	}

	public function test_inserts_with_anything_but_literal_values_never_run(): void {
		$this->exec(
			'CREATE TABLE wp_live (id int, v varchar(20)) ENGINE=MyISAM',
			"INSERT INTO wp_live VALUES (1,'orig')",
			'CREATE DATABASE IF NOT EXISTS lwm_other',
			'DROP TABLE IF EXISTS lwm_other.secret',
			'CREATE TABLE lwm_other.secret (id int, v varchar(20)) ENGINE=MyISAM',
			"INSERT INTO lwm_other.secret VALUES (1,'s3cret')",
			'DROP FUNCTION IF EXISTS lwm_f',
			"CREATE FUNCTION lwm_f() RETURNS int DETERMINISTIC MODIFIES SQL DATA BEGIN INSERT INTO wp_live VALUES (9,'fn'); RETURN 1; END"
		);
		$x     = '`SERVMASK_PREFIX_x`';
		$lines = [
			"CREATE TABLE {$x} (id int primary key, v varchar(20)) ENGINE=InnoDB;",
			"INSERT INTO {$x} VALUES (1,'a') /*!50000 , (2, (SELECT v FROM lwm_other.secret LIMIT 1)) */;",
			"INSERT INTO {$x} VALUES (10,(SELECT v FROM lwm_other.secret LIMIT 1));",
			"INSERT INTO {$x} (id, v /* ) VALUES */) SELECT id + 20, v FROM lwm_other.secret;",
			"INSERT INTO {$x} VALUES (1,'a') ON DUPLICATE KEY UPDATE v=(SELECT v FROM lwm_other.secret LIMIT 1);",
			"INSERT INTO {$x} VALUES (20, lwm_test.lwm_f());",
			"INSERT INTO {$x} VALUES (30, 'ok'), (31, _utf8mb4'ok2');",
		];
		$path = $this->dir . '/values.sql';
		file_put_contents( $path, implode( "\n", $lines ) . "\n" );
		$cursor = new ImportCursor();
		try {
			do {
				$done = ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() );
			} while ( ! $done );

			$this->assertSame( [ [ 'id' => '1', 'v' => 'orig' ] ], self::db()->query( 'SELECT id, v FROM wp_live' ) );
			$this->assertSame( [ [ 'id' => '30', 'v' => 'ok' ], [ 'id' => '31', 'v' => 'ok2' ] ], self::db()->query( 'SELECT id, v FROM lwt1a2b_x ORDER BY id' ) );
			$this->assertSame( 5, $cursor->warningCount );
		} finally {
			$this->exec( 'DROP DATABASE IF EXISTS lwm_other', 'DROP FUNCTION IF EXISTS lwm_f' );
		}
	}

	public function test_named_check_constraints_do_not_collide_with_the_live_tables(): void {
		$this->exec(
			'CREATE TABLE wp_x (a int, b int, CONSTRAINT `c1` CHECK (a > 0))',
			'INSERT INTO wp_x VALUES (1, 1)'
		);
		$path = $this->dir . '/check.sql';
		file_put_contents(
			$path,
			"DROP TABLE IF EXISTS `SERVMASK_PREFIX_x`;\nCREATE TABLE `SERVMASK_PREFIX_x` (\n  `a` int DEFAULT NULL,\n  `b` int DEFAULT NULL,\n  CONSTRAINT `c1` CHECK ((`a` > 0)),\n  CONSTRAINT `wp_x_chk_1` CHECK ((`b` < 100))\n) ENGINE=InnoDB;\nINSERT INTO `SERVMASK_PREFIX_x` VALUES (5,6),(7,8);\n"
		);
		$import = function ( string $tmpPrefix ) use ( $path ): ImportCursor {
			$cursor = new ImportCursor();
			do {
				$done = ( new Importer( self::db(), new StatementReader( $path ), $this->plan( 1048576, $tmpPrefix ) ) )->run( $cursor, new NoDeadline() );
			} while ( ! $done );

			return $cursor;
		};

		$cursor = $import( 'lwt1a2b_' );
		$this->assertSame( [], $cursor->warnings );
		$this->assertSame( [ [ 'a' => '5' ], [ 'a' => '7' ] ], self::db()->query( 'SELECT a FROM lwt1a2b_x ORDER BY a' ) );

		// The swap (Task 12): the live table moves aside, the import table takes its name.
		$this->exec( 'RENAME TABLE wp_x TO lwold_x, lwt1a2b_x TO wp_x' );
		$cursor = $import( 'lwt9z8y_' );
		$this->assertSame( [], $cursor->warnings );
		$this->assertSame( [ [ 'a' => '5' ], [ 'a' => '7' ] ], self::db()->query( 'SELECT a FROM lwt9z8y_x ORDER BY a' ) );
		$this->expectException( \LightweightPlugins\Migration\Database\QueryException::class );
		self::db()->execute( 'INSERT INTO lwt9z8y_x VALUES (0, 1)' );
	}

	public function test_extra_prefix_tables_are_imported_and_replace_the_live_ones(): void {
		$this->exec(
			'CREATE TABLE wbk_bookings (id INT PRIMARY KEY, name VARCHAR(20))',
			"INSERT INTO wbk_bookings VALUES (9, 'live')",
			'CREATE TABLE wp_options (option_name VARCHAR(20))'
		);
		$path = $this->dir . '/extra.sql';
		file_put_contents(
			$path,
			"DROP TABLE IF EXISTS `wbk_bookings`;\nCREATE TABLE `wbk_bookings` (\n  `id` int NOT NULL,\n  `name` varchar(40) DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n\n"
			. "START TRANSACTION;\nINSERT INTO `wbk_bookings` VALUES (1,'`wbk_x` http://old.test');\nCOMMIT;\n"
			. "CREATE TABLE `wbk_m` (`id` int) ENGINE=MERGE UNION=(`wp_options`) INSERT_METHOD=LAST;\n"
		);
		$cursor = new ImportCursor();
		$done   = false;
		for ( $i = 0; $i < 10 && ! $done; $i++ ) {
			$done = ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() );
		}
		$this->assertTrue( $done );
		$this->assertSame( 1, $cursor->warningCount );
		$this->assertStringContainsString( 'CREATE TABLE whose options reach outside', $cursor->warnings[0] );

		$swap   = new TableSwap( self::db(), 'lwt1a2b_', 'wp_', [ 'wbk_', 'civicrm_' ] );
		$result = $swap->swap();
		$this->assertSame( [], $result->warnings );
		$this->assertSame( [ 'wbk_bookings' ], $result->finals );
		$this->assertSame( [], $swap->cleanup() );
		$this->assertSame( [ [ 'id' => '1', 'name' => '`wbk_x` https://new.test' ] ], self::db()->query( 'SELECT id, name FROM wbk_bookings' ) );
		$names = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
		sort( $names );
		$this->assertSame( [ 'wbk_bookings', 'wp_options' ], $names );
	}

	public function test_create_table_with_unusual_whitespace_never_reaches_the_live_tables(): void {
		$this->exec( 'CREATE TABLE wp_live (id int primary key, v text) ENGINE=MyISAM', "INSERT INTO wp_live VALUES (1,'live')" );
		$lines = [];
		foreach ( [ 'a' => '  ', 'b' => "\t", 'c' => "\n", 'd' => "\r\n" ] as $n => $sep ) {
			$lines[] = "CREATE{$sep}TABLE `SERVMASK_PREFIX_m{$n}` (id int NOT NULL, v text, PRIMARY KEY (id)) ENGINE=MRG_MyISAM UNION=(`wp_live`) INSERT_METHOD=LAST;";
			$lines[] = "INSERT INTO `SERVMASK_PREFIX_m{$n}` VALUES (2,'pwned');";
			$lines[] = "CREATE{$sep}TABLE `SERVMASK_PREFIX_s{$n}` AS SELECT * FROM `wp_live`;";
		}
		$path = $this->dir . '/whitespace.sql';
		file_put_contents( $path, implode( "\n", $lines ) . "\n" );
		$cursor = new ImportCursor();
		$done   = false;
		for ( $i = 0; $i < 20 && ! $done; $i++ ) {
			$done = ( new Importer( self::db(), new StatementReader( $path ), $this->plan() ) )->run( $cursor, new NoDeadline() );
		}
		$this->assertTrue( $done );

		$this->assertSame( [ [ 'id' => '1', 'v' => 'live' ] ], self::db()->query( 'SELECT id, v FROM wp_live' ) );
		$this->assertSame( [], self::db()->query( "SHOW TABLES LIKE 'lwt1a2b\\_%'" ) );
		$rejected = array_filter( $cursor->warnings, static fn( string $w ): bool => str_contains( $w, 'reach outside the import tables' ) );
		$this->assertCount( 8, $rejected );
	}

	public function test_session_sql_mode_is_restored_after_import(): void {
		$this->exec( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO'" );
		try {
			$this->assertTrue( ( new Importer( self::db(), new StatementReader( $this->dump() ), $this->plan() ) )->run( new ImportCursor(), new NoDeadline() ) );
			$this->assertSame( 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO', self::db()->query( 'SELECT @@SESSION.sql_mode AS m' )[0]['m'] );
		} finally {
			$this->exec( 'SET SESSION sql_mode = DEFAULT' );
		}
	}
}

