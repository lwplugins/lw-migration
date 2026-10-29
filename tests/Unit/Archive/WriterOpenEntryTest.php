<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\FileCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Writer::holdsOpenEntry() — is the entry a saved cursor points at still in the archive?
 */
final class WriterOpenEntryTest extends TestCase {

	private string $dir;

	private string $archive;

	protected function setUp(): void {
		$this->dir     = TempDir::create();
		$this->archive = $this->dir . '/a.wpress';
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function source( string $name, int $bytes ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, random_bytes( $bytes ) );

		return $path;
	}

	/** Writes package.json and the first chunk of $name; returns the saved cursor. */
	private function openEntry( Writer $writer, string $path, string $name ): FileCursor {
		$writer->addString( 'package.json', '{}', 1 );
		$cursor = new FileCursor();
		$this->assertFalse( $writer->addFile( $path, $name, $cursor, new CountdownDeadline( 0 ) ) );

		return FileCursor::fromArray( $cursor->toArray() );
	}

	public function test_a_partly_written_entry_is_held(): void {
		$writer = new Writer( $this->archive );
		$saved  = $this->openEntry( $writer, $this->source( 'x', 1300000 ), 'd/x.bin' );

		$this->assertTrue( $writer->holdsOpenEntry( $saved, 'd/x.bin' ) );
		$this->assertFalse( $writer->holdsOpenEntry( $saved, 'd/other.bin' ) );
		$this->assertFalse( $writer->holdsOpenEntry( new FileCursor(), 'd/x.bin' ) );
		$writer->close();
	}

	public function test_another_entry_at_the_header_offset_is_not_held(): void {
		$writer = new Writer( $this->archive );
		$saved  = $this->openEntry( $writer, $this->source( 'x', 1300000 ), 'x.bin' );
		// An unsaved tick skipped x.bin and wrote y.bin in its place.
		$writer->truncateTo( (int) $saved->headerOffset );
		$this->assertTrue( $writer->addFile( $this->source( 'y', 700000 ), 'y.bin', new FileCursor(), new NoDeadline() ) );

		$this->assertFalse( $writer->holdsOpenEntry( $saved, 'x.bin' ) );
		$writer->close();
	}

	public function test_an_entry_completed_after_the_saved_cursor_is_not_held(): void {
		$writer = new Writer( $this->archive );
		$saved  = $this->openEntry( $writer, $this->source( 'x', 1300000 ), 'x.bin' );
		// An unsaved tick finished the entry: its size field is no longer the placeholder.
		$this->assertTrue( $writer->addFile( $this->dir . '/x', 'x.bin', FileCursor::fromArray( $saved->toArray() ), new NoDeadline() ) );

		$this->assertFalse( $writer->holdsOpenEntry( $saved, 'x.bin' ) );
		$writer->close();
	}

	public function test_an_archive_shorter_than_the_saved_progress_is_not_held(): void {
		$writer = new Writer( $this->archive );
		$saved  = $this->openEntry( $writer, $this->source( 'x', 1300000 ), 'x.bin' );
		$writer->truncateTo( $writer->length() - 1 );

		$this->assertFalse( $writer->holdsOpenEntry( $saved, 'x.bin' ) );
		$writer->close();
	}

	public function test_garbage_after_an_open_entry_is_dropped_on_resume(): void {
		$path   = $this->source( 'x', 1300000 );
		$writer = new Writer( $this->archive );
		$saved  = $this->openEntry( $writer, $path, 'x.bin' );
		$writer->close();
		// The request died after writing more chunks it never recorded, then garbage.
		file_put_contents( $this->archive, random_bytes( 70000 ), FILE_APPEND );

		$writer = new Writer( $this->archive );
		$this->assertTrue( $writer->holdsOpenEntry( $saved, 'x.bin' ) );
		$this->assertTrue( $writer->addFile( $path, 'x.bin', $saved, new NoDeadline() ) );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		$reader = new Reader( $this->archive );
		$reader->validate();
		$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
		$this->assertSame( (string) file_get_contents( $path ), $reader->readContents( $reader->find( 'x.bin' ) ) );
		$reader->close();
	}
}
