<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_remove_file_deletes_quietly_and_says_whether_it_is_gone(): void {
		mkdir( $this->dir . '/ro' );
		file_put_contents( $this->dir . '/ro/kept.txt', 'k' );
		file_put_contents( $this->dir . '/gone.txt', 'g' );
		symlink( $this->dir . '/ro/kept.txt', $this->dir . '/link' );
		chmod( $this->dir . '/ro', 0555 );
		try {
			$this->assertFalse( Files::removeFile( $this->dir . '/ro/kept.txt' ), 'No PHP warning, just false.' );
		} finally {
			chmod( $this->dir . '/ro', 0755 );
		}

		$this->assertTrue( Files::removeFile( $this->dir . '/gone.txt' ) );
		$this->assertTrue( Files::removeFile( $this->dir . '/link' ) );
		$this->assertFileExists( $this->dir . '/ro/kept.txt', 'A link is removed, never its target.' );
		$this->assertTrue( Files::removeFile( $this->dir . '/missing.txt' ) );
		$this->assertFalse( Files::removeFile( $this->dir . '/ro' ), 'A folder is not a file.' );
	}

	public function test_remove_tree_does_not_follow_symlinks(): void {
		mkdir( $this->dir . '/tree/sub', 0755, true );
		mkdir( $this->dir . '/outside' );
		file_put_contents( $this->dir . '/outside/keep.txt', 'k' );
		file_put_contents( $this->dir . '/tree/sub/a.txt', 'a' );
		symlink( $this->dir . '/outside', $this->dir . '/tree/link' );

		Files::removeTree( $this->dir . '/tree' );
		Files::removeTree( $this->dir . '/missing' );

		$this->assertDirectoryDoesNotExist( $this->dir . '/tree' );
		$this->assertFileExists( $this->dir . '/outside/keep.txt' );
	}

	public function test_a_linked_root_is_unlinked_not_emptied(): void {
		mkdir( $this->dir . '/outside' );
		file_put_contents( $this->dir . '/outside/keep.txt', 'k' );
		symlink( $this->dir . '/outside', $this->dir . '/link' );

		Files::removeTree( $this->dir . '/link' );

		$this->assertFalse( is_link( $this->dir . '/link' ) );
		$this->assertFileExists( $this->dir . '/outside/keep.txt' );
	}

	public function test_a_read_only_tree_is_left_in_place_without_a_warning(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs POSIX permissions that bind the current user.' );
		}
		mkdir( $this->dir . '/tree/locked/inner', 0755, true );
		file_put_contents( $this->dir . '/tree/locked/a.txt', 'a' );
		chmod( $this->dir . '/tree/locked', 0555 );
		try {
			Files::removeTree( $this->dir . '/tree' );

			$this->assertFileExists( $this->dir . '/tree/locked/a.txt' );
			$this->assertDirectoryExists( $this->dir . '/tree/locked/inner' );
		} finally {
			chmod( $this->dir . '/tree/locked', 0755 );
		}
	}
}
