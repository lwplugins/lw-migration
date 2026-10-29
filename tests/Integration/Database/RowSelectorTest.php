<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\RowSelector;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;

final class RowSelectorTest extends DatabaseTestCase {

	public function test_describe_and_keyset_batches(): void {
		$this->exec( 'CREATE TABLE wp_k (a INT, b VARCHAR(5), v TEXT, PRIMARY KEY (a, b))' );
		$this->exec( "INSERT INTO wp_k VALUES (1,'x','1x'),(1,'y','1y'),(2,'a','2a')" );
		$sel = new RowSelector( self::db(), new DumpOptions( 'wp_' ) );
		$d   = $sel->describe( 'wp_k' );
		$this->assertSame( [ 'a', 'b' ], $d['keys'] );
		$this->assertSame( 'int', strtolower( substr( $d['columns']['a'], 0, 3 ) ) );

		$first = self::db()->query( $sel->batchSql( 'wp_k', $d, null, 0, 2 ) );
		$this->assertSame( [ '1x', '1y' ], array_column( $first, 'v' ) );
		$next = self::db()->query( $sel->batchSql( 'wp_k', $d, [ '1', 'y' ], 2, 2 ) );
		$this->assertSame( [ '2a' ], array_column( $next, 'v' ) );
	}

	public function test_filters_and_option_rewrites(): void {
		$this->exec(
			'CREATE TABLE wp_options (option_id INT PRIMARY KEY, option_name VARCHAR(191), option_value LONGTEXT, autoload VARCHAR(20))',
			"INSERT INTO wp_options VALUES (1,'active_plugins','a:1:{i:0;s:9:\"x/x.php\";}','yes'),(2,'template','tt','yes'),(3,'ai1wm_status','x','no'),(4,'blogname','B','yes')",
			'CREATE TABLE wp_posts (ID INT PRIMARY KEY, post_type VARCHAR(20))',
			"INSERT INTO wp_posts VALUES (1,'post'),(2,'revision')",
			'CREATE TABLE wp_noid (v INT)',
			'INSERT INTO wp_noid VALUES (3),(1),(2)'
		);
		$sel  = new RowSelector( self::db(), new DumpOptions( 'wp_', true, true ) );
		$rows = self::db()->query( $sel->batchSql( 'wp_options', $sel->describe( 'wp_options' ), null, 0, 100 ) );
		$this->assertSame( [ 'active_plugins', 'template', 'blogname' ], array_column( $rows, 'option_name' ) );
		$this->assertSame( [ 'a:0:{}', '', 'B' ], array_column( $rows, 'option_value' ) );
		$this->assertSame( [ 'option_id', 'option_name', 'option_value', 'autoload' ], array_keys( $rows[0] ) );

		$posts = self::db()->query( $sel->batchSql( 'wp_posts', $sel->describe( 'wp_posts' ), null, 0, 100 ) );
		$this->assertSame( [ '1' ], array_column( $posts, 'ID' ) );

		$noid = self::db()->query( $sel->batchSql( 'wp_noid', $sel->describe( 'wp_noid' ), null, 1, 100 ) );
		$this->assertSame( [ '2', '3' ], array_column( $noid, 'v' ) );
	}

	/**
	 * Pages a table in batches of $size, passing lastKey only when keyset is used.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function page( RowSelector $sel, string $table, int $size ): array {
		$d    = $sel->describe( $table );
		$all  = [];
		$last = null;
		for ( $i = 0; $i < 50; $i++ ) {
			$keyset = $sel->usesKeyset( $d );
			$rows   = self::db()->query( $sel->batchSql( $table, $d, $keyset ? $last : null, count( $all ), $size ) );
			if ( [] === $rows ) {
				return $all;
			}
			foreach ( $rows as $row ) {
				$all[] = $row;
			}
			$end  = end( $rows );
			$last = array_map( fn( string $k ): string => (string) $end[ $k ], $d['keys'] );
		}
		$this->fail( 'paging did not terminate' );
	}

	public function test_float_pk_uses_offset_paging(): void {
		$this->exec( 'CREATE TABLE wp_f (k FLOAT PRIMARY KEY, v INT)', 'INSERT INTO wp_f VALUES (0.3,3),(0.1,1),(0.2,2)' );
		$sel = new RowSelector( self::db(), new DumpOptions( 'wp_' ) );
		$this->assertFalse( $sel->usesKeyset( $sel->describe( 'wp_f' ) ) );
		$this->assertSame( [ '1', '2', '3' ], array_column( $this->page( $sel, 'wp_f', 1 ), 'v' ) );
	}

	public function test_enum_pk_uses_offset_paging(): void {
		$this->exec( "CREATE TABLE wp_e (k ENUM('b','a','c') PRIMARY KEY, v INT)", "INSERT INTO wp_e VALUES ('a',2),('b',1),('c',3)" );
		$sel  = new RowSelector( self::db(), new DumpOptions( 'wp_' ) );
		$expd = array_column( self::db()->query( 'SELECT v FROM wp_e ORDER BY k' ), 'v' );
		$this->assertSame( $expd, array_column( $this->page( $sel, 'wp_e', 1 ), 'v' ) );
		$this->assertCount( 3, $expd );
	}

	public function test_three_column_keyset_matches_ordered_query(): void {
		$this->exec( 'CREATE TABLE wp_c3 (a INT, b INT, c INT, PRIMARY KEY (a,b,c))' );
		$vals = [];
		foreach ( [ 1, 2 ] as $a ) {
			foreach ( [ 1, 2, 3 ] as $b ) {
				foreach ( [ 1, 2 ] as $c ) {
					$vals[] = "($a,$b,$c)";
				}
			}
		}
		$this->exec( 'INSERT INTO wp_c3 VALUES ' . implode( ',', $vals ) );
		$sel = new RowSelector( self::db(), new DumpOptions( 'wp_' ) );
		$this->assertTrue( $sel->usesKeyset( $sel->describe( 'wp_c3' ) ) );
		$this->assertSame( self::db()->query( 'SELECT * FROM wp_c3 ORDER BY a,b,c' ), $this->page( $sel, 'wp_c3', 2 ) );
	}

	public function test_varbinary_pk_keyset_matches_ordered_query(): void {
		$this->exec( 'CREATE TABLE wp_vb (k VARBINARY(10) PRIMARY KEY, v INT)', "INSERT INTO wp_vb VALUES ('b',2),('a',1),('c',3),('ab',4)" );
		$sel = new RowSelector( self::db(), new DumpOptions( 'wp_' ) );
		$this->assertTrue( $sel->usesKeyset( $sel->describe( 'wp_vb' ) ) );
		$this->assertSame( self::db()->query( 'SELECT * FROM wp_vb ORDER BY k' ), $this->page( $sel, 'wp_vb', 2 ) );
	}
}
