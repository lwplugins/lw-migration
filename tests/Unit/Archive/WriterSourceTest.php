<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\FileCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\SourceChangedException;
use LightweightPlugins\Migration\Archive\SourceReadException;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\ShortReadStream;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class WriterSourceTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		ShortReadStream::register();
	}

	protected function tearDown(): void {
		ShortReadStream::reset();
		TempDir::remove( $this->dir );
	}

	private function source( int $bytes ): string {
		$data = '';
		for ( $i = 0; strlen( $data ) < $bytes; $i++ ) {
			$data .= hash( 'sha256', 'src' . $i, true );
		}
		$path = $this->dir . '/src.bin';
		file_put_contents( $path, substr( $data, 0, $bytes ) );
		return $path;
	}

	public function test_short_reads_still_produce_full_encrypted_chunks(): void {
		$src     = $this->source( 600000 );
		$archive = $this->dir . '/a.wpress';
		$codec   = new ChunkCodec( '', new Cipher( 'pw' ) );
		$writer  = new Writer( $archive, $codec );
		$writer->addString( 'package.json', '{}', 1 );
		$this->assertTrue( $writer->addFile( ShortReadStream::url( $src ), 'big.bin', new FileCursor(), new NoDeadline() ) );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		$reader = new Reader( $archive, $codec );
		$entry  = $reader->find( 'big.bin' );
		$this->assertNotNull( $entry );
		$this->assertSame( file_get_contents( $src ), $reader->readContents( $entry ) );
		$reader->close();
	}

	public function test_a_source_changed_between_resumes_is_reported(): void {
		$src    = $this->source( 600000 );
		$writer = new Writer( $this->dir . '/a.wpress' );
		$cursor = new FileCursor();
		$this->assertFalse( $writer->addFile( $src, 'big.bin', $cursor, new CountdownDeadline( 0 ) ) );
		$this->assertSame( 600000, $cursor->sourceSize );
		$this->assertSame( $cursor->toArray(), FileCursor::fromArray( $cursor->toArray() )->toArray() );

		file_put_contents( $src, 'x', FILE_APPEND );
		touch( $src, time() + 100 );
		clearstatcache();

		$this->expectException( SourceChangedException::class );
		$writer->addFile( $src, 'big.bin', $cursor, new NoDeadline() );
	}

	public function test_a_missing_source_is_a_source_read_error(): void {
		$writer = new Writer( $this->dir . '/a.wpress' );
		$this->expectException( SourceReadException::class );
		$writer->addFile( $this->dir . '/missing.txt', 'missing.txt', new FileCursor(), new NoDeadline() );
	}

	/**
	 * Archives the first chunk of a 600000-byte source, then stops.
	 *
	 * @return array{0: Writer, 1: FileCursor, 2: string}
	 */
	private function paused(): array {
		$src    = $this->source( 600000 );
		$writer = new Writer( $this->dir . '/a.wpress' );
		$cursor = new FileCursor();
		$this->assertFalse( $writer->addFile( $src, 'big.bin', $cursor, new CountdownDeadline( 0 ) ) );
		return [ $writer, $cursor, $src ];
	}

	public function test_a_size_only_change_is_reported(): void {
		[ $writer, $cursor, $src ] = $this->paused();
		$mtime                     = (int) filemtime( $src );
		file_put_contents( $src, 'x', FILE_APPEND );
		touch( $src, $mtime );
		clearstatcache();

		$this->expectException( SourceChangedException::class );
		$writer->addFile( $src, 'big.bin', $cursor, new NoDeadline() );
	}

	public function test_an_mtime_only_change_is_reported(): void {
		[ $writer, $cursor, $src ] = $this->paused();
		touch( $src, (int) filemtime( $src ) + 100 );
		clearstatcache();

		$this->expectException( SourceChangedException::class );
		$writer->addFile( $src, 'big.bin', $cursor, new NoDeadline() );
	}

	public function test_a_cursor_saved_without_source_stat_skips_the_comparison(): void {
		[ $writer, $cursor, $src ] = $this->paused();
		$old = FileCursor::fromArray( array_diff_key( $cursor->toArray(), [ 'sourceSize' => 0, 'sourceMtime' => 0 ] ) );
		touch( $src, (int) filemtime( $src ) + 100 );
		clearstatcache();

		$this->assertTrue( $writer->addFile( $src, 'big.bin', $old, new NoDeadline() ) );
	}

	public function test_a_stalled_source_is_a_source_read_error(): void {
		$src                   = $this->source( 1000 );
		$writer                = new Writer( $this->dir . '/a.wpress' );
		ShortReadStream::$stall = true;

		$this->expectException( SourceReadException::class );
		$writer->addFile( ShortReadStream::url( $src ), 'stalled.bin', new FileCursor(), new NoDeadline() );
	}

	public function test_header_mtime_is_the_opened_files_mtime(): void {
		$src = $this->source( 1000 );
		touch( $src, 1700000000 );
		clearstatcache();
		ShortReadStream::$urlMtime = 1600000000;
		$archive                   = $this->dir . '/a.wpress';
		$writer                    = new Writer( $archive );
		$writer->addString( 'package.json', '{}', 1 );
		$this->assertTrue( $writer->addFile( ShortReadStream::url( $src ), 'm.bin', new FileCursor(), new NoDeadline() ) );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		$reader = new Reader( $archive );
		$entry  = $reader->find( 'm.bin' );
		$reader->close();
		$this->assertNotNull( $entry );
		$this->assertSame( 1700000000, $entry->mtime );
	}
}
