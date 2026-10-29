<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Entry;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\TargetFile;
use LightweightPlugins\Migration\Archive\UnsafePathException;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ReaderTargetTest extends TestCase {

	private string $dir;

	private string $big;

	private Reader $reader;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		$this->big = str_repeat( hash( 'sha256', 'big', true ), 20000 );
		$this->reader = $this->open( null );
		mkdir( $this->dir . '/out' );
	}

	protected function tearDown(): void {
		$this->reader->close();
		TempDir::remove( $this->dir );
	}

	private function open( ?Cipher $cipher ): Reader {
		$archive = $this->dir . '/t.wpress';
		if ( ! is_file( $archive ) ) {
			$writer = new Writer( $archive, new ChunkCodec( '', new Cipher( 'pw' ) ) );
			$writer->addString( 'package.json', '{}', 1 );
			$writer->addString( 'x.txt', 'PAYLOAD', 1000 );
			$writer->addString( 'big.bin', $this->big, 2000 );
			$writer->finalize( new CrcCursor(), new NoDeadline() );
			$writer->close();
		}

		return new Reader( $archive, new ChunkCodec( '', $cipher ?? new Cipher( 'pw' ) ) );
	}

	private function entry( string $name ): Entry {
		$entry = $this->reader->find( $name );
		$this->assertNotNull( $entry );

		return $entry;
	}

	/** @return list<string> */
	private function parts(): array {
		return array_values( array_filter( (array) scandir( $this->dir . '/out' ), static fn( $f ): bool => str_ends_with( (string) $f, '.part' ) ) );
	}

	private function paused( ExtractCursor $cursor ): void {
		$this->assertFalse( $this->reader->extractTo( $this->entry( 'big.bin' ), $this->dir . '/out/big.bin', $cursor, new CountdownDeadline( 0 ), WriteBudget::unlimited() ) );
	}

	public function test_the_final_name_appears_only_when_the_entry_is_complete(): void {
		$cursor = new ExtractCursor();
		$this->paused( $cursor );

		clearstatcache();
		$this->assertFileDoesNotExist( $this->dir . '/out/big.bin' );
		$this->assertNotNull( $cursor->tempPath );
		$this->assertSame( $this->dir . '/out', dirname( $cursor->tempPath ) );
		$this->assertMatchesRegularExpression( '/^\.lwm-[0-9a-f]{16}\.part$/', basename( $cursor->tempPath ) );
		$this->assertFileExists( $cursor->tempPath );
	}

	public function test_resume_continues_the_same_temporary_file(): void {
		$cursor = new ExtractCursor();
		$this->paused( $cursor );
		$inode  = fileinode( (string) $cursor->tempPath );
		$cursor = ExtractCursor::fromArray( $cursor->toArray() );

		$this->assertTrue( $this->reader->extractTo( $this->entry( 'big.bin' ), $this->dir . '/out/big.bin', $cursor, new NoDeadline(), WriteBudget::unlimited() ) );

		clearstatcache();
		$this->assertSame( $inode, fileinode( $this->dir . '/out/big.bin' ) );
		$this->assertSame( $this->big, file_get_contents( $this->dir . '/out/big.bin' ) );
		$this->assertSame( 2000, filemtime( $this->dir . '/out/big.bin' ) );
		$this->assertNull( $cursor->tempPath );
		$this->assertSame( [], $this->parts() );
	}

	public function test_a_cursor_without_temp_path_restarts_the_entry(): void {
		$cursor = ExtractCursor::fromArray( [ 'consumed' => 5, 'written' => 5, 'crc' => 'abcdef01' ] );

		$this->assertTrue( $this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', $cursor, new NoDeadline(), WriteBudget::unlimited() ) );

		clearstatcache();
		$this->assertSame( 'PAYLOAD', file_get_contents( $this->dir . '/out/x.txt' ) );
	}

	public function test_a_corrupted_entry_deletes_the_temporary_file(): void {
		$this->reader->close();
		$this->reader = $this->open( new Cipher( 'wrong' ) );
		$cursor       = new ExtractCursor();

		try {
			$this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', $cursor, new NoDeadline(), WriteBudget::unlimited() );
			$this->fail( 'A wrong password must fail the entry.' );
		} catch ( CorruptedEntryException $e ) {
			$this->assertNull( $cursor->tempPath );
		}

		clearstatcache();
		$this->assertSame( [], $this->parts() );
		$this->assertFileDoesNotExist( $this->dir . '/out/x.txt' );
	}

	public function test_discard_removes_a_leftover_temporary_file(): void {
		$cursor = new ExtractCursor();
		$this->paused( $cursor );

		TargetFile::discard( $cursor, $this->dir . '/out/big.bin' );

		clearstatcache();
		$this->assertNull( $cursor->tempPath );
		$this->assertSame( [], $this->parts() );
	}

	public function test_discard_never_deletes_a_file_that_is_not_ours(): void {
		file_put_contents( $this->dir . '/out/keep.txt', 'KEEP' );
		$cursor = new ExtractCursor( 0, 0, '00000000', $this->dir . '/out/keep.txt' );

		TargetFile::discard( $cursor, $this->dir . '/out/x.txt' );

		clearstatcache();
		$this->assertFileExists( $this->dir . '/out/keep.txt' );
	}

	public function test_resume_refuses_a_temp_path_outside_the_target_folder(): void {
		file_put_contents( $this->dir . '/.lwm-0123456789abcdef.part', 'PAY' );
		$cursor = new ExtractCursor( 3, 3, '00000000', $this->dir . '/.lwm-0123456789abcdef.part' );

		$this->expectException( UnsafePathException::class );
		$this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', $cursor, new NoDeadline(), WriteBudget::unlimited() );
	}

	public function test_completion_replaces_a_hard_link_without_touching_the_other_link(): void {
		file_put_contents( $this->dir . '/victim.txt', 'VICTIM' );
		link( $this->dir . '/victim.txt', $this->dir . '/out/x.txt' );

		$this->assertTrue( $this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() ) );

		clearstatcache();
		$this->assertSame( 'VICTIM', file_get_contents( $this->dir . '/victim.txt' ) );
		$this->assertSame( 'PAYLOAD', file_get_contents( $this->dir . '/out/x.txt' ) );
		$this->assertSame( 1000, filemtime( $this->dir . '/out/x.txt' ) );
	}

	public function test_completion_replaces_an_existing_file(): void {
		file_put_contents( $this->dir . '/out/x.txt', 'OLD CONTENT THAT IS LONGER' );

		$this->assertTrue( $this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() ) );

		clearstatcache();
		$this->assertSame( 'PAYLOAD', file_get_contents( $this->dir . '/out/x.txt' ) );
	}

	public function test_a_dangling_symlink_target_is_refused_without_creating_the_outside_file(): void {
		symlink( $this->dir . '/outside.txt', $this->dir . '/out/x.txt' );

		try {
			$this->reader->extractTo( $this->entry( 'x.txt' ), $this->dir . '/out/x.txt', new ExtractCursor(), new NoDeadline(), WriteBudget::unlimited() );
			$this->fail( 'A symlink target must be refused.' );
		} catch ( UnsafePathException $e ) {
			$this->assertStringContainsString( 'symlink', $e->getMessage() );
		}

		clearstatcache();
		$this->assertFileDoesNotExist( $this->dir . '/outside.txt' );
	}

	public function test_resume_refuses_a_temporary_file_with_another_hard_link(): void {
		$cursor = new ExtractCursor();
		$this->paused( $cursor );
		$before = file_get_contents( (string) $cursor->tempPath );
		link( (string) $cursor->tempPath, $this->dir . '/victim.bin' );

		try {
			$this->reader->extractTo( $this->entry( 'big.bin' ), $this->dir . '/out/big.bin', $cursor, new NoDeadline(), WriteBudget::unlimited() );
			$this->fail( 'A hard-linked temporary file must be refused on resume.' );
		} catch ( UnsafePathException $e ) {
			$this->assertStringContainsString( 'hard link', $e->getMessage() );
		}

		clearstatcache();
		$this->assertSame( $before, file_get_contents( $this->dir . '/victim.bin' ) );
	}

	public function test_discard_never_deletes_a_temp_named_file_in_another_folder(): void {
		$crafted = $this->dir . '/.lwm-0123456789abcdef.part';
		file_put_contents( $crafted, 'KEEP' );
		$cursor = new ExtractCursor( 0, 0, '00000000', $crafted );

		TargetFile::discard( $cursor, $this->dir . '/out/x.txt' );

		clearstatcache();
		$this->assertNull( $cursor->tempPath );
		$this->assertFileExists( $crafted );
	}

	public function test_a_failed_resume_deletes_the_temporary_file(): void {
		$cursor = new ExtractCursor();
		$this->paused( $cursor );
		$temp = (string) $cursor->tempPath;
		file_put_contents( $temp, 'short' );

		try {
			$this->reader->extractTo( $this->entry( 'big.bin' ), $this->dir . '/out/big.bin', $cursor, new NoDeadline(), WriteBudget::unlimited() );
			$this->fail( 'A temporary file shorter than the progress must fail the resume.' );
		} catch ( \LightweightPlugins\Migration\Archive\ArchiveException $e ) {
			$this->assertStringContainsString( 'shorter than the saved progress', $e->getMessage() );
		}

		clearstatcache();
		$this->assertNull( $cursor->tempPath );
		$this->assertFileDoesNotExist( $temp );
	}
}
