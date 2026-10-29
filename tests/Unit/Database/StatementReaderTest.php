<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Tests\Support\SqlReferenceSplitter;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class StatementReaderTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function reader( string $sql ): StatementReader {
		file_put_contents( $this->dir . '/d.sql', $sql );

		return new StatementReader( $this->dir . '/d.sql' );
	}

	public function test_splits_dump_statements_and_skips_header(): void {
		$sql = "-- header\n--\n\nDROP TABLE IF EXISTS `a`;\nCREATE TABLE `a` (\n  `x` int\n) ENGINE=InnoDB;\n\nSTART TRANSACTION;\nINSERT INTO `a` VALUES (1);\nCOMMIT;\n";
		$r   = $this->reader( $sql );
		$out = [];
		$off = 0;
		while ( null !== ( $s = $r->next( $off, 1000 ) ) ) {
			$out[] = $s->sql;
			$off   = $s->end;
		}
		$this->assertSame(
			[ 'DROP TABLE IF EXISTS `a`;', "CREATE TABLE `a` (\n  `x` int\n) ENGINE=InnoDB;", 'START TRANSACTION;', 'INSERT INTO `a` VALUES (1);', 'COMMIT;' ],
			$out
		);
		$this->assertSame( strlen( $sql ), $off );
	}

	public function test_semicolon_inside_value_line_is_not_an_end_unless_at_line_end(): void {
		$r = $this->reader( "INSERT INTO `a` VALUES ('x;y');\nINSERT INTO `a` VALUES ('z');   \n" );
		$s = $r->next( 0, 1000 );
		$this->assertSame( "INSERT INTO `a` VALUES ('x;y');", $s->sql );
		$this->assertSame( "INSERT INTO `a` VALUES ('z');", $r->next( $s->end, 1000 )->sql );
	}

	public function test_oversized_statement_is_flagged_and_skipped(): void {
		$big = 'INSERT INTO `a` VALUES (\'' . str_repeat( 'x', 5000 ) . "');\nCOMMIT;\n";
		$r   = $this->reader( $big );
		$s   = $r->next( 0, 1000 );
		$this->assertTrue( $s->oversized );
		$this->assertSame( '', $s->sql );
		$this->assertSame( 0, $s->start );
		$this->assertSame( 'COMMIT;', $r->next( $s->end, 1000 )->sql );
	}

	public function test_trailing_text_without_semicolon_is_a_truncated_statement(): void {
		$r = $this->reader( "COMMIT;\n-- end\ngarbage" );
		$s = $r->next( 0, 100 );
		$this->assertFalse( $s->truncated );
		$t = $r->next( $s->end, 100 );
		$this->assertNotNull( $t );
		$this->assertTrue( $t->truncated );
		$this->assertSame( 'garbage', $t->sql );
		$this->assertSame( 15, $t->start );
		$this->assertSame( 22, $t->end );
		$this->assertNull( $r->next( $t->end, 100 ) );
	}

	public function test_truncated_multi_line_statement_and_blank_or_comment_tails(): void {
		$r = $this->reader( "INSERT INTO `a` VALUES (1);\nINSERT INTO `a` VALUES (2,'trunc\nmore\n" );
		$s = $r->next( 0, 1000 );
		$t = $r->next( $s->end, 1000 );
		$this->assertTrue( $t->truncated );
		$this->assertSame( 28, $t->start );
		$this->assertSame( "INSERT INTO `a` VALUES (2,'trunc\nmore", $t->sql );

		$r = $this->reader( "COMMIT;\n\n  \n-- end\n" );
		$this->assertNull( $r->next( $r->next( 0, 100 )->end, 100 ) );
	}

	public function test_single_line_larger_than_max_slack_is_flagged(): void {
		$large = 'INSERT INTO `a` VALUES (\'' . str_repeat( 'x', 300000 ) . "');\nCOMMIT;\n";
		$r     = $this->reader( $large );
		$s     = $r->next( 0, 1000 );
		$this->assertTrue( $s->oversized );
		$this->assertSame( '', $s->sql );
		$this->assertSame( 0, $s->start );
		// Next statement should be COMMIT.
		$next = $r->next( $s->end, 1000 );
		$this->assertNotNull( $next );
		$this->assertSame( 'COMMIT;', $next->sql );
	}

	public function test_oversized_spanning_multiple_lines(): void {
		$big = "INSERT INTO `a` VALUES (\n" . str_repeat( "  'x',\n", 10000 ) . "  'y'\n);\nCOMMIT;\n";
		$r   = $this->reader( $big );
		$s   = $r->next( 0, 1000 );
		$this->assertTrue( $s->oversized );
		$this->assertSame( '', $s->sql );
		$next = $r->next( $s->end, 1000 );
		$this->assertNotNull( $next );
		$this->assertSame( 'COMMIT;', $next->sql );
	}

	public function test_semicolon_at_chunk_boundary(): void {
		// Create a statement where the semicolon is near the chunk boundary.
		// With slack=50 and maxBytes=100, chunk size = min(150, 8MB) + 1 = 151.
		// This line is exactly 101 bytes: crosses maxBytes but stays under chunk.
		$line = 'INSERT INTO `a` VALUES (\'' . str_repeat( 'x', 63 ) . "');\n";
		file_put_contents( $this->dir . '/d.sql', $line . "COMMIT;\n" );
		$r = new StatementReader( $this->dir . '/d.sql', 50 );
		$s = $r->next( 0, 100 );
		// Statement should be recognized despite being over maxBytes (but under slack threshold).
		$this->assertSame( trim( $line ), $s->sql );
		$next = $r->next( $s->end, 100 );
		$this->assertSame( 'COMMIT;', $next->sql );
	}

	public function test_crlf_line_endings(): void {
		$r = $this->reader( "-- header\r\nINSERT INTO `a` VALUES (1);\r\nCOMMIT;\r\n" );
		$s = $r->next( 0, 1000 );
		$this->assertSame( "INSERT INTO `a` VALUES (1);", $s->sql );
		$next = $r->next( $s->end, 1000 );
		$this->assertSame( 'COMMIT;', $next->sql );
	}

	public function test_final_line_without_newline(): void {
		$r = $this->reader( "COMMIT;" );
		$s = $r->next( 0, 1000 );
		$this->assertSame( 'COMMIT;', $s->sql );
		$this->assertNull( $r->next( $s->end, 1000 ) );
	}

	public function test_resume_from_mid_file_offset(): void {
		$sql = "DROP TABLE IF EXISTS `a`;\nINSERT INTO `a` VALUES (1);\nCOMMIT;\n";
		$r   = $this->reader( $sql );
		// Get first statement to find where it ends.
		$s1 = $r->next( 0, 1000 );
		$this->assertSame( 'DROP TABLE IF EXISTS `a`;', $s1->sql );
		// Resume from $s1->end.
		$s2 = $r->next( $s1->end, 1000 );
		$this->assertSame( 'INSERT INTO `a` VALUES (1);', $s2->sql );
		// Resume from $s2->end.
		$s3 = $r->next( $s2->end, 1000 );
		$this->assertSame( 'COMMIT;', $s3->sql );
	}

	public function test_huge_line_with_memory_limit(): void {
		if ( disk_free_space( sys_get_temp_dir() ) < 1073741824 ) { // 1 GB
			$this->markTestSkipped( 'Insufficient disk space for 300 MB test file' );
		}

		// Create a 300 MB single-line INSERT statement.
		$path = $this->dir . '/huge.sql';
		$h    = fopen( $path, 'wb' );
		$this->assertNotFalse( $h, 'Failed to open huge.sql for writing' );

		fwrite( $h, "INSERT INTO `t` VALUES ('" );
		$mb = str_repeat( 'x', 1048576 );
		for ( $i = 0; $i < 300; $i++ ) {
			fwrite( $h, $mb );
		}
		unset( $mb );
		fwrite( $h, "');\nCOMMIT;\n" );
		fclose( $h );

		$origLimit = ini_get( 'memory_limit' );
		try {
			ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 67108864 ) ); // Usage + 64 MB.

			$startPeak = memory_get_peak_usage( true );
			$r         = new StatementReader( $path );
			$s         = $r->next( 0, 1048576 );
			$endPeak   = memory_get_peak_usage( true );
			$next      = $r->next( $s->end, 1048576 );
			$r->close();

			$this->assertTrue( $s->oversized, 'Expected oversized=true for 300 MB line' );
			$this->assertSame( '', $s->sql );
			$this->assertSame( 0, $s->start );
			$this->assertSame( filesize( $path ) - 8, $s->end );
			$this->assertNotNull( $next );
			$this->assertSame( 'COMMIT;', $next->sql );

			$peakGrowth = $endPeak - $startPeak;
			$this->assertLessThan( 33554432, $peakGrowth, "Peak memory growth {$peakGrowth} exceeds 32 MB" );
		} finally {
			ini_set( 'memory_limit', (string) $origLimit );
		}
	}

	public function test_20mb_statement_returned_intact_with_bounded_memory(): void {
		$path = $this->dir . '/d.sql';
		$h    = fopen( $path, 'wb' );
		$this->assertNotFalse( $h );
		fwrite( $h, "INSERT INTO `t` VALUES ('" );
		$mb = str_repeat( 'y', 1048576 );
		for ( $i = 0; $i < 20; $i++ ) {
			fwrite( $h, $mb );
		}
		unset( $mb );
		fwrite( $h, "');\nCOMMIT;\n" );
		fclose( $h );
		$length = filesize( $path ) - 9; // Trimmed statement: without "\nCOMMIT;\n".
		$hash   = md5( (string) file_get_contents( $path, false, null, 0, $length ) );

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$r      = new StatementReader( $path );
		$s      = $r->next( 0, 67108864 ); // 64 MB.
		$growth = memory_get_peak_usage() - $before;
		$r->close();

		$this->assertFalse( $s->oversized );
		$this->assertSame( $length, strlen( $s->sql ) );
		$this->assertSame( $hash, md5( $s->sql ) );
		$this->assertSame( $length + 1, $s->end );
		$this->assertLessThan( 3 * $length, $growth, "Peak memory growth {$growth} exceeds 3x the statement size" );
	}

	public function test_eof_without_newline_offset(): void {
		// Test: "COMMIT;" (7 bytes, no newline) should have end=7
		$r = $this->reader( 'COMMIT;' );
		$s = $r->next( 0, 100 );
		$this->assertSame( 'COMMIT;', $s->sql );
		$this->assertSame( 7, $s->end );

		// Test: "A;\nB;   " (no final newline) second statement should have end=8
		$r2 = $this->reader( "A;\nB;   " );
		$s1 = $r2->next( 0, 100 );
		$this->assertSame( 'A;', $s1->sql );
		$s2 = $r2->next( $s1->end, 100 );
		$this->assertSame( 'B;', $s2->sql );
		$this->assertSame( 8, $s2->end );
	}

	/**
	 * Lines split across chunk boundaries (slack 5, max 10 => 16-byte chunks).
	 *
	 * @dataProvider provide_chunk_boundary_cases
	 *
	 * @param string                           $sql      File contents.
	 * @param list<array{string,int,int,bool,bool}> $expected Statements as [sql, start, end, oversized, truncated].
	 */
	public function test_lines_split_across_chunks_are_classified_whole( string $sql, array $expected ): void {
		file_put_contents( $this->dir . '/d.sql', $sql );

		$this->assertSame( $expected, $this->readAll( new StatementReader( $this->dir . '/d.sql', 5 ), 10 ) );
	}

	/**
	 * @return array<string, array{string, list<array{string,int,int,bool,bool}>}>
	 */
	public static function provide_chunk_boundary_cases(): array {
		return [
			'blank lines spanning chunks'     => [ str_repeat( "\n", 15 ) . " B;\n", [ [ 'B;', 15, 19, false, false ] ] ],
			'comment after blank chunk'       => [ str_repeat( "\n", 15 ) . "-- c;\nB;\n", [ [ 'B;', 21, 24, false, false ] ] ],
			'"--" split by a chunk boundary'  => [ str_repeat( ' ', 15 ) . "-- c;\nB;\n", [ [ 'B;', 21, 24, false, false ] ] ],
			'long comment line spans chunks'  => [ '-- ' . str_repeat( 'c', 30 ) . "\nB;\n", [ [ 'B;', 34, 37, false, false ] ] ],
			'lone "-" at a chunk boundary'    => [ str_repeat( ' ', 15 ) . "-1;\n", [ [ '', 0, 19, true, false ] ] ],
		];
	}

	public function test_fuzz_matches_reference_splitter(): void {
		mt_srand( 1234 );
		$path = $this->dir . '/fuzz.sql';

		for ( $it = 0; $it < 500; $it++ ) {
			$sql   = SqlReferenceSplitter::randomDump();
			$max   = mt_rand( 3, 60 );
			$slack = mt_rand( 1, 20 );
			file_put_contents( $path, $sql );

			$reader = new StatementReader( $path, $slack );
			$actual = $this->readAll( $reader, $max );
			$reader->close();

			$this->assertSame(
				SqlReferenceSplitter::split( $sql, $max, $slack ),
				$actual,
				sprintf( 'Case %d (max %d, slack %d): %s', $it, $max, $slack, (string) json_encode( $sql ) )
			);
		}
	}

	/**
	 * @return list<array{string,int,int,bool,bool}>
	 */
	private function readAll( StatementReader $reader, int $max ): array {
		$out = [];
		$off = 0;
		while ( null !== ( $s = $reader->next( $off, $max ) ) ) {
			$out[] = [ $s->sql, $s->start, $s->end, $s->oversized, $s->truncated ];
			$this->assertGreaterThan( $off, $s->end, 'The reader must make progress.' );
			$off = $s->end;
		}

		return $out;
	}
}
