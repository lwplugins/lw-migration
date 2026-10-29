<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Crc32;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Eof;
use LightweightPlugins\Migration\Archive\FileCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function source( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		touch( $path, 1767225600 );

		return $path;
	}

	public function test_plain_file_entry_bytes(): void {
		$archive = $this->dir . '/a.wpress';
		$writer  = new Writer( $archive );
		$this->assertTrue( $writer->addFile( $this->source( 'h.txt', 'hello' ), 'x/h.txt', new FileCursor(), new NoDeadline() ) );
		$writer->close();

		$this->assertSame( Header::pack( 'x/h.txt', 5, 1767225600, Crc32::ofString( 'hello' ) ) . 'hello', file_get_contents( $archive ) );
	}

	public function test_add_string_and_config_entries_stay_plain(): void {
		$archive = $this->dir . '/a.wpress';
		$writer  = new Writer( $archive, new ChunkCodec( Codec::GZIP ) );
		$writer->addString( 'package.json', '{"a":1}', 7 );
		$writer->close();

		$this->assertSame( Header::pack( 'package.json', 7, 7, Crc32::ofString( '{"a":1}' ) ) . '{"a":1}', file_get_contents( $archive ) );
	}

	public function test_compressed_entry_size_field_is_stored_length(): void {
		$archive = $this->dir . '/a.wpress';
		$data    = str_repeat( 'a', 600000 );
		$writer  = new Writer( $archive, new ChunkCodec( Codec::GZIP ) );
		$writer->addFile( $this->source( 'big.txt', $data ), 'big.txt', new FileCursor(), new NoDeadline() );
		$writer->close();

		$raw      = (string) file_get_contents( $archive );
		$expected = ( new ChunkCodec( Codec::GZIP ) )->encode( substr( $data, 0, Format::CHUNK_SIZE ) )
			. ( new ChunkCodec( Codec::GZIP ) )->encode( substr( $data, Format::CHUNK_SIZE ) );
		$this->assertSame( (string) strlen( $expected ), rtrim( substr( $raw, 255, 14 ), "\0" ) );
		$this->assertSame( $expected, substr( $raw, Format::HEADER_SIZE ) );
		$this->assertSame( Crc32::ofString( $data ), substr( $raw, 4369, 8 ) );
	}

	public function test_resume_produces_identical_bytes(): void {
		$data = random_bytes( 3 * Format::CHUNK_SIZE + 99 );
		$src  = $this->source( 'r.bin', $data );

		$oneShot = $this->dir . '/one.wpress';
		$w       = new Writer( $oneShot );
		$w->addFile( $src, 'r.bin', new FileCursor(), new NoDeadline() );
		$w->close();

		$resumed = $this->dir . '/resumed.wpress';
		$cursor  = new FileCursor();
		$calls   = 0;
		do {
			$cursor = FileCursor::fromArray( $cursor->toArray() );
			$w      = new Writer( $resumed );
			$done   = $w->addFile( $src, 'r.bin', $cursor, new CountdownDeadline( 0 ) );
			$w->close();
			++$calls;
		} while ( ! $done );

		$this->assertGreaterThan( 2, $calls );
		$this->assertSame( file_get_contents( $oneShot ), file_get_contents( $resumed ) );
	}

	public function test_resume_with_compressed_encrypted_codec_extracts_exactly(): void {
		$data    = random_bytes( 3 * Format::CHUNK_SIZE + 99 );
		$src     = $this->source( 'ce.bin', $data );
		$archive = $this->dir . '/ce.wpress';
		$codec   = new ChunkCodec( Codec::GZIP, new Cipher( 'pw' ) );

		$cursor = new FileCursor();
		$calls  = 0;
		do {
			$cursor = FileCursor::fromArray( $cursor->toArray() );
			$w      = new Writer( $archive, $codec );
			$done   = $w->addFile( $src, 'ce.bin', $cursor, new CountdownDeadline( 0 ) );
			$w->close();
			++$calls;
		} while ( ! $done );
		$w = new Writer( $archive, $codec );
		$this->assertTrue( $w->finalize( new CrcCursor(), new NoDeadline() ) );
		$w->close();

		$this->assertGreaterThan( 2, $calls );
		$reader = new Reader( $archive, new ChunkCodec( Codec::GZIP, new Cipher( 'pw' ) ) );
		$entry  = $reader->find( 'ce.bin' );
		$this->assertNotNull( $entry );
		$this->assertSame( Crc32::ofString( $data ), $entry->crc );
		$out = $this->dir . '/out/ce.bin';
		$this->assertTrue( $reader->extractTo( $entry, $out, new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() ) );
		$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
		$reader->close();
		$this->assertSame( $data, file_get_contents( $out ) );
	}

	public function test_resume_after_partial_chunk_on_disk(): void {
		$data    = random_bytes( 2 * Format::CHUNK_SIZE + 5 );
		$src     = $this->source( 'p.bin', $data );
		$archive = $this->dir . '/p.wpress';

		$cursor = new FileCursor();
		$w      = new Writer( $archive );
		$this->assertFalse( $w->addFile( $src, 'p.bin', $cursor, new CountdownDeadline( 0 ) ) );
		$w->close();
		// Simulate a crash that appended half a chunk after the cursor was saved.
		file_put_contents( $archive, random_bytes( 1000 ), FILE_APPEND );

		$w = new Writer( $archive );
		$this->assertTrue( $w->addFile( $src, 'p.bin', $cursor, new NoDeadline() ) );
		$w->close();

		$this->assertSame( Header::pack( 'p.bin', strlen( $data ), 1767225600, Crc32::ofString( $data ) ) . $data, file_get_contents( $archive ) );
	}

	public function test_finalize_appends_v2_eof_resumably(): void {
		$archive = $this->dir . '/f.wpress';
		$w       = new Writer( $archive );
		$w->addString( 'package.json', '{}', 1 );
		$w->addFile( $this->source( 'b.bin', random_bytes( 3000000 ) ), 'b.bin', new FileCursor(), new NoDeadline() );
		$before = (string) file_get_contents( $archive );

		$w->close();

		$cursor = new CrcCursor();
		$calls  = 0;
		do {
			$cursor = CrcCursor::fromArray( $cursor->toArray() );
			$w      = new Writer( $archive );
			$done   = $w->finalize( $cursor, new CountdownDeadline( 0 ) );
			$w->close();
			if ( ! $done ) {
				++$calls;
			}
		} while ( ! $done );

		$this->assertGreaterThan( 0, $calls );
		$this->assertSame( $before . Eof::v2( strlen( $before ), Crc32::ofString( $before ) ), file_get_contents( $archive ) );
	}

	public function test_empty_file(): void {
		$archive = $this->dir . '/e.wpress';
		$w       = new Writer( $archive );
		$this->assertTrue( $w->addFile( $this->source( 'e.txt', '' ), 'e.txt', new FileCursor(), new NoDeadline() ) );
		$w->close();
		$this->assertSame( Header::pack( 'e.txt', 0, 1767225600, Crc32::EMPTY ), file_get_contents( $archive ) );
	}

	public function test_finalize_after_partial_eof_rewrites_single_eof(): void {
		$archive = $this->dir . '/g.wpress';
		$w       = new Writer( $archive );
		$w->addString( 'a.txt', 'abc', 1 );
		$w->close();
		$before = (string) file_get_contents( $archive );

		$cursor = new CrcCursor();
		$w      = new Writer( $archive );
		$this->assertTrue( $w->finalize( $cursor, new NoDeadline() ) );
		$w->close();
		file_put_contents( $archive, 'junk', FILE_APPEND );

		$w = new Writer( $archive );
		$this->assertTrue( $w->finalize( CrcCursor::fromArray( $cursor->toArray() ), new NoDeadline() ) );
		$w->close();

		$this->assertSame( $before . Eof::v2( strlen( $before ), Crc32::ofString( $before ) ), file_get_contents( $archive ) );
	}

	public function test_finalize_retry_with_unsaved_cursor_keeps_single_eof(): void {
		$archive = $this->dir . '/twice.wpress';
		$w       = new Writer( $archive );
		$w->addString( 'a.txt', 'abc', 1 );
		$w->close();
		$before = (string) file_get_contents( $archive );

		$w = new Writer( $archive );
		$this->assertTrue( $w->finalize( new CrcCursor(), new NoDeadline() ) );
		$w->close();
		$finalized = (string) file_get_contents( $archive );

		// The cursor of the first run was never saved: the retry starts from scratch.
		$retry = new CrcCursor();
		$w     = new Writer( $archive );
		$this->assertTrue( $w->finalize( $retry, new NoDeadline() ) );
		$w->close();

		clearstatcache();
		$this->assertSame( $finalized, file_get_contents( $archive ) );
		$this->assertSame( $before . Eof::v2( strlen( $before ), Crc32::ofString( $before ) ), $finalized );
		$this->assertSame( strlen( $before ), $retry->size );
		$this->assertSame( strlen( $before ), $retry->offset );
		$this->assertSame( Crc32::ofString( $before ), $retry->crc );
	}

	public function test_length_matches_file_size(): void {
		$archive = $this->dir . '/len.wpress';
		$w       = new Writer( $archive );
		$this->assertSame( 0, $w->length() );
		$w->addString( 'a.txt', 'abc', 1 );
		$length = $w->length();
		$w->close();

		clearstatcache();
		$this->assertSame( filesize( $archive ), $length );
	}

	public function test_truncate_to_drops_orphan_entry_bytes(): void {
		$data  = random_bytes( 2 * Format::CHUNK_SIZE + 7 );
		$src   = $this->source( 't.bin', $data );
		$clean = $this->dir . '/clean.wpress';
		$w     = new Writer( $clean );
		$w->addString( 'package.json', '{}', 1 );
		$w->addFile( $src, 't.bin', new FileCursor(), new NoDeadline() );
		$w->close();

		$archive = $this->dir . '/orphan.wpress';
		$w       = new Writer( $archive );
		$w->addString( 'package.json', '{}', 1 );
		$saved = $w->length();
		// A request dies mid-entry; its cursor is lost.
		$this->assertFalse( $w->addFile( $src, 't.bin', new FileCursor(), new CountdownDeadline( 0 ) ) );
		$w->close();

		$w = new Writer( $archive );
		$w->truncateTo( $saved );
		$this->assertSame( $saved, $w->length() );
		$this->assertTrue( $w->addFile( $src, 't.bin', new FileCursor(), new NoDeadline() ) );
		$w->close();

		$this->assertSame( file_get_contents( $clean ), file_get_contents( $archive ) );
	}

	public function test_truncate_to_rejects_invalid_lengths(): void {
		$w = new Writer( $this->dir . '/x.wpress' );
		$w->addString( 'a.txt', 'abc', 1 );
		$length = $w->length();
		foreach ( [ $length + 1, -1 ] as $bad ) {
			try {
				$w->truncateTo( $bad );
				$this->fail( sprintf( 'truncateTo( %d ) must throw.', $bad ) );
			} catch ( ArchiveException $e ) {
				$this->assertSame( $length, $w->length() );
			}
		}
		$w->close();
	}

	public function test_resume_fails_when_archive_is_shorter_than_cursor(): void {
		$src     = $this->source( 's.bin', random_bytes( 2 * Format::CHUNK_SIZE ) );
		$archive = $this->dir . '/s.wpress';
		$cursor  = new FileCursor();
		$w       = new Writer( $archive );
		$this->assertFalse( $w->addFile( $src, 's.bin', $cursor, new CountdownDeadline( 0 ) ) );
		$w->close();
		$handle = fopen( $archive, 'r+b' );
		ftruncate( $handle, (int) filesize( $archive ) - 100 );
		fclose( $handle );
		clearstatcache();

		$w = new Writer( $archive );
		$this->expectException( ArchiveException::class );
		try {
			$w->addFile( $src, 's.bin', $cursor, new NoDeadline() );
		} finally {
			$w->close();
		}
	}
}
