<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\Crc32;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Eof;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\FileCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\UnsafePathException;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function build( ?ChunkCodec $codec, array $files ): string {
		$archive = $this->dir . '/t.wpress';
		$w       = new Writer( $archive, $codec );
		$w->addString( 'package.json', '{"SiteURL":"https://a.test"}', 1 );
		foreach ( $files as $name => $content ) {
			$src = $this->dir . '/src-' . md5( $name );
			file_put_contents( $src, $content );
			$w->addFile( $src, $name, new FileCursor(), new NoDeadline() );
		}
		$w->finalize( new CrcCursor(), new NoDeadline() );
		$w->close();

		return $archive;
	}

	public function test_round_trip_with_every_codec(): void {
		$files  = [ 'a.txt' => 'hi', 'd/big.bin' => random_bytes( 1100000 ), 'e.txt' => '' ];
		$codecs = [ null, new ChunkCodec( Codec::GZIP ), new ChunkCodec( Codec::NONE, new Cipher( 'p' ) ), new ChunkCodec( Codec::GZIP, new Cipher( 'p' ) ) ];
		if ( function_exists( 'bzcompress' ) ) {
			$codecs[] = new ChunkCodec( Codec::BZIP2 );
		}

		foreach ( $codecs as $codec ) {
			$archive = $this->build( $codec, $files );
			$reader  = new Reader( $archive, $codec );
			$reader->validate();
			$first = $reader->firstEntry();
			$this->assertNotNull( $first );
			$this->assertSame( '{"SiteURL":"https://a.test"}', $reader->readContents( $first ) );
			foreach ( $files as $name => $content ) {
				$entry = $reader->find( $name );
				$this->assertNotNull( $entry );
				$out = $this->dir . '/out/' . $name;
				$this->assertTrue( $reader->extractTo( $entry, $out, new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() ) );
				$this->assertSame( $content, file_get_contents( $out ) );
			}
			$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
			$reader->close();
			unlink( $archive );
			TempDir::remove( $this->dir . '/out' );
		}
	}

	public function test_entries_from_offset_and_entry_at(): void {
		$archive = $this->build( null, [ 'a.txt' => 'one', 'b.txt' => str_repeat( 'x', 9000 ), 'c/d.txt' => 'three', 'e.txt' => '' ] );
		$reader  = new Reader( $archive );
		$all     = iterator_to_array( $reader->entries(), false );
		$this->assertCount( 5, $all );

		$third = $all[2];
		$this->assertSame( 'b.txt', $third->name );
		$tail = iterator_to_array( $reader->entries( $third->headerOffset ), false );
		$this->assertEquals( array_slice( $all, 2 ), $tail );
		$this->assertSame( [], iterator_to_array( $reader->entries( $reader->dataEnd() ), false ) );

		foreach ( $all as $entry ) {
			$this->assertEquals( $entry, $reader->entryAt( $entry->headerOffset ) );
		}
		$this->assertSame( str_repeat( 'x', 9000 ), $reader->readContents( $reader->entryAt( $third->headerOffset ) ) );

		foreach ( [ $reader->dataEnd(), $third->dataOffset(), -1, $reader->dataEnd() + 1 ] as $bad ) {
			try {
				$reader->entryAt( $bad );
				$this->fail( sprintf( 'entryAt( %d ) must throw.', $bad ) );
			} catch ( InvalidArchiveException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		foreach ( [ $third->dataOffset(), -1, $reader->dataEnd() + 1 ] as $bad ) {
			try {
				iterator_to_array( $reader->entries( $bad ) );
				$this->fail( sprintf( 'entries( %d ) must throw.', $bad ) );
			} catch ( InvalidArchiveException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$reader->close();
	}

	public function test_has_archive_extension(): void {
		$this->assertTrue( Reader::hasArchiveExtension( 'site-2026.WPRESS' ) );
		$this->assertFalse( Reader::hasArchiveExtension( 'site.zip' ) );
	}

	public function test_truncated_archive_is_rejected(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hi' ] );
		$raw     = (string) file_get_contents( $archive );
		file_put_contents( $archive, substr( $raw, 0, -100 ) );

		$this->expectException( InvalidArchiveException::class );
		( new Reader( $archive ) )->validate();
	}

	public function test_entry_past_end_is_rejected(): void {
		$archive = $this->dir . '/bad.wpress';
		$body    = Header::pack( 'package.json', 2, 1 ) . '{}' . Header::pack( 'a.txt', 999999, 1 ) . 'short';
		file_put_contents( $archive, $body . Eof::v2( strlen( $body ), \LightweightPlugins\Migration\Archive\Crc32::ofString( $body ) ) );

		$this->expectException( InvalidArchiveException::class );
		foreach ( ( new Reader( $archive ) )->entries() as $entry ) {
			// Iterating must throw before yielding the broken entry's data.
			$this->assertSame( 'package.json', $entry->name );
		}
	}

	public function test_v2_eof_with_wrong_size_is_rejected(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hi' ] );
		$raw     = (string) file_get_contents( $archive );
		$body    = substr( $raw, 0, -Format::HEADER_SIZE );
		file_put_contents( $archive, $body . Eof::v2( strlen( $body ) + 1, Crc32::ofString( $body ) ) );

		$this->expectException( InvalidArchiveException::class );
		( new Reader( $archive ) )->validate();
	}

	public function test_first_entry_must_be_package_json(): void {
		$archive = $this->dir . '/nopkg.wpress';
		$body    = Header::pack( 'a.txt', 1, 1 ) . 'x';
		file_put_contents( $archive, $body . Eof::v1() );

		$this->expectException( InvalidArchiveException::class );
		( new Reader( $archive ) )->validate();
	}

	public function test_first_entry_works_without_eof(): void {
		$archive = $this->dir . '/partial.wpress';
		file_put_contents( $archive, Header::pack( 'package.json', 2, 1 ) . '{}' );
		$first = ( new Reader( $archive ) )->firstEntry();
		$this->assertNotNull( $first );
		$this->assertSame( 'package.json', $first->name );
	}

	public function test_v1_eof_is_accepted_and_skips_checksum(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hi' ] );
		$raw     = (string) file_get_contents( $archive );
		file_put_contents( $archive, substr( $raw, 0, -Format::HEADER_SIZE ) . Eof::v1() );

		$reader = new Reader( $archive );
		$reader->validate();
		$this->assertSame( 1, $reader->eof()->version );
		$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
	}

	public function test_checksum_mismatch_and_resumable_verify(): void {
		$archive = $this->build( null, [ 'big.bin' => random_bytes( 3000000 ) ] );
		$reader  = new Reader( $archive );
		$cursor  = new CrcCursor();
		$calls   = 0;
		while ( ! $reader->verifyChecksum( $cursor, new CountdownDeadline( 0 ) ) ) {
			++$calls;
		}
		$this->assertGreaterThan( 0, $calls );
		$reader->close();

		$fh = fopen( $archive, 'r+b' );
		fseek( $fh, Format::HEADER_SIZE * 2 + 100 );
		$orig = (string) fread( $fh, 1 );
		fseek( $fh, Format::HEADER_SIZE * 2 + 100 );
		fwrite( $fh, $orig ^ "\xFF" ); // Always a different byte (a fixed one matched 1 time in 256).
		fclose( $fh );

		$this->expectException( CorruptedEntryException::class );
		( new Reader( $archive ) )->verifyChecksum( new CrcCursor(), new NoDeadline() );
	}

	public function test_entry_crc_mismatch_is_corrupted(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hello' ] );
		$raw     = (string) file_get_contents( $archive );
		$pos     = strpos( $raw, 'hello' );
		$raw[ $pos ] = 'j';
		file_put_contents( $archive, $raw );

		$reader = new Reader( $archive );
		$entry  = $reader->find( 'a.txt' );
		$this->assertNotNull( $entry );
		$this->expectException( CorruptedEntryException::class );
		$reader->extractTo( $entry, $this->dir . '/x.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() );
	}

	public function test_refuses_symlink_target(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hello' ] );
		symlink( $this->dir . '/elsewhere', $this->dir . '/a.txt' );
		$reader = new Reader( $archive );
		$entry  = $reader->find( 'a.txt' );
		$this->assertNotNull( $entry );
		$this->expectException( UnsafePathException::class );
		$reader->extractTo( $entry, $this->dir . '/a.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() );
	}

	public function test_read_contents_respects_limit(): void {
		$archive = $this->build( null, [ 'big.bin' => random_bytes( 2000 ) ] );
		$reader  = new Reader( $archive );
		$entry   = $reader->find( 'big.bin' );
		$this->assertNotNull( $entry );
		$this->expectException( \LightweightPlugins\Migration\Archive\ArchiveException::class );
		$reader->readContents( $entry, 1000 );
	}

	public function test_resume_with_output_shorter_than_progress_is_rejected(): void {
		$archive = $this->build( null, [ 'a.txt' => 'hello' ] );
		$reader  = new Reader( $archive );
		$entry   = $reader->find( 'a.txt' );
		$this->assertNotNull( $entry );
		$target = $this->dir . '/short.txt';
		$temp   = $this->dir . '/.lwm-0123456789abcdef.part';
		file_put_contents( $temp, 'he' );

		$this->expectException( \LightweightPlugins\Migration\Archive\ArchiveException::class );
		$this->expectExceptionMessage( 'shorter than the saved progress' );
		$reader->extractTo( $entry, $target, new ExtractCursor( 5, 5, Crc32::EMPTY, $temp ), new NoDeadline(), WriteBudget::unlimited() );
	}

	public function test_a_missing_archive_is_refused_by_name_without_a_php_warning(): void {
		foreach ( [ $this->dir . '/missing.wpress', $this->dir ] as $path ) {
			try {
				new Reader( $path );
				$this->fail( 'Expected an ArchiveException.' );
			} catch ( ArchiveException $e ) {
				$this->assertSame( 'Cannot open archive: ' . basename( $path ), $e->getMessage() );
				$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			}
		}
	}
}
