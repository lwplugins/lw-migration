<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Extractor;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase {

	public function test_extracts_the_plain_fixture_byte_for_byte(): void {
		$target   = TempDir::create();
		$manifest = json_decode( (string) file_get_contents( __DIR__ . '/../../Fixtures/wpress/manifest.json' ), true );
		$count    = Extractor::extractAll( __DIR__ . '/../../Fixtures/wpress/plain.wpress', ChunkCodec::plain(), $target, WriteBudget::unlimited(), static function (): void {} );

		$this->assertSame( count( $manifest['entries'] ) + 1, $count ); // + package.json
		foreach ( $manifest['entries'] as $entry ) {
			$this->assertSame( $entry['sha1'], sha1_file( $target . '/' . $entry['name'] ), $entry['name'] );
		}
		TempDir::remove( $target );
	}

	public function test_unsafe_names_are_skipped_and_corruption_removes_the_partial_file(): void {
		$dir     = TempDir::create();
		$archive = self::damagedArchive( $dir, [ '../evil.txt', 'sub/../../../evil2.txt', 'C:/evil3.txt' ] );

		$warnings = [];
		try {
			Extractor::extractAll( $archive, ChunkCodec::plain(), $dir . '/out', WriteBudget::unlimited(), static function ( string $w ) use ( &$warnings ): void {
				$warnings[] = $w;
			} );
			$this->fail( 'Corruption not detected.' );
		} catch ( CorruptedEntryException $e ) {
			$this->assertFileDoesNotExist( $dir . '/out/bad.txt' );
		}
		$this->assertFileExists( $dir . '/out/good.txt' );
		$this->assertFileDoesNotExist( $dir . '/evil.txt' );
		$this->assertFileDoesNotExist( dirname( $dir ) . '/evil2.txt' );
		$this->assertCount( 3, $warnings );
		$this->assertSame( [ 'good.txt', 'package.json' ], self::filesIn( $dir . '/out' ) );
		TempDir::remove( $dir );
	}

	public function test_a_damaged_entry_keeps_an_existing_file_of_the_same_name(): void {
		$dir     = TempDir::create();
		$archive = self::damagedArchive( $dir, [] );
		mkdir( $dir . '/out' );
		file_put_contents( $dir . '/out/bad.txt', 'kept' );

		try {
			Extractor::extractAll( $archive, ChunkCodec::plain(), $dir . '/out', WriteBudget::unlimited(), static function (): void {}, true );
			$this->fail( 'Corruption not detected.' );
		} catch ( CorruptedEntryException $e ) {
			$this->assertSame( 'kept', file_get_contents( $dir . '/out/bad.txt' ) );
		}
		$this->assertSame( [ 'bad.txt', 'good.txt', 'package.json' ], self::filesIn( $dir . '/out' ) ); // No temporary file left.
		TempDir::remove( $dir );
	}

	public function test_existing_files_are_kept_unless_overwrite_is_asked(): void {
		$dir     = TempDir::create();
		$archive = $dir . '/a.wpress';
		$writer  = new Writer( $archive );
		$writer->addString( 'package.json', '{}', 1 );
		$writer->addString( 'sub/new.txt', 'new', 1 );
		$writer->addString( 'sub/old.txt', 'from the archive', 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		mkdir( $dir . '/out/sub', 0755, true );
		file_put_contents( $dir . '/out/sub/old.txt', 'mine' );
		$warnings = [];
		$warn     = static function ( string $w ) use ( &$warnings ): void {
			$warnings[] = $w;
		};

		$count = Extractor::extractAll( $archive, ChunkCodec::plain(), $dir . '/out', WriteBudget::unlimited(), $warn );

		$this->assertSame( 2, $count );
		$this->assertSame( 'mine', file_get_contents( $dir . '/out/sub/old.txt' ) );
		$this->assertSame( 'new', file_get_contents( $dir . '/out/sub/new.txt' ) );
		$this->assertSame( [ 'Kept existing file sub/old.txt (use --force to overwrite)' ], $warnings );

		$this->assertSame( 3, Extractor::extractAll( $archive, ChunkCodec::plain(), $dir . '/out', WriteBudget::unlimited(), $warn, true ) );
		$this->assertSame( 'from the archive', file_get_contents( $dir . '/out/sub/old.txt' ) );
		$this->assertCount( 1, $warnings, 'A forced run warns about nothing.' );
		TempDir::remove( $dir );
	}

	public function test_the_write_limit_stops_the_extraction_without_a_partial_file(): void {
		$dir     = TempDir::create();
		$archive = $dir . '/a.wpress';
		$writer  = new Writer( $archive );
		$writer->addString( 'package.json', '{}', 1 );
		$writer->addString( 'a.txt', str_repeat( 'a', 100 ), 1 );
		$writer->addString( 'b.txt', str_repeat( 'b', 100 ), 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		try {
			Extractor::extractAll( $archive, ChunkCodec::plain(), $dir . '/out', new WriteBudget( 150 ), static function (): void {} );
			$this->fail( 'Expected a WriteLimitException.' );
		} catch ( WriteLimitException $e ) {
			$this->assertSame( [ 'a.txt', 'package.json' ], self::filesIn( $dir . '/out' ) ); // No temporary file left.
		}
		TempDir::remove( $dir );
	}

	/**
	 * package.json, the unsafe names, good.txt, then bad.txt with one data byte flipped.
	 *
	 * @param string            $dir    Folder for the archive.
	 * @param array<int,string> $unsafe Unsafe entry names.
	 * @return string Archive path.
	 */
	private static function damagedArchive( string $dir, array $unsafe ): string {
		$archive = $dir . '/a.wpress';
		$writer  = new Writer( $archive );
		$writer->addString( 'package.json', '{}', 1 );
		foreach ( $unsafe as $name ) {
			$writer->addString( $name, 'evil', 1 );
		}
		$writer->addString( 'good.txt', 'good', 1 );
		$writer->addString( 'bad.txt', str_repeat( 'b', 100 ), 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		// Flip one data byte of bad.txt (its data ends right before the 4377-byte end block).
		$h = fopen( $archive, 'r+b' );
		fseek( $h, -4377 - 10, SEEK_END );
		fwrite( $h, 'X' );
		fclose( $h );

		return $archive;
	}

	/**
	 * @param string $dir Folder.
	 * @return array<int, string> Sorted names, hidden ones included.
	 */
	private static function filesIn( string $dir ): array {
		$names = array_values( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) );
		sort( $names );

		return $names;
	}
}
