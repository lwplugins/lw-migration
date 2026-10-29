<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Database;

use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\Dumper;
use LightweightPlugins\Migration\Database\DumpOptions;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SchemaFixer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Dump with the real Dumper, import with the real Importer, compare with the source.
 */
final class DumpRoundTripTest extends DatabaseTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function roundTrip( string ...$tables ): ImportCursor {
		$path = $this->dir . '/database.sql';
		$ok   = ( new Dumper( self::db(), new DumpOptions( 'wp_' ), array_values( $tables ), [], $path ) )->run( new DumpCursor(), new NoDeadline() );
		$this->assertTrue( $ok );
		$plan   = new ImportPlan( 'wp_', 'lwt1a2b_', PrefixRules::importPairs( 'wp_' ), new Replacer( [] ), new SchemaFixer( self::db()->serverVersion() ), $this->dir . '/views.sql', 1048576 );
		$cursor = new ImportCursor();
		for ( $i = 0; $i < 50 && ! ( new Importer( self::db(), new StatementReader( $path ), $plan ) )->run( $cursor, new NoDeadline() ); $i++ ) {
			continue;
		}

		return $cursor;
	}

	/**
	 * @param string $sql Query.
	 * @return array<int, array<string, string|null>>
	 */
	private function rows( string $sql ): array {
		return self::db()->query( $sql );
	}

	public function test_bit_columns_round_trip_numerically(): void {
		$this->exec(
			'CREATE TABLE wp_bits (id INT PRIMARY KEY, a BIT(1), b BIT(10), c BIT(64) NULL) ENGINE=InnoDB',
			"INSERT INTO wp_bits VALUES (1, b'1', b'1010101010', b'1111111111111111111111111111111111111111111111111111111111111111'), (2, b'0', b'0', NULL), (3, NULL, NULL, NULL)"
		);
		$cursor = $this->roundTrip( 'wp_bits' );
		$this->assertSame( 0, $cursor->warningCount, implode( "\n", $cursor->warnings ) );
		$sql = (string) file_get_contents( $this->dir . '/database.sql' );
		$this->assertStringContainsString( 'VALUES (1,1,682,18446744073709551615)', $sql );
		$q = 'SELECT id, CAST(a AS UNSIGNED) AS a, CAST(b AS UNSIGNED) AS b, CAST(c AS UNSIGNED) AS c FROM ';
		$this->assertSame( $this->rows( $q . 'wp_bits ORDER BY id' ), $this->rows( $q . 'lwt1a2b_bits ORDER BY id' ) );
		$this->assertSame( '682', $this->rows( $q . 'lwt1a2b_bits WHERE id = 1' )[0]['b'] );
		$this->assertSame( '18446744073709551615', $this->rows( $q . 'lwt1a2b_bits WHERE id = 1' )[0]['c'] );
		$this->assertNull( $this->rows( $q . 'lwt1a2b_bits WHERE id = 3' )[0]['c'] );
	}

	public function test_bit_column_in_a_keyless_table_and_options(): void {
		$this->exec(
			'CREATE TABLE wp_nokey (f BIT(3), t VARCHAR(5)) ENGINE=InnoDB',
			"INSERT INTO wp_nokey VALUES (b'101','x'), (b'010','y')"
		);
		$this->assertSame( 0, $this->roundTrip( 'wp_nokey' )->warningCount );
		$this->assertSame( [ '5', '2' ], array_column( $this->rows( 'SELECT CAST(f AS UNSIGNED) AS f FROM lwt1a2b_nokey ORDER BY t' ), 'f' ) );
	}

	public function test_generated_columns_are_not_dumped_and_rows_survive(): void {
		$this->exec(
			'CREATE TABLE wp_gen (id INT PRIMARY KEY, a INT NOT NULL, b INT NOT NULL, v INT AS (a + b) VIRTUAL, s VARCHAR(20) AS (CONCAT(a, "-", b)) STORED, note VARCHAR(10)) ENGINE=InnoDB',
			"INSERT INTO wp_gen (id, a, b, note) VALUES (1, 2, 3, 'x'), (2, 10, 20, NULL)",
			'CREATE TABLE wp_plain (id INT PRIMARY KEY, a INT) ENGINE=InnoDB',
			'INSERT INTO wp_plain VALUES (1, 1)'
		);
		$cursor = $this->roundTrip( 'wp_gen', 'wp_plain' );
		$this->assertSame( 0, $cursor->warningCount, implode( "\n", $cursor->warnings ) );
		$sql = (string) file_get_contents( $this->dir . '/database.sql' );
		$this->assertStringContainsString( "INSERT INTO `SERVMASK_PREFIX_gen` (`id`,`a`,`b`,`note`) VALUES (1,2,3,'x');", $sql );
		$this->assertStringContainsString( 'INSERT INTO `SERVMASK_PREFIX_plain` VALUES (1,1);', $sql );
		$q = 'SELECT id, a, b, v, s, note FROM ';
		$this->assertSame( $this->rows( $q . 'wp_gen ORDER BY id' ), $this->rows( $q . 'lwt1a2b_gen ORDER BY id' ) );
		$this->assertSame( '5', $this->rows( $q . 'lwt1a2b_gen WHERE id = 1' )[0]['v'] );
		$this->assertSame( '10-20', $this->rows( $q . 'lwt1a2b_gen WHERE id = 2' )[0]['s'] );
	}

	public function test_generated_primary_key_falls_back_to_offset_paging(): void {
		if ( false !== stripos( self::db()->serverVersion(), 'maria' ) ) {
			$this->markTestSkipped( 'MariaDB does not allow a primary key on a generated column.' );
		}
		$this->exec(
			'CREATE TABLE wp_gk (a INT NOT NULL, k INT AS (a * 2) STORED, PRIMARY KEY (k)) ENGINE=InnoDB',
			'INSERT INTO wp_gk (a) VALUES (1), (2), (3)'
		);
		$this->assertSame( 0, $this->roundTrip( 'wp_gk' )->warningCount );
		$this->assertSame( [ '2', '4', '6' ], array_column( $this->rows( 'SELECT k FROM lwt1a2b_gk ORDER BY k' ), 'k' ) );
	}
}
