<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Http;

use LightweightPlugins\Migration\Http\DownloadFile;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class DownloadFileTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_cut_back_keeps_the_saved_bytes_and_never_pads(): void {
		$target = $this->dir . '/t';
		file_put_contents( $target, 'abcdefGARBAGE' );

		$this->assertSame( 6, DownloadFile::cutBack( $target, 6 ) );
		$this->assertSame( 'abcdef', file_get_contents( $target ) );
		$this->assertSame( 6, DownloadFile::cutBack( $target, 6 ) );
		$this->assertSame( 0, DownloadFile::cutBack( $target, 9 ) );
		$this->assertSame( '', file_get_contents( $target ) );
		$this->assertSame( 0, DownloadFile::cutBack( $this->dir . '/new', 4 ) );
		$this->assertFileExists( $this->dir . '/new' );
	}

	public function test_append_adds_the_whole_part(): void {
		file_put_contents( $this->dir . '/t', 'abc' );
		file_put_contents( $this->dir . '/p', 'defg' );

		DownloadFile::append( $this->dir . '/p', $this->dir . '/t', 3 );
		$this->assertSame( 'abcdefg', file_get_contents( $this->dir . '/t' ) );
		$this->assertSame( 7, DownloadFile::size( $this->dir . '/t' ) );
	}

	public function test_size_and_remove_accept_a_missing_file(): void {
		$this->assertSame( 0, DownloadFile::size( $this->dir . '/none' ) );
		DownloadFile::remove( $this->dir . '/none' );
		file_put_contents( $this->dir . '/x', 'x' );
		DownloadFile::remove( $this->dir . '/x' );
		$this->assertFileDoesNotExist( $this->dir . '/x' );
	}
}
