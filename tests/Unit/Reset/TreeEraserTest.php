<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\TreeEraser;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\Site;
use PHPUnit\Framework\TestCase;

final class TreeEraserTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private function tree( string $root, array $files ): void {
		foreach ( $files as $file ) {
			@mkdir( dirname( $root . '/' . $file ), 0755, true );
			file_put_contents( $root . '/' . $file, 'x' );
		}
	}

	private static function asRoot(): bool {
		return 0 === ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : -1 );
	}

	public function test_a_folder_is_emptied_and_removed(): void {
		$this->tree( $this->dir . '/plugin', [ 'plugin.php', 'inc/a.php', 'inc/deep/b.php', 'assets/c.css' ] );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/plugin', true, new NoDeadline() );
		$this->assertSame( [ 'done' => true, 'removed' => 8, 'failed' => 0, 'skipped' => 0 ], $result ); // 4 files + inc/deep, inc, assets, plugin
		$this->assertFileDoesNotExist( $this->dir . '/plugin' );
	}

	public function test_a_linked_folder_inside_is_removed_as_a_link_and_its_target_survives(): void {
		$this->tree( $this->dir . '/shared', [ 'keep.txt', 'sub/keep2.txt' ] );
		$this->tree( $this->dir . '/plugin', [ 'plugin.php' ] );
		symlink( $this->dir . '/shared', $this->dir . '/plugin/vendor' );
		symlink( $this->dir . '/shared/keep.txt', $this->dir . '/plugin/file-link' );
		( new TreeEraser( [] ) )->erase( $this->dir . '/plugin', true, new NoDeadline() );
		$this->assertFileDoesNotExist( $this->dir . '/plugin' );
		$this->assertFileExists( $this->dir . '/shared/keep.txt' );
		$this->assertFileExists( $this->dir . '/shared/sub/keep2.txt' );
	}

	public function test_a_linked_root_is_removed_as_a_link_or_emptied_through_when_it_stays(): void {
		$this->tree( $this->dir . '/real', [ 'a.jpg', 'b/c.jpg' ] );
		symlink( $this->dir . '/real', $this->dir . '/linked' );
		( new TreeEraser( [] ) )->erase( $this->dir . '/linked', false, new NoDeadline() );
		$this->assertTrue( is_link( $this->dir . '/linked' ), 'The root stays.' );
		$this->assertSame( [ '.', '..' ], scandir( $this->dir . '/real' ), 'Its content goes.' );
		$this->tree( $this->dir . '/real', [ 'd.jpg' ] );
		( new TreeEraser( [] ) )->erase( $this->dir . '/linked', true, new NoDeadline() );
		$this->assertFalse( is_link( $this->dir . '/linked' ) );
		$this->assertFileExists( $this->dir . '/real/d.jpg', 'Removing a linked root removes the link only.' );
	}

	public function test_sealed_folders_are_never_entered_also_under_another_name(): void {
		$this->tree( $this->dir . '/uploads', [ 'a.jpg', 'lw-backups/site.wpress' ] );
		$sealed = FolderIdentity::ids( [ $this->dir . '/uploads/lw-backups' ] );
		$result = ( new TreeEraser( $sealed ) )->erase( $this->dir . '/uploads', false, new NoDeadline() );
		$this->assertFileExists( $this->dir . '/uploads/lw-backups/site.wpress' );
		$this->assertFileDoesNotExist( $this->dir . '/uploads/a.jpg' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_a_sealed_folder_known_only_by_another_spelling_is_found_by_identity(): void {
		$this->tree( $this->dir . '/uploads', [ 'a.jpg', 'store/site.wpress' ] );
		symlink( $this->dir . '/uploads/store', $this->dir . '/alias' );
		$sealed = FolderIdentity::ids( [ $this->dir . '/alias' ] ); // The configured spelling differs from the walked one.
		$result = ( new TreeEraser( $sealed ) )->erase( $this->dir . '/uploads', true, new NoDeadline() );
		$this->assertFileExists( $this->dir . '/uploads/store/site.wpress' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertDirectoryExists( $this->dir . '/uploads', 'A root holding a sealed folder stays.' );
	}

	public function test_a_sealed_root_is_skipped_whole(): void {
		$this->tree( $this->dir . '/backups', [ 'one.wpress' ] );
		$result = ( new TreeEraser( FolderIdentity::ids( [ $this->dir . '/backups' ] ) ) )->erase( $this->dir . '/backups', true, new NoDeadline() );
		$this->assertSame( [ 'done' => true, 'removed' => 0, 'failed' => 0, 'skipped' => 1 ], $result );
		$this->assertFileExists( $this->dir . '/backups/one.wpress' );
	}

	public function test_protection_files_of_the_root_stay(): void {
		$this->tree( $this->dir . '/uploads', [ '.htaccess', 'index.php', '2026/10/index.php', '2026/10/a.jpg' ] );
		( new TreeEraser( [], TreeEraser::PROTECTION_FILES ) )->erase( $this->dir . '/uploads', false, new NoDeadline() );
		$this->assertSame( [ '.', '..', '.htaccess', 'index.php' ], scandir( $this->dir . '/uploads' ), 'Only the root\'s own protection files.' );
	}

	public function test_a_folder_that_cannot_be_emptied_keeps_its_deny_file(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		$this->tree( $this->dir . '/up', [ 'private/.htaccess', 'private/sub/a.pdf', 'open/.HTACCESS', 'open/b.jpg' ] );
		chmod( $this->dir . '/up/private/sub', 0555 );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', false, new NoDeadline() );
		chmod( $this->dir . '/up/private/sub', 0755 );
		$this->assertFileExists( $this->dir . '/up/private/.htaccess', 'Its private file stayed, so its deny file stays.' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/up/open', 'An emptied folder loses its deny file last.' );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_a_pass_cut_short_never_leaves_a_folder_without_its_deny_file(): void {
		$this->tree( $this->dir . '/up/private', [ '.htaccess', 'a.pdf', 'b.pdf' ] );
		$first = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', true, new CountdownDeadline( 0 ) );
		$this->assertFalse( $first['done'] );
		$this->assertFileExists( $this->dir . '/up/private/.htaccess', 'Sorted first, yet it waits for the rest of its folder.' );
		$this->assertFileExists( $this->dir . '/up/private/b.pdf' );
		$second = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', true, new NoDeadline() );
		$this->assertTrue( $second['done'] );
		$this->assertFileDoesNotExist( $this->dir . '/up' );
	}

	public function test_a_pass_cut_by_the_deadline_resumes_with_a_fresh_pass(): void {
		$files = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$files[] = 'd' . ( $i % 3 ) . '/f' . $i . '.txt';
		}
		$this->tree( $this->dir . '/big', $files );
		$eraser = new TreeEraser( [] );
		$first  = $eraser->erase( $this->dir . '/big', true, new CountdownDeadline( 10 ) );
		$this->assertFalse( $first['done'] );
		$this->assertGreaterThan( 0, $first['removed'] );
		$second = $eraser->erase( $this->dir . '/big', true, new NoDeadline() );
		$this->assertTrue( $second['done'] );
		$this->assertFileDoesNotExist( $this->dir . '/big' );
	}

	public function test_files_that_cannot_be_removed_do_not_use_up_a_pass(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		$files = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$files[] = 'a-locked/f' . $i . '.txt';
		}
		$this->tree( $this->dir . '/up', array_merge( $files, [ 'b.txt', 'c.txt' ] ) );
		chmod( $this->dir . '/up/a-locked', 0555 );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', false, new CountdownDeadline( 0 ) );
		chmod( $this->dir . '/up/a-locked', 0755 );
		$this->assertSame( 1, $result['removed'], 'The pass ends after its first removal, not after its first failure.' );
		$this->assertSame( 25, $result['failed'] );
	}

	public function test_what_cannot_be_removed_is_counted_not_hidden(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		$this->tree( $this->dir . '/up', [ 'locked/a.jpg', 'free.jpg' ] );
		chmod( $this->dir . '/up/locked', 0555 );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', false, new NoDeadline() );
		chmod( $this->dir . '/up/locked', 0755 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertFileDoesNotExist( $this->dir . '/up/free.jpg' );
	}

	public function test_an_unreadable_folder_is_one_failure_and_stays(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		$this->tree( $this->dir . '/up', [ 'closed/a.jpg', 'free.jpg' ] );
		chmod( $this->dir . '/up/closed', 0000 );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/up', true, new NoDeadline() );
		chmod( $this->dir . '/up/closed', 0755 );
		$this->assertSame( [ 'done' => true, 'removed' => 1, 'failed' => 1, 'skipped' => 0 ], $result );
		$this->assertFileExists( $this->dir . '/up/closed/a.jpg' );
	}

	/**
	 * A deadline that runs $swap once, at its first check (between two entries of a walk).
	 */
	private static function swapAtFirstCheck( \Closure $swap ): Deadline {
		return new class( $swap ) implements Deadline {
			private bool $done = false;

			public function __construct( private \Closure $swap ) {
			}

			public function reached(): bool {
				if ( ! $this->done ) {
					$this->done = true;
					( $this->swap )();
				}
				return false;
			}
		};
	}

	public function test_a_folder_swapped_for_a_link_while_it_is_emptied_is_left_and_its_target_survives(): void {
		$this->tree( $this->dir . '/victim', [ 'a1.txt', 'a2.txt', 'a3.txt' ] );
		$this->tree( $this->dir . '/plugin', [ 'X/a1.txt', 'X/a2.txt', 'X/a3.txt' ] );
		$base   = $this->dir;
		$eraser = new TreeEraser( [] );
		$result = $eraser->erase(
			$this->dir . '/plugin',
			true,
			self::swapAtFirstCheck(
				static function () use ( $base ): void {
					rename( $base . '/plugin/X', $base . '/X.moved' );
					symlink( $base . '/victim', $base . '/plugin/X' );
				}
			)
		);
		$this->assertSame( [ '.', '..', 'a1.txt', 'a2.txt', 'a3.txt' ], scandir( $this->dir . '/victim' ) );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $eraser->skippedFor()['changed'] );
		$this->assertTrue( is_link( $this->dir . '/plugin/X' ), 'The swapped-in link is left for the person to look at.' );
	}

	public function test_a_parent_swapped_for_a_link_stops_the_walk_below_it(): void {
		$this->tree( $this->dir . '/victim', [ 'deep/z.txt', 'y.txt' ] );
		$this->tree( $this->dir . '/plugin', [ 'A/1.txt', 'A/deep/z.txt', 'A/y.txt' ] );
		$base = $this->dir;
		( new TreeEraser( [] ) )->erase(
			$this->dir . '/plugin',
			true,
			self::swapAtFirstCheck(
				static function () use ( $base ): void {
					rename( $base . '/plugin/A', $base . '/A.moved' );
					symlink( $base . '/victim', $base . '/plugin/A' );
				}
			)
		);
		$this->assertFileExists( $this->dir . '/victim/deep/z.txt' );
		$this->assertFileExists( $this->dir . '/victim/y.txt' );
	}

	public function test_a_folder_on_another_device_is_never_entered(): void {
		$this->tree( $this->dir . '/plugin', [ 'p.php', 'mnt/shared.txt' ] );
		$mnt    = $this->dir . '/plugin/mnt';
		$eraser = new TreeEraser( [], [], static fn( string $path, string $id ): string => $path === $mnt ? 'other-disk' : explode( ':', $id )[0] );
		$result = $eraser->erase( $this->dir . '/plugin', true, new NoDeadline() );
		$this->assertFileExists( $mnt . '/shared.txt' );
		$this->assertFileDoesNotExist( $this->dir . '/plugin/p.php' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame(
			[
				'sealed'  => 0,
				'core'    => 0,
				'device'  => 1,
				'changed' => 0,
			],
			$eraser->skippedFor()
		);
	}

	public function test_a_fifo_or_a_socket_is_removed_like_a_file(): void {
		if ( ! function_exists( 'posix_mkfifo' ) ) {
			$this->markTestSkipped( 'Needs posix_mkfifo().' );
		}
		$this->tree( $this->dir . '/plugin', [ 'p.php' ] );
		posix_mkfifo( $this->dir . '/plugin/pipe', 0600 );
		$result = ( new TreeEraser( [] ) )->erase( $this->dir . '/plugin', true, new NoDeadline() );
		$this->assertSame( [ 'done' => true, 'removed' => 3, 'failed' => 0, 'skipped' => 0 ], $result );
		$this->assertFileDoesNotExist( $this->dir . '/plugin' );
	}

	public function test_a_scan_pass_ends_at_the_deadline_without_removing_anything_and_resumes_after_its_position(): void {
		$sealed = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$this->tree( $this->dir . '/up', [ sprintf( 'kept-%02d/site.wpress', $i ) ] );
			$sealed[] = $this->dir . sprintf( '/up/kept-%02d', $i );
		}
		$this->tree( $this->dir . '/up', [ 'zz/a.jpg' ] );
		$eraser    = new TreeEraser( FolderIdentity::ids( $sealed ) );
		$positions = [];
		$skipped   = 0;
		$position  = '';
		do {
			$result      = $eraser->erase( $this->dir . '/up', false, new CountdownDeadline( 4 ), $position );
			$skipped    += $result['skipped'];
			$position    = $eraser->position();
			$positions[] = $position;
		} while ( ! $result['done'] && count( $positions ) < 100 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 40, $skipped, 'Each sealed folder counted once over all passes.' );
		$this->assertSame( 'kept-04', $positions[0], 'A pass that removed nothing still ends at the deadline.' );
		$this->assertCount( count( $positions ) - 1, array_unique( array_slice( $positions, 0, -1 ) ), 'Every cut pass moved on.' );
		$this->assertFileDoesNotExist( $this->dir . '/up/zz' );
		$this->assertFileExists( $this->dir . '/up/kept-00/site.wpress' );
	}

	public function test_a_scan_resumed_inside_a_folder_still_removes_its_deny_file_last(): void {
		$this->tree( $this->dir . '/up', [ 'a/.htaccess', 'a/b/1.jpg', 'a/b/2.jpg', 'a/c.jpg', 'a/d.jpg', 'e.jpg' ] );
		$eraser = new TreeEraser( [], TreeEraser::PROTECTION_FILES );
		$first  = $eraser->erase( $this->dir . '/up', false, new CountdownDeadline( 1 ), '' );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 'a/b/2.jpg', $eraser->position() );
		$this->assertFileExists( $this->dir . '/up/a/.htaccess' );
		$second = $eraser->erase( $this->dir . '/up', false, new NoDeadline(), $eraser->position() );
		$this->assertTrue( $second['done'] );
		$this->assertSame( [ '.', '..' ], scandir( $this->dir . '/up' ), 'The resumed folder lost its deny file once it was empty.' );
	}

	public function test_a_scan_resumed_never_revisits_what_it_passed(): void {
		if ( self::asRoot() ) {
			$this->markTestSkipped( 'root ignores folder permissions' );
		}
		$this->tree( $this->dir . '/up', [ 'locked/1.jpg', 'locked/2.jpg', 'locked/3.jpg', 'next/4.jpg' ] );
		chmod( $this->dir . '/up/locked', 0555 );
		$eraser = new TreeEraser( [] );
		$first  = $eraser->erase( $this->dir . '/up', false, new CountdownDeadline( 1 ), '' );
		$cut    = $eraser->position();
		$second = $eraser->erase( $this->dir . '/up', false, new NoDeadline(), $cut );
		chmod( $this->dir . '/up/locked', 0755 );
		$this->assertSame( 'locked/2.jpg', $cut );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 2, $first['failed'] );
		$this->assertSame( 1, $second['failed'], 'Only 3.jpg is new; 1 and 2 were counted by the first pass.' );
		$this->assertTrue( $second['done'] );
		$this->assertFileDoesNotExist( $this->dir . '/up/next' );
	}

	public function test_a_missing_root_is_done(): void {
		$this->assertSame( [ 'done' => true, 'removed' => 0, 'failed' => 0, 'skipped' => 0 ], ( new TreeEraser( [] ) )->erase( $this->dir . '/none', true, new NoDeadline() ) );
	}

	public function test_a_core_folder_mounted_inside_is_never_entered(): void {
		$this->tree( $this->dir . '/up', [ 'a.jpg', 'bindp/p/p.php' ] );
		$site   = Site::fromArray(
			[
				'abspath'      => $this->dir . '/wp/',
				'contentDir'   => $this->dir . '/wp/wp-content',
				'pluginsDir'   => $this->dir . '/up/bindp', // The same identity as a bind mount of the plugins folder gives.
				'muPluginsDir' => '',
				'themeRoot'    => '',
			]
		);
		$eraser = TreeEraser::forSite( [], $site );
		$result = $eraser->erase( $this->dir . '/up', false, new NoDeadline() );
		$this->assertFileExists( $this->dir . '/up/bindp/p/p.php' );
		$this->assertFileDoesNotExist( $this->dir . '/up/a.jpg' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 1, $eraser->skippedFor()['core'] );
	}

	public function test_a_core_root_is_skipped_whole(): void {
		$this->tree( $this->dir . '/themes/t', [ 'style.css' ] );
		$site   = Site::fromArray( [ 'themes' => [ 'x' => $this->dir . '/themes/t' ] ] );
		$result = TreeEraser::forSite( [], $site )->erase( $this->dir . '/themes/t', true, new NoDeadline() );
		$this->assertSame( [ 'done' => true, 'removed' => 0, 'failed' => 0, 'skipped' => 1 ], $result );
		$this->assertFileExists( $this->dir . '/themes/t/style.css' );
	}
}
