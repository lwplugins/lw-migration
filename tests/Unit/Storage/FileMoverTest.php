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

final class FileMoverTest extends TestCase {

	private string $dir;
	private string $source;
	private string $target;

	protected function setUp(): void {
		$this->dir    = TempDir::create();
		$this->source = $this->dir . '/a.wpress';
		$this->target = $this->dir . '/.lwm-0123456789abcdef.part';
		file_put_contents( $this->source, random_bytes( 10000 ) );
		chmod( $this->source, 0644 );
		touch( $this->source, 1700000000 );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function copier( int $chunk = 1024, ?int $free = null ): FileMover {
		return new FileMover( false, $chunk, static fn( string $d ): ?int => $free );
	}

	/**
	 * @param array<string, mixed> $cursor Cursor.
	 */
	private function copyToEnd( FileMover $mover, array &$cursor ): void {
		for ( $calls = 0; $calls < 100; $calls++ ) {
			if ( $mover->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 2 ) ) ) {
				return;
			}
		}
		$this->fail( 'The copy did not finish.' );
	}

	/**
	 * @param string $code Expected FileMover code.
	 */
	private function assertFails( int $code, \Closure $call ): void {
		try {
			$call();
			$this->fail( 'Expected code ' . $code );
		} catch ( StorageException $e ) {
			$this->assertSame( $code, $e->getCode() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage(), 'No path in the message.' );
		}
	}

	public function test_a_hard_link_stands_in_on_one_file_system(): void {
		$cursor = [];
		$this->assertTrue( ( new FileMover() )->copy( $this->source, $this->target, $cursor, new NoDeadline() ) );
		$this->assertSame( fileinode( $this->source ), fileinode( $this->target ) );
		$this->assertSame( FileIdentity::of( $this->target ), $cursor['id'] );
	}

	public function test_a_copy_runs_over_several_calls_and_keeps_bytes_mode_and_mtime(): void {
		$before = hash_file( 'sha256', $this->source );
		$cursor = [];
		$calls  = 0;
		do {
			++$calls;
			$done = $this->copier()->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 2 ) );
		} while ( ! $done && $calls < 100 );
		$this->assertGreaterThan( 2, $calls );
		$this->assertSame( $before, hash_file( 'sha256', $this->target ) );
		$this->assertSame( $before, hash_file( 'sha256', $this->source ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $this->target ) & 0777 );
		$this->assertSame( 1700000000, filemtime( $this->target ) );
		$this->assertNotSame( fileinode( $this->source ), fileinode( $this->target ) );
		$this->assertSame( FileIdentity::of( $this->target ), $cursor['id'], 'The copy is named by its identity.' );
		$this->assertSame( FileIdentity::of( $this->source ), $cursor['source'], 'The original as it was copied.' );
	}

	public function test_a_world_readable_source_ends_owner_only_with_links_on_and_off(): void {
		foreach ( [ true, false ] as $links ) {
			if ( file_exists( $this->target ) ) {
				unlink( $this->target );
			}
			chmod( $this->source, 0644 );
			$cursor = [];
			$this->copyToEnd( new FileMover( $links, 1024 ), $cursor );
			clearstatcache();
			$this->assertSame( 0600, fileperms( $this->target ) & 0777, $links ? 'link' : 'copy' );
		}
	}

	public function test_the_temp_is_owner_only_while_it_is_copied(): void {
		$cursor = [];
		$this->assertFalse( $this->copier()->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 2 ) ) );
		clearstatcache();
		$this->assertGreaterThan( 0, filesize( $this->target ) );
		$this->assertLessThan( 10000, filesize( $this->target ), 'Still in the middle of the copy.' );
		$this->assertSame( 0600, fileperms( $this->target ) & 0777 );
	}

	public function test_a_copy_resumes_after_a_request_died_mid_chunk(): void {
		$cursor = [];
		$this->copier()->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 1 ) );
		$saved = $cursor;
		file_put_contents( $this->target, 'garbage written after the cursor was saved', FILE_APPEND );
		$cursor = $saved;
		while ( ! $this->copier()->copy( $this->source, $this->target, $cursor, new NoDeadline() ) ) {
			continue;
		}
		$this->assertSame( hash_file( 'sha256', $this->source ), hash_file( 'sha256', $this->target ) );
	}

	public function test_a_changed_source_restarts_the_copy(): void {
		$cursor = [];
		$this->copier()->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 1 ) );
		file_put_contents( $this->source, random_bytes( 5000 ) );
		while ( ! $this->copier()->copy( $this->source, $this->target, $cursor, new NoDeadline() ) ) {
			continue;
		}
		$this->assertSame( hash_file( 'sha256', $this->source ), hash_file( 'sha256', $this->target ) );
	}

	public function test_a_copy_whose_temp_vanished_starts_over(): void {
		$cursor = [];
		$this->copyToEnd( $this->copier(), $cursor );
		unlink( $this->target );

		$this->copyToEnd( $this->copier(), $cursor );

		$this->assertSame( hash_file( 'sha256', $this->source ), hash_file( 'sha256', $this->target ) );
	}

	public function test_a_temp_replaced_by_a_link_is_never_written_through(): void {
		$cursor = [];
		$this->copier()->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 1 ) );
		$other = $this->dir . '/other.txt';
		file_put_contents( $other, 'not ours' );
		unlink( $this->target );
		symlink( $other, $this->target );

		$this->copyToEnd( $this->copier(), $cursor );

		$this->assertSame( 'not ours', file_get_contents( $other ) );
		$this->assertFalse( is_link( $this->target ) );
		$this->assertSame( hash_file( 'sha256', $this->source ), hash_file( 'sha256', $this->target ) );
	}

	public function test_a_corrupted_copy_is_refused_and_removed(): void {
		$cursor = [];
		$mover  = $this->copier( 100000 );
		$mover->copy( $this->source, $this->target, $cursor, new CountdownDeadline( 1 ) ); // copied, verify not started
		$this->assertSame( 'verify', $cursor['phase'] );
		$handle = fopen( $this->target, 'r+b' );
		$byte   = (string) fread( $handle, 1 );
		rewind( $handle );
		fwrite( $handle, chr( ord( $byte ) ^ 0xff ) ); // Always another byte.
		fclose( $handle );
		$this->assertFails( FileMover::MISMATCH, fn() => $mover->copy( $this->source, $this->target, $cursor, new NoDeadline() ) );
		$this->assertFileDoesNotExist( $this->target );
		$this->assertFileExists( $this->source );
	}

	public function test_too_little_free_space_is_refused_before_writing(): void {
		$cursor = [];
		$this->assertFails( FileMover::FULL, fn() => $this->copier( 1024, 1000 )->copy( $this->source, $this->target, $cursor, new NoDeadline() ) );
		$this->assertFileDoesNotExist( $this->target );
		$this->assertFileExists( $this->source );
	}

	public function test_a_missing_or_linked_source_is_refused(): void {
		$cursor = [];
		symlink( $this->source, $this->dir . '/link.wpress' );
		foreach ( [ $this->dir . '/none.wpress', $this->dir . '/link.wpress' ] as $source ) {
			$this->assertFails( FileMover::MISSING, fn() => $this->copier()->copy( $source, $this->target, $cursor, new NoDeadline() ) );
		}
		$this->assertFileDoesNotExist( $this->target );
	}

	public function test_a_target_that_is_not_our_temp_name_is_refused(): void {
		$cursor = [];
		$other  = $this->dir . '/b.wpress';
		file_put_contents( $other, 'someone else' );
		$this->assertFails( FileMover::IO, fn() => $this->copier()->copy( $this->source, $other, $cursor, new NoDeadline() ) );
		$this->assertSame( 'someone else', file_get_contents( $other ) );
	}

	public function test_an_unwritable_target_folder_fails_without_touching_the_source(): void {
		if ( 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root writes into read-only folders' );
		}
		mkdir( $this->dir . '/ro' );
		chmod( $this->dir . '/ro', 0555 );
		$cursor = [];
		try {
			$this->assertFails( FileMover::IO, fn() => $this->copier()->copy( $this->source, $this->dir . '/ro/.lwm-0123456789abcdef.part', $cursor, new NoDeadline() ) );
		} finally {
			chmod( $this->dir . '/ro', 0755 );
		}
		$this->assertSame( [], $cursor, 'A failed copy starts over next time.' );
		$this->assertSame( 10000, filesize( $this->source ) );
	}

	public function test_a_finished_temp_whose_bytes_changed_in_place_starts_over(): void {
		$cursor = [];
		$this->copyToEnd( $this->copier(), $cursor );
		file_put_contents( $this->target, 'x', FILE_APPEND ); // Same inode, other size.

		$this->copyToEnd( $this->copier(), $cursor );

		$this->assertSame( hash_file( 'sha256', $this->source ), hash_file( 'sha256', $this->target ) );
	}

	/**
	 * @return array<string, array{0: int, 1: int|null, 2: int}>
	 */
	public static function writeFailures(): array {
		return [
			'past 4 GiB with room left' => [ FileMover::FAT_LIMIT + 1, 1 << 40, FileMover::TOO_BIG ],
			'past 4 GiB, room unknown'  => [ FileMover::FAT_LIMIT + 1, null, FileMover::TOO_BIG ],
			'past 4 GiB and full'       => [ FileMover::FAT_LIMIT + 1, 10, FileMover::FULL ],
			'full'                      => [ 1000, 10, FileMover::FULL ],
			'room left'                 => [ 1000, 1 << 40, FileMover::IO ],
			'room unknown'              => [ 1000, null, FileMover::IO ],
		];
	}

	/**
	 * @dataProvider writeFailures
	 */
	public function test_a_failed_write_is_told_apart( int $end, ?int $free, int $code ): void {
		$this->assertSame( $code, FileMover::writeFailure( $end, $free, 1024 ) );
	}
}
