<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage;

use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class FileIdentityTest extends TestCase {

	private string $dir;
	private string $source;
	private string $target;

	protected function setUp(): void {
		$this->dir    = TempDir::create();
		$this->source = $this->dir . '/a.wpress';
		$this->target = $this->dir . '/.lwm-0123456789abcdef.part';
		file_put_contents( $this->source, random_bytes( 10000 ) );
		touch( $this->source, 1700000000 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function copier(): FileMover {
		return new FileMover( false, 1024, static fn( string $d ): ?int => null );
	}

	/**
	 * @param array<string, mixed> $cursor Cursor.
	 */
	private function copyToEnd( FileMover $mover, array &$cursor ): void {
		while ( ! $mover->copy( $this->source, $this->target, $cursor, new NoDeadline() ) ) {
			continue;
		}
	}

	private function assertFails( int $code, \Closure $call ): void {
		try {
			$call();
			$this->fail( 'Expected code ' . $code );
		} catch ( StorageException $e ) {
			$this->assertSame( $code, $e->getCode() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage(), 'No path in the message.' );
		}
	}

	public function test_a_link_or_a_folder_has_no_file_identity(): void {
		symlink( $this->source, $this->dir . '/link.wpress' );
		$this->assertNull( FileIdentity::of( $this->dir . '/link.wpress' ) );
		$this->assertNull( FileIdentity::of( $this->dir ) );
		$this->assertNull( FileIdentity::of( $this->dir . '/none' ) );
		$stat = (array) stat( $this->source );
		$this->assertSame(
			[
				'id'    => $stat['dev'] . ':' . $stat['ino'],
				'size'  => 10000,
				'mtime' => 1700000000,
			],
			FileIdentity::of( $this->source )
		);
	}

	public function test_the_original_is_removed_only_while_the_copy_is_published_and_the_original_unchanged(): void {
		$cursor = [];
		$this->copyToEnd( $this->copier(), $cursor );
		$published = $this->dir . '/site.wpress';
		rename( $this->target, $published );

		file_put_contents( $this->source, 'x', FILE_APPEND );
		$this->assertFails( FileMover::MISMATCH, fn() => FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] ) );
		$this->assertFileExists( $this->source, 'A changed original stays.' );

		$cursor = [];
		$this->copyToEnd( $this->copier(), $cursor );
		$this->assertFails( FileMover::MISMATCH, fn() => FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] ) );
		$this->assertFileExists( $this->source, 'The published name holds another file: the original stays.' );

		unlink( $published );
		rename( $this->target, $published );
		FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] );
		$this->assertFileDoesNotExist( $this->source );
		FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] );
		$this->assertFileExists( $published, 'A second call after the original went is a no-op.' );
	}

	public function test_the_original_of_a_hard_link_is_removed_after_publishing(): void {
		$cursor = [];
		( new FileMover() )->copy( $this->source, $this->target, $cursor, new NoDeadline() );
		$published = $this->dir . '/site.wpress';
		link( $this->target, $published );
		unlink( $this->target );

		FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] );

		$this->assertFileDoesNotExist( $this->source );
		$this->assertSame( 10000, filesize( $published ) );
	}

	public function test_same_names_one_inode_or_equal_bytes_size_and_mtime(): void {
		$copy = $this->dir . '/copy.wpress';
		$cursor = [];
		link( $this->source, $copy );
		$this->assertTrue( FileIdentity::same( $this->source, $copy, $cursor, new NoDeadline() ) );

		unlink( $copy );
		copy( $this->source, $copy );
		touch( $copy, 1700000000 );
		$cursor = [];
		$result = null;
		for ( $calls = 1; null === $result && $calls < 100; $calls++ ) {
			$result = FileIdentity::same( $this->source, $copy, $cursor, new CountdownDeadline( 1 ), 1024 );
		}
		$this->assertTrue( $result );
		$this->assertGreaterThan( 2, $calls, 'Compared in slices over several calls.' );

		$handle = fopen( $copy, 'r+b' );
		fseek( $handle, 9000 );
		$byte = (string) fread( $handle, 1 );
		fseek( $handle, 9000 );
		fwrite( $handle, chr( ord( $byte ) ^ 0xff ) ); // Always another byte.
		fclose( $handle );
		touch( $copy, 1700000000 );
		$cursor = [];
		$this->assertFalse( FileIdentity::same( $this->source, $copy, $cursor, new NoDeadline() ) );

		file_put_contents( $copy, file_get_contents( $this->source ) );
		touch( $copy, 1700000003 );
		$cursor = [];
		$this->assertFalse( FileIdentity::same( $this->source, $copy, $cursor, new NoDeadline() ), 'An mtime more than 2 seconds off is another file.' );
	}

	public function test_a_copy_on_a_two_second_file_system_is_the_same_backup(): void {
		touch( $this->source, 1700000001 ); // An odd second, as FAT cannot keep it.
		$copy = $this->dir . '/copy.wpress';
		copy( $this->source, $copy );
		touch( $copy, 1700000000 );
		$cursor = [];

		$this->assertTrue( FileIdentity::same( $this->source, $copy, $cursor, new NoDeadline() ), 'Size and bytes decide; the mtime is only a hint.' );
	}

	public function test_an_inode_reused_with_other_bytes_is_not_the_copy(): void {
		$cursor = [];
		$this->copyToEnd( $this->copier(), $cursor );
		$published = $this->dir . '/site.wpress';
		rename( $this->target, $published );
		// Same device and inode (FAT hands a freed start cluster to the next file), other size and mtime.
		file_put_contents( $published, 'foreign' );
		touch( $published, 1700000500 );

		$this->assertFails( FileMover::MISMATCH, fn() => FileIdentity::removeOriginal( $this->source, (array) $cursor['source'], $published, (array) $cursor['id'] ) );
		$this->assertFileExists( $this->source );
	}
}
