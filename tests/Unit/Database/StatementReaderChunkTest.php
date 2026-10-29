<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Tests\Support\ShortReadStream;
use LightweightPlugins\Migration\Tests\Support\SqlReferenceSplitter;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Reads start small and grow, and bytes read ahead serve the next statement:
 * the results must stay exactly those of the whole-line reference.
 */
final class StatementReaderChunkTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		ShortReadStream::register();
		ShortReadStream::reset();
	}

	protected function tearDown(): void {
		ShortReadStream::reset();
		TempDir::remove( $this->dir );
	}

	private function write( string $sql ): string {
		file_put_contents( $this->dir . '/d.sql', $sql );

		return $this->dir . '/d.sql';
	}

	public function test_a_large_packet_limit_does_not_read_the_file_again_for_every_statement(): void {
		$sql = '';
		for ( $i = 0; $i < 300; $i++ ) {
			$sql .= "INSERT INTO `a` VALUES ($i,'" . str_repeat( 'v', 200 ) . "');\n";
		}
		ShortReadStream::$max = PHP_INT_MAX;
		$reader               = new StatementReader( ShortReadStream::url( $this->write( $sql ) ) );

		$this->assertCount( 300, $this->readAll( $reader, 67108864 ) );
		$this->assertLessThan( 2 * strlen( $sql ), ShortReadStream::$bytesRead );
	}

	public function test_statement_spanning_several_small_chunks_is_returned_whole(): void {
		$long   = "INSERT INTO `a` VALUES ('" . str_repeat( 'x', 1000 ) . "',\n'" . str_repeat( 'y', 500 ) . "');";
		$reader = new StatementReader( $this->write( "-- c\n" . $long . "\nCOMMIT;\n" ), 65536, 16 );

		$this->assertSame(
			[ [ $long, 5, 6 + strlen( $long ), false, false ], [ 'COMMIT;', 6 + strlen( $long ), 14 + strlen( $long ), false, false ] ],
			$this->readAll( $reader, 4096 )
		);
	}

	public function test_statements_ending_exactly_at_chunk_boundaries(): void {
		// First chunk 8, then 16, 32: the lines end at 8, 24 and 56.
		$sql = "INS 12;\n" . 'INSERT ' . str_repeat( 'q', 7 ) . ";\n" . 'INSERT ' . str_repeat( 'r', 23 ) . ";\nEND;\n";
		foreach ( [ 1, 2, 7, 8, 9, 16 ] as $first ) {
			$reader = new StatementReader( $this->write( $sql ), 65536, $first );
			$this->assertSame( SqlReferenceSplitter::split( $sql, 1000, 65536 ), $this->readAll( $reader, 1000 ), "First chunk $first" );
			$this->assertSame( [ 8, 24, 56, 61 ], array_column( $this->readAll( $reader, 1000 ), 2 ) );
		}
	}

	public function test_statement_over_max_bytes_is_refused_as_before(): void {
		$big = "INSERT INTO `a` VALUES ('" . str_repeat( 'x', 5000 ) . "');";
		foreach ( [ 16, 1024, 65536 ] as $first ) {
			$reader = new StatementReader( $this->write( $big . "\nCOMMIT;\n" ), 50, $first );
			$this->assertSame(
				[ [ '', 0, strlen( $big ) + 1, true, false ], [ 'COMMIT;', strlen( $big ) + 1, strlen( $big ) + 9, false, false ] ],
				$this->readAll( $reader, 1000 ),
				"First chunk $first"
			);
		}
	}

	public function test_crlf_quotes_and_escapes_across_chunk_boundaries(): void {
		$sql = "-- head\r\nINSERT INTO `a` VALUES ('it\\'s;', \"q;\\\"\");\r\n\r\n"
			. "INSERT INTO `a` VALUES ('multi\r\nline;\r\n', 'x\\\\');\r\n  -- note;\r\nCOMMIT;\r\n";
		$expected = SqlReferenceSplitter::split( $sql, 1000, 65536 );
		$this->assertCount( 4, $expected );
		for ( $first = 1; $first <= strlen( $sql ) + 1; $first++ ) {
			$reader = new StatementReader( $this->write( $sql ), 65536, $first );
			$this->assertSame( $expected, $this->readAll( $reader, 1000 ), "First chunk $first" );
		}
	}

	public function test_resume_from_stored_offsets_in_any_order(): void {
		mt_srand( 4321 );
		$path = $this->dir . '/fuzz.sql';
		for ( $it = 0; $it < 300; $it++ ) {
			$sql   = SqlReferenceSplitter::randomDump();
			$max   = mt_rand( 3, 60 );
			$slack = mt_rand( 1, 20 );
			file_put_contents( $path, $sql );
			$expected = SqlReferenceSplitter::split( $sql, $max, $slack );
			$byStart  = [];
			foreach ( $expected as $index => $row ) {
				$byStart[ 0 === $index ? 0 : $expected[ $index - 1 ][2] ] = $row;
			}
			$offsets = array_keys( $byStart );
			shuffle( $offsets );

			$shared = new StatementReader( $path, $slack, mt_rand( 1, 40 ) );
			foreach ( $offsets as $offset ) {
				$fresh = new StatementReader( $path, $slack, mt_rand( 1, 40 ) );
				$this->assertSame( $byStart[ $offset ], $this->row( $fresh->next( $offset, $max ) ), "Case $it, fresh reader at $offset" );
				$fresh->close();
				$this->assertSame( $byStart[ $offset ], $this->row( $shared->next( $offset, $max ) ), "Case $it, shared reader at $offset" );
			}
			$shared->close();
		}
	}

	public function test_fuzz_with_small_growing_chunks_matches_reference(): void {
		mt_srand( 99 );
		$path = $this->dir . '/fuzz.sql';
		for ( $it = 0; $it < 500; $it++ ) {
			$sql   = SqlReferenceSplitter::randomDump();
			$max   = mt_rand( 3, 200 );
			$slack = mt_rand( 1, 200 );
			file_put_contents( $path, $sql );

			$reader = new StatementReader( $path, $slack, mt_rand( 1, 12 ) );
			$this->assertSame( SqlReferenceSplitter::split( $sql, $max, $slack ), $this->readAll( $reader, $max ), "Case $it" );
			$reader->close();
		}
	}

	/**
	 * @return array{string,int,int,bool,bool}
	 */
	private function row( ?\LightweightPlugins\Migration\Database\Statement $s ): array {
		$this->assertNotNull( $s );

		return [ $s->sql, $s->start, $s->end, $s->oversized, $s->truncated ];
	}

	/**
	 * @return list<array{string,int,int,bool,bool}>
	 */
	private function readAll( StatementReader $reader, int $max ): array {
		$out = [];
		$off = 0;
		while ( null !== ( $s = $reader->next( $off, $max ) ) ) {
			$out[] = $this->row( $s );
			$this->assertGreaterThan( $off, $s->end, 'The reader must make progress.' );
			$off = $s->end;
		}

		return $out;
	}
}
