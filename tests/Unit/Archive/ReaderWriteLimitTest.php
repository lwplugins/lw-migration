<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Entry;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9: the total an extraction writes is capped, and an entry never
 * writes more than its header allows where the header size is exact.
 */
final class ReaderWriteLimitTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/** @param array<string, string> $files Entry => contents. */
	private function archive( ?ChunkCodec $codec, array $files ): string {
		$path   = $this->dir . '/t.wpress';
		$writer = new Writer( $path, $codec );
		$writer->addString( 'package.json', '{}', 1 );
		foreach ( $files as $name => $data ) {
			$writer->addString( $name, $data, 1 );
		}
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		return $path;
	}

	private function entry( Reader $reader, string $name ): Entry {
		$entry = $reader->find( $name );
		$this->assertNotNull( $entry );

		return $entry;
	}

	/** @return array<int, string> Files left in the output folder. */
	private function leftovers(): array {
		return array_values( array_diff( (array) scandir( $this->dir . '/out' ), [ '.', '..' ] ) );
	}

	public function test_an_entry_that_would_pass_the_limit_is_refused_and_its_temporary_file_removed(): void {
		$reader = new Reader( $this->archive( null, [ 'a.bin' => str_repeat( 'a', 2000 ) ] ) );
		mkdir( $this->dir . '/out' );
		$budget = new WriteBudget( 1999 );
		try {
			$reader->extractTo( $this->entry( $reader, 'a.bin' ), $this->dir . '/out/a.bin', new ExtractCursor(), new NoDeadline(), $budget );
			$this->fail( 'Expected a WriteLimitException.' );
		} catch ( WriteLimitException $e ) {
			$this->assertSame( [], $this->leftovers(), 'Nothing is left behind, not even the temporary file.' );
			$this->assertSame( 0, $budget->written(), 'The refused chunk is not counted.' );
		} finally {
			$reader->close();
		}
	}

	public function test_the_limit_is_a_running_total_over_entries(): void {
		$reader = new Reader( $this->archive( null, [ 'a.bin' => str_repeat( 'a', 600 ), 'b.bin' => str_repeat( 'b', 600 ) ] ) );
		mkdir( $this->dir . '/out' );
		$budget = new WriteBudget( 1000 );
		try {
			$this->assertTrue( $reader->extractTo( $this->entry( $reader, 'a.bin' ), $this->dir . '/out/a.bin', new ExtractCursor(), new NoDeadline(), $budget ) );
			$this->assertSame( 600, $budget->written() );
			$this->expectException( WriteLimitException::class );
			$reader->extractTo( $this->entry( $reader, 'b.bin' ), $this->dir . '/out/b.bin', new ExtractCursor(), new NoDeadline(), $budget );
		} finally {
			$reader->close();
		}
	}

	public function test_a_compressed_entry_is_stopped_at_the_limit_before_it_inflates_on_disk(): void {
		$zeros  = str_repeat( "\0", 5 * Format::CHUNK_SIZE );
		$reader = new Reader( $this->archive( new ChunkCodec( Codec::GZIP ), [ 'bomb.bin' => $zeros ] ), new ChunkCodec( Codec::GZIP ) );
		mkdir( $this->dir . '/out' );
		$entry  = $this->entry( $reader, 'bomb.bin' );
		$budget = new WriteBudget( 2 * Format::CHUNK_SIZE );
		try {
			$this->assertLessThan( Format::CHUNK_SIZE / 100, $entry->size, 'The stored entry is tiny.' );
			$reader->extractTo( $entry, $this->dir . '/out/bomb.bin', new ExtractCursor(), new NoDeadline(), $budget );
			$this->fail( 'Expected a WriteLimitException.' );
		} catch ( WriteLimitException $e ) {
			$this->assertSame( 2 * Format::CHUNK_SIZE, $budget->written(), 'Two chunks fit, the third is refused before it is written.' );
			$this->assertSame( [], $this->leftovers() );
		} finally {
			$reader->close();
		}
	}

	public function test_a_resumed_entry_counts_against_the_total_saved_between_requests(): void {
		$data   = random_bytes( 3 * Format::CHUNK_SIZE );
		$reader = new Reader( $this->archive( null, [ 'big.bin' => $data ] ) );
		mkdir( $this->dir . '/out' );
		$entry  = $this->entry( $reader, 'big.bin' );
		$cursor = new ExtractCursor();
		$budget = new WriteBudget( 3 * Format::CHUNK_SIZE - 1 );
		try {
			$this->assertFalse( $reader->extractTo( $entry, $this->dir . '/out/big.bin', $cursor, new CountdownDeadline( 0 ), $budget ) );
			$saved = $budget->toArray();
			$this->assertSame( Format::CHUNK_SIZE, $saved['written'] );

			$this->expectException( WriteLimitException::class );
			$reader->extractTo( $entry, $this->dir . '/out/big.bin', $cursor, new NoDeadline(), WriteBudget::fromArray( $saved ) );
		} finally {
			$reader->close();
		}
	}

	public function test_a_replaced_file_is_refunded_only_after_the_rename(): void {
		$reader = new Reader( $this->archive( null, [ 'same.bin' => str_repeat( 'n', 3000 ), 'grown.bin' => str_repeat( 'g', 3000 ), 'new.bin' => str_repeat( 'w', 500 ) ] ) );
		mkdir( $this->dir . '/out' );
		file_put_contents( $this->dir . '/out/same.bin', str_repeat( 'o', 3000 ) );
		file_put_contents( $this->dir . '/out/grown.bin', str_repeat( 'o', 1000 ) );
		$budget = new WriteBudget( 3500 );
		$after  = [];
		try {
			foreach ( [ 'same.bin', 'grown.bin', 'new.bin' ] as $name ) {
				$this->assertTrue( $reader->extractTo( $this->entry( $reader, $name ), $this->dir . '/out/' . $name, new ExtractCursor(), new NoDeadline(), $budget ) );
				$after[ $name ] = $budget->written();
			}
		} finally {
			$reader->close();
		}
		$this->assertSame( [ 'same.bin' => 0, 'grown.bin' => 2000, 'new.bin' => 2500 ], $after, 'Equal size: nothing once in place; larger: the difference; new: in full.' );
		$this->assertSame( str_repeat( 'n', 3000 ), file_get_contents( $this->dir . '/out/same.bin' ) );
	}

	public function test_a_replacement_larger_than_the_room_is_refused_while_it_is_written(): void {
		$reader = new Reader( $this->archive( null, [ 'same.bin' => str_repeat( 'n', 3000 ) ] ) );
		mkdir( $this->dir . '/out' );
		file_put_contents( $this->dir . '/out/same.bin', str_repeat( 'o', 3000 ) );
		$budget = new WriteBudget( 2999 );
		try {
			$this->expectException( WriteLimitException::class );
			$reader->extractTo( $this->entry( $reader, 'same.bin' ), $this->dir . '/out/same.bin', new ExtractCursor(), new NoDeadline(), $budget );
		} finally {
			$reader->close();
			$this->assertSame( str_repeat( 'o', 3000 ), file_get_contents( $this->dir . '/out/same.bin' ) );
		}
	}

	public function test_the_same_path_repeated_in_one_archive_cannot_pass_the_limit(): void {
		$path   = $this->dir . '/t.wpress';
		$writer = new Writer( $path );
		$writer->addString( 'package.json', '{}', 1 );
		$writer->addString( 'a.bin', str_repeat( 'a', 600 ), 1 );
		$writer->addString( 'a.bin', str_repeat( 'b', 600 ), 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		$reader = new Reader( $path );
		mkdir( $this->dir . '/out' );
		$budget = new WriteBudget( 1000 );
		$done   = 0;
		try {
			foreach ( $reader->entries() as $entry ) {
				if ( 'a.bin' === $entry->name ) {
					$reader->extractTo( $entry, $this->dir . '/out/a.bin', new ExtractCursor(), new NoDeadline(), $budget );
					++$done;
				}
			}
			$this->fail( 'Expected a WriteLimitException.' );
		} catch ( WriteLimitException $e ) {
			$this->assertSame( 1, $done, 'The second copy needs room next to the first until its rename.' );
			$this->assertSame( 600, $budget->written() );
		} finally {
			$reader->close();
		}
	}

	public function test_the_replaced_size_survives_a_resumed_entry_and_is_refunded_after_the_rename(): void {
		$data   = random_bytes( 3 * Format::CHUNK_SIZE );
		$reader = new Reader( $this->archive( null, [ 'big.bin' => $data ] ) );
		mkdir( $this->dir . '/out' );
		file_put_contents( $this->dir . '/out/big.bin', random_bytes( 3 * Format::CHUNK_SIZE ) );
		$entry  = $this->entry( $reader, 'big.bin' );
		$cursor = new ExtractCursor();
		$budget = new WriteBudget( 3 * Format::CHUNK_SIZE );
		try {
			$this->assertFalse( $reader->extractTo( $entry, $this->dir . '/out/big.bin', $cursor, new CountdownDeadline( 0 ), $budget ) );
			$this->assertSame( Format::CHUNK_SIZE, $budget->written(), 'Charged in full while the old file still exists.' );
			$saved = ExtractCursor::fromArray( $cursor->toArray() );
			$this->assertSame( 3 * Format::CHUNK_SIZE, $saved->replaced );
			$resumed = WriteBudget::fromArray( $budget->toArray() );
			$this->assertTrue( $reader->extractTo( $entry, $this->dir . '/out/big.bin', $saved, new NoDeadline(), $resumed ) );
		} finally {
			$reader->close();
		}
		$this->assertSame( 0, $resumed->written() );
		$this->assertSame( $data, file_get_contents( $this->dir . '/out/big.bin' ) );
	}

	public function test_a_file_with_another_hard_link_frees_nothing_and_gives_no_credit(): void {
		$reader = new Reader( $this->archive( null, [ 'a.bin' => str_repeat( 'a', 3000 ) ] ) );
		mkdir( $this->dir . '/out' );
		file_put_contents( $this->dir . '/out/a.bin', str_repeat( 'o', 3000 ) );
		link( $this->dir . '/out/a.bin', $this->dir . '/kept.bin' );
		$budget = WriteBudget::unlimited();
		try {
			$this->assertTrue( $reader->extractTo( $this->entry( $reader, 'a.bin' ), $this->dir . '/out/a.bin', new ExtractCursor(), new NoDeadline(), $budget ) );
		} finally {
			$reader->close();
		}
		$this->assertSame( 3000, $budget->written() );
	}

	public function test_an_uncompressed_entry_writes_exactly_its_header_size_even_with_more_data_behind_it(): void {
		$path    = $this->dir . '/short.wpress';
		$payload = 'hello, and a lot more that the header does not cover';
		file_put_contents( $path, Header::pack( 'package.json', 2, 1 ) . '{}' . Header::pack( 'a.txt', 5, 1 ) . $payload . str_repeat( "\0", Format::HEADER_SIZE ) );
		$reader = new Reader( $path );
		mkdir( $this->dir . '/out' );
		$cursor = new ExtractCursor();
		try {
			$entry = $this->entry( $reader, 'a.txt' );
			$this->assertTrue( $reader->extractTo( $entry, $this->dir . '/out/a.txt', $cursor, new NoDeadline(), WriteBudget::unlimited() ) );
		} finally {
			$reader->close();
		}
		$this->assertSame( 'hello', file_get_contents( $this->dir . '/out/a.txt' ) );
		$this->assertSame( 5, $cursor->written );
		$this->assertSame( 5, $cursor->consumed );
	}

	public function test_an_encrypted_entry_writes_less_than_its_header_size(): void {
		$codec  = new ChunkCodec( Codec::NONE, new Cipher( 'pw' ) );
		$data   = random_bytes( 2 * Format::CHUNK_SIZE + 7 );
		$reader = new Reader( $this->archive( $codec, [ 'a.bin' => $data ] ), $codec );
		mkdir( $this->dir . '/out' );
		$cursor = new ExtractCursor();
		try {
			$entry = $this->entry( $reader, 'a.bin' );
			$this->assertTrue( $reader->extractTo( $entry, $this->dir . '/out/a.bin', $cursor, new NoDeadline(), WriteBudget::unlimited() ) );
			$this->assertLessThan( $entry->size, $cursor->written );
			$this->assertSame( $entry->size, $cursor->consumed );
		} finally {
			$reader->close();
		}
	}

	/**
	 * The header size counts the stored (compressed) bytes, which is how the
	 * reader finds the next entry, so a compressed entry legitimately writes more
	 * than it: the total limit, not the header, bounds it.
	 */
	public function test_a_compressed_entry_header_size_is_its_stored_size_not_its_written_size(): void {
		$codec  = new ChunkCodec( Codec::GZIP );
		$data   = str_repeat( 'compressible ', 20000 );
		$reader = new Reader( $this->archive( $codec, [ 'a.txt' => $data, 'b.txt' => 'next' ] ), $codec );
		mkdir( $this->dir . '/out' );
		$cursor = new ExtractCursor();
		try {
			$entry = $this->entry( $reader, 'a.txt' );
			$this->assertTrue( $reader->extractTo( $entry, $this->dir . '/out/a.txt', $cursor, new NoDeadline(), WriteBudget::unlimited() ) );
			$this->assertSame( $entry->size, $cursor->consumed );
			$this->assertSame( strlen( $data ), $cursor->written );
			$this->assertGreaterThan( $entry->size, $cursor->written );
			$this->assertSame( 'b.txt', $reader->entryAt( $entry->dataOffset() + $entry->size )->name );
		} finally {
			$reader->close();
		}
	}
}
