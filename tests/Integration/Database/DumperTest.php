<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class DumperTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->exec(
			'CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL, PRIMARY KEY (option_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			"INSERT INTO wp_options VALUES (1,'siteurl','https://old.test','yes'),(2,'wp_user_roles','a:0:{}','yes'),(3,'active_plugins','a:1:{i:0;s:7:\"a/a.php\";}','yes'),(4,'ai1wm_status','x','no')",
			'CREATE TABLE wp_big (id INT NOT NULL PRIMARY KEY, txt VARCHAR(20)) ENGINE=InnoDB',
			'CREATE TABLE wp_bin (id INT NOT NULL PRIMARY KEY, b LONGBLOB) ENGINE=InnoDB',
			"INSERT INTO wp_bin VALUES (1, 0x00FF10), (2, '')",
			'CREATE TABLE wp_empty (id INT) ENGINE=InnoDB',
			'CREATE VIEW wp_view AS SELECT option_name FROM wp_options'
		);
		$values = [];
		for ( $i = 1; $i <= 2500; $i++ ) {
			$values[] = "({$i},'row {$i}')";
		}
		$this->exec( 'INSERT INTO wp_big VALUES ' . implode( ',', $values ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function dumper( string $path ): Dumper {
		return new Dumper( self::db(), new DumpOptions( 'wp_' ), [ 'wp_options', 'wp_big', 'wp_bin', 'wp_empty' ], [ 'wp_view' ], $path );
	}

	public function test_dump_layout(): void {
		$path = $this->dir . '/one.sql';
		$this->assertTrue( $this->dumper( $path )->run( new DumpCursor(), new NoDeadline() ) );
		$sql = (string) file_get_contents( $path );

		$this->assertStringStartsWith( "-- LW Migration SQL Dump\n", $sql );
		$this->assertStringContainsString( "\nDROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\nCREATE TABLE `SERVMASK_PREFIX_options` (", $sql );
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'SERVMASK_PREFIX_user_roles','a:0:{}','yes');\n", $sql );
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (3,'active_plugins','a:0:{}','yes');\n", $sql );
		$this->assertStringNotContainsString( 'ai1wm_status', $sql );
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_bin` VALUES (1,0x00ff10);\nINSERT INTO `SERVMASK_PREFIX_bin` VALUES (2,'');\n", $sql );
		$this->assertStringContainsString( "\nDROP TABLE IF EXISTS `SERVMASK_PREFIX_empty`;\nCREATE TABLE `SERVMASK_PREFIX_empty` (", $sql );
		$this->assertStringContainsString( "\nDROP VIEW IF EXISTS `SERVMASK_PREFIX_view`;\nCREATE VIEW `SERVMASK_PREFIX_view` AS ", $sql );
		$this->assertStringContainsString( '`SERVMASK_PREFIX_options`', substr( $sql, (int) strpos( $sql, 'CREATE VIEW' ) ) );

		// wp_big: 2500 rows → 3 START, 3 COMMIT; options 3 rows → 1+1; bin 2 rows → 1+1; empty → 0.
		$this->assertSame( 5, substr_count( $sql, "START TRANSACTION;\n" ) );
		$this->assertSame( 5, substr_count( $sql, "COMMIT;\n" ) );
		$this->assertStringContainsString( "VALUES (1000,'row 1000');\nCOMMIT;\nSTART TRANSACTION;\nINSERT INTO `SERVMASK_PREFIX_big` VALUES (1001,", $sql );
	}

	public function test_resume_is_byte_identical(): void {
		$one = $this->dir . '/one.sql';
		$this->dumper( $one )->run( new DumpCursor(), new NoDeadline() );

		$two    = $this->dir . '/two.sql';
		$cursor = new DumpCursor();
		$ticks  = 0;
		while ( ! $this->dumper( $two )->run( $cursor, new CountdownDeadline( 0 ) ) ) {
			$cursor = DumpCursor::fromArray( $cursor->toArray() );
			file_put_contents( $two, 'CRASH GARBAGE', FILE_APPEND );
			++$ticks;
		}
		$this->assertGreaterThan( 3, $ticks );
		$this->assertSame( file_get_contents( $one ), file_get_contents( $two ) );
	}

	public function test_spatial_columns_are_hex_encoded_and_round_trip(): void {
		$this->exec(
			'CREATE TABLE wp_geo (id INT NOT NULL PRIMARY KEY, p POINT NOT NULL, g GEOMETRYCOLLECTION NOT NULL) ENGINE=InnoDB',
			"INSERT INTO wp_geo VALUES (1, ST_GeomFromText('POINT(1 2)'), ST_GeomFromText('GEOMETRYCOLLECTION(POINT(1 2),LINESTRING(0 0,1 1))'))"
		);
		$before = self::db()->query( 'SELECT HEX(p) AS p, HEX(g) AS g FROM wp_geo' )[0];
		$path   = $this->dir . '/geo.sql';
		$dump   = new Dumper( self::db(), new DumpOptions( 'wp_' ), [ 'wp_geo' ], [], $path );
		$this->assertTrue( $dump->run( new DumpCursor(), new NoDeadline() ) );
		$sql = (string) file_get_contents( $path );
		$this->assertMatchesRegularExpression( '/VALUES \(1,0x[0-9a-f]+,0x[0-9a-f]+\);/', $sql );

		$this->exec( 'TRUNCATE TABLE wp_geo' );
		$this->assertSame( 1, preg_match( '/^INSERT INTO `SERVMASK_PREFIX_geo`.*$/m', $sql, $m ) );
		$this->exec( str_replace( 'SERVMASK_PREFIX_', 'wp_', $m[0] ) );
		$this->assertSame( [ $before ], self::db()->query( 'SELECT HEX(p) AS p, HEX(g) AS g FROM wp_geo' ) );
	}

	public function test_exactly_one_batch_of_rows_has_one_transaction(): void {
		$this->exec( 'CREATE TABLE wp_thou (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', 'INSERT INTO wp_thou VALUES ' . implode( ',', array_map( static fn( int $i ): string => "({$i})", range( 1, 1000 ) ) ) );
		$path = $this->dir . '/thou.sql';
		$this->assertTrue( ( new Dumper( self::db(), new DumpOptions( 'wp_' ), [ 'wp_thou' ], [], $path ) )->run( new DumpCursor(), new NoDeadline() ) );
		$sql = (string) file_get_contents( $path );
		$this->assertSame( 1, substr_count( $sql, "START TRANSACTION;\n" ) );
		$this->assertSame( 1, substr_count( $sql, "COMMIT;\n" ) );
		$this->assertSame( 1000, substr_count( $sql, 'INSERT INTO' ) );
	}

	public function test_table_without_primary_key_is_dumped_once_per_row(): void {
		$this->exec( 'CREATE TABLE wp_nokey (a INT, b VARCHAR(10)) ENGINE=InnoDB', 'INSERT INTO wp_nokey VALUES ' . implode( ',', array_map( static fn( int $i ): string => "({$i},'r{$i}')", range( 1, 1500 ) ) ) );
		$path = $this->dir . '/nokey.sql';
		$cursor = new DumpCursor();
		$dumper = new Dumper( self::db(), new DumpOptions( 'wp_' ), [ 'wp_nokey' ], [], $path );
		while ( ! $dumper->run( $cursor, new CountdownDeadline( 0 ) ) ) {
			$cursor = DumpCursor::fromArray( $cursor->toArray() );
		}
		$sql = (string) file_get_contents( $path );
		$this->assertSame( 1500, substr_count( $sql, 'INSERT INTO' ) );
		for ( $i = 1; $i <= 1500; $i += 499 ) {
			$this->assertSame( 1, substr_count( $sql, "VALUES ({$i},'r{$i}');" ) );
		}
		$this->assertSame( 2, substr_count( $sql, "START TRANSACTION;\n" ) );
	}

	public function test_refuses_unsafe_connection_charset(): void {
		$this->exec( 'SET NAMES gbk' );
		try {
			$this->expectException( DatabaseException::class );
			$this->expectExceptionMessage( 'uses the gbk character set' );
			$this->dumper( $this->dir . '/gbk.sql' )->run( new DumpCursor(), new NoDeadline() );
		} finally {
			$this->exec( 'SET NAMES utf8mb4' );
		}
	}

	public function test_large_rows_yield_inside_a_batch_and_output_is_identical(): void {
		$this->exec( 'CREATE TABLE wp_fat (id INT NOT NULL PRIMARY KEY, b LONGTEXT) ENGINE=InnoDB' );
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->exec( "INSERT INTO wp_fat VALUES ({$i}, REPEAT('x', 1048576))" );
		}
		$make = fn( string $path ): Dumper => new Dumper( self::db(), new DumpOptions( 'wp_' ), [ 'wp_fat' ], [], $path );
		$one  = $this->dir . '/fat1.sql';
		$make( $one )->run( new DumpCursor(), new NoDeadline() );

		$two    = $this->dir . '/fat2.sql';
		$cursor = new DumpCursor();
		$ticks  = 0;
		while ( ! $make( $two )->run( $cursor, new CountdownDeadline( 0 ) ) ) {
			$cursor = DumpCursor::fromArray( $cursor->toArray() );
			++$ticks;
		}
		$this->assertGreaterThan( 5, $ticks );
		$this->assertSame( md5_file( $one ), md5_file( $two ) );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $two ), "COMMIT;\n" ) );
	}

	public function test_session_sql_mode_is_restored_after_dump(): void {
		$this->exec( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO'" );
		try {
			$this->assertTrue( $this->dumper( $this->dir . '/mode.sql' )->run( new DumpCursor(), new NoDeadline() ) );
			$this->assertSame( 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO', self::db()->query( 'SELECT @@SESSION.sql_mode AS m' )[0]['m'] );
		} finally {
			$this->exec( 'SET SESSION sql_mode = DEFAULT' );
		}
	}
}
