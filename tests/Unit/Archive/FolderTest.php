<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Folder;
use LightweightPlugins\Migration\Archive\TargetFile;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FolderTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		if ( is_dir( $this->dir . '/ro' ) ) {
			chmod( $this->dir . '/ro', 0755 );
		}
		TempDir::remove( $this->dir );
	}

	public function test_creates_missing_folders(): void {
		Folder::ensure( $this->dir . '/a/b/c' );
		$this->assertDirectoryExists( $this->dir . '/a/b/c' );
		Folder::ensure( $this->dir . '/a/b/c' ); // Existing: nothing to do.
	}

	public function test_a_file_in_the_way_is_an_exception_not_a_warning(): void {
		file_put_contents( $this->dir . '/file', 'x' );
		$this->expectException( ArchiveException::class );
		Folder::ensure( $this->dir . '/file/sub' );
	}

	public function test_a_read_only_parent_is_an_exception_not_a_warning(): void {
		$this->readOnly();
		$this->expectException( ArchiveException::class );
		Folder::ensure( $this->dir . '/ro/new/deeper' );
	}

	public function test_target_file_in_a_read_only_folder_is_an_exception_not_a_warning(): void {
		$this->readOnly();
		$this->expectException( ArchiveException::class );
		TargetFile::open( $this->dir . '/ro/x.txt', new ExtractCursor() );
	}

	private function readOnly(): void {
		mkdir( $this->dir . '/ro' );
		chmod( $this->dir . '/ro', 0555 );
		if ( is_writable( $this->dir . '/ro' ) ) {
			$this->markTestSkipped( 'Running with privileges that ignore folder permissions.' );
		}
	}
}
