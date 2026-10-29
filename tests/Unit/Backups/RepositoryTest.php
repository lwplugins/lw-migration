<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		file_put_contents( $this->dir . '/own/new.wpress', 'abc' );
		touch( $this->dir . '/own/new.wpress', 2000 );
		file_put_contents( $this->dir . '/ext/old.wpress', 'abcdef' );
		touch( $this->dir . '/ext/old.wpress', 1000 );
		file_put_contents( $this->dir . '/own/notes.txt', 'x' );
		file_put_contents( $this->dir . '/secret.wpress', 'x' );
		symlink( $this->dir . '/secret.wpress', $this->dir . '/own/link.wpress' );
	}

	protected function tearDown(): void {
		chmod( $this->dir . '/ext', 0755 );
		TempDir::remove( $this->dir );
	}

	public function test_lists_both_folders_newest_first(): void {
		$all = ( new Repository( $this->dir . '/own', $this->dir . '/ext' ) )->all();
		$this->assertSame( [ 'new.wpress', 'old.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $all ) );
		$this->assertSame( Backup::OWN, $all[0]->source );
		$this->assertSame( Backup::EXTERNAL, $all[1]->source );
		$this->assertSame( 6, $all[1]->size );
	}

	public function test_find_refuses_traversal_and_hidden_names(): void {
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );
		$this->assertNull( $repo->find( '../secret.wpress' ) );
		$this->assertNull( $repo->find( 'link.wpress' ), 'Symlinks are not backups.' );
		$this->assertNull( $repo->find( 'notes.txt' ) );
		$this->assertSame( $this->dir . '/ext/old.wpress', $repo->find( 'old.wpress' )->path );
	}

	public function test_an_empty_file_is_no_backup(): void {
		touch( $this->dir . '/own/site-20260101-000000-001.wpress' ); // A name reserved by a store that died.
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );

		$this->assertNull( $repo->find( 'site-20260101-000000-001.wpress' ) );
		$this->assertSame( [ 'new.wpress', 'old.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $repo->all() ) );
	}

	public function test_delete(): void {
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );
		$this->assertTrue( $repo->delete( 'old.wpress' ) );
		$this->assertFalse( $repo->delete( 'old.wpress' ) );
		$this->assertFileExists( $this->dir . '/secret.wpress' );
	}

	public function test_delete_touches_only_valid_regular_files_inside_the_two_folders(): void {
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );
		mkdir( $this->dir . '/own/folder.wpress' );

		$this->assertFalse( $repo->delete( '../secret.wpress' ) );
		$this->assertFalse( $repo->delete( 'link.wpress' ) );
		$this->assertFalse( $repo->delete( 'notes.txt' ) );
		$this->assertFalse( $repo->delete( 'folder.wpress' ) );
		$this->assertFalse( $repo->delete( '.hidden.wpress' ) );

		$this->assertFileExists( $this->dir . '/secret.wpress' );
		$this->assertTrue( is_link( $this->dir . '/own/link.wpress' ), 'The symlink itself stays.' );
		$this->assertFileExists( $this->dir . '/own/notes.txt' );
		$this->assertDirectoryExists( $this->dir . '/own/folder.wpress' );
	}

	public function test_listing_skips_symlinks_folders_and_other_files(): void {
		mkdir( $this->dir . '/own/folder.wpress' );
		$names = array_map( static fn( Backup $b ): string => $b->name, ( new Repository( $this->dir . '/own', '' ) )->all() );

		$this->assertSame( [ 'new.wpress' ], $names );
	}

	public function test_an_unreadable_folder_is_skipped_without_a_warning(): void {
		chmod( $this->dir . '/ext', 0000 );
		if ( is_readable( $this->dir . '/ext' ) ) {
			$this->markTestSkipped( 'Running with privileges that ignore folder permissions.' );
		}
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );

		$this->assertSame( [ 'new.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $repo->all() ) );
		$this->assertNull( $repo->find( 'old.wpress' ) );
	}

	public function test_delete_in_a_read_only_folder_fails_without_a_warning(): void {
		chmod( $this->dir . '/ext', 0555 );
		if ( is_writable( $this->dir . '/ext' ) ) {
			$this->markTestSkipped( 'Running with privileges that ignore folder permissions.' );
		}
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );

		$this->assertFalse( $repo->delete( 'old.wpress' ) );
		$this->assertFileExists( $this->dir . '/ext/old.wpress' );
	}

	public function test_find_and_delete_in_one_folder(): void {
		file_put_contents( $this->dir . '/own/twin.wpress', 'own' );
		file_put_contents( $this->dir . '/ext/twin.wpress', 'external' );
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext' );

		$this->assertSame( $this->dir . '/own/twin.wpress', $repo->findIn( 'twin.wpress', Backup::OWN )->path );
		$this->assertSame( $this->dir . '/ext/twin.wpress', $repo->findIn( 'twin.wpress', Backup::EXTERNAL )->path );
		$this->assertSame( Backup::EXTERNAL, $repo->findIn( 'twin.wpress', Backup::EXTERNAL )->source );
		$this->assertNull( $repo->findIn( 'twin.wpress', 'tmp' ) );
		$this->assertNull( $repo->findIn( '../secret.wpress', Backup::OWN ) );
		$this->assertNull( $repo->findIn( 'link.wpress', Backup::OWN ) );
		$this->assertNull( ( new Repository( $this->dir . '/own', '' ) )->findIn( 'twin.wpress', Backup::EXTERNAL ) );

		$this->assertTrue( $repo->deleteIn( 'twin.wpress', Backup::EXTERNAL ) );
		$this->assertFileDoesNotExist( $this->dir . '/ext/twin.wpress' );
		$this->assertFileExists( $this->dir . '/own/twin.wpress' );
		$this->assertFalse( $repo->deleteIn( 'twin.wpress', Backup::EXTERNAL ) );
	}

	public function test_an_unfinished_move_lists_both_folders_once(): void {
		$this->moveFolders();
		$repo = new Repository( $this->dir . '/a', $this->dir . '/ext', $this->dir . '/b' );

		$all = $repo->all();
		$this->assertSame( [ 'c.wpress', 'b.wpress', 'a.wpress', 'old.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $all ) );
		$this->assertSame( [ Backup::OWN, Backup::OWN, Backup::OWN, Backup::EXTERNAL ], array_map( static fn( Backup $b ): string => $b->source, $all ) );
		$this->assertSame( $this->dir . '/a/b.wpress', $all[1]->path, 'A name in both folders counts once, from the current folder.' );
		$this->assertSame( $this->dir . '/b/c.wpress', $all[0]->path );
	}

	public function test_find_find_in_delete_and_delete_in_reach_the_moving_folder(): void {
		$this->moveFolders();
		$repo = new Repository( $this->dir . '/a', $this->dir . '/ext', $this->dir . '/b' );

		$this->assertSame( $this->dir . '/b/c.wpress', $repo->find( 'c.wpress' )->path );
		$this->assertSame( $this->dir . '/b/c.wpress', $repo->findIn( 'c.wpress', Backup::OWN )->path );
		$this->assertNull( $repo->findIn( 'c.wpress', Backup::EXTERNAL ) );
		$this->assertTrue( $repo->deleteIn( 'c.wpress', Backup::OWN ) );
		$this->assertFileDoesNotExist( $this->dir . '/b/c.wpress' );
		$this->assertTrue( $repo->delete( 'a.wpress' ) );
		$this->assertFileDoesNotExist( $this->dir . '/a/a.wpress' );
	}

	public function test_delete_removes_both_copies_an_interrupted_move_left(): void {
		$this->moveFolders();
		$repo = new Repository( $this->dir . '/a', $this->dir . '/ext', $this->dir . '/b' );

		$this->assertTrue( $repo->deleteIn( 'b.wpress', Backup::OWN ) );
		$this->assertFileDoesNotExist( $this->dir . '/a/b.wpress' );
		$this->assertFileDoesNotExist( $this->dir . '/b/b.wpress', 'Never a copy left behind that shows up again.' );
		$this->assertNull( $repo->find( 'b.wpress' ) );

		copy( $this->dir . '/b/c.wpress', $this->dir . '/a/c.wpress' );
		$this->assertTrue( $repo->delete( 'c.wpress' ) );
		$this->assertSame( [ 'a.wpress', 'old.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $repo->all() ) );
	}

	public function test_without_a_moving_folder_nothing_changes(): void {
		$two   = new Repository( $this->dir . '/own', $this->dir . '/ext' );
		$three = new Repository( $this->dir . '/own', $this->dir . '/ext', '' );
		$same  = new Repository( $this->dir . '/own', $this->dir . '/ext', $this->dir . '/own' );

		foreach ( [ $two, $three, $same ] as $repo ) {
			$this->assertSame( [ 'new.wpress', 'old.wpress' ], array_map( static fn( Backup $b ): string => $b->name, $repo->all() ) );
			$this->assertSame( $this->dir . '/own/new.wpress', $repo->findIn( 'new.wpress', Backup::OWN )->path );
		}
	}

	public function test_the_external_folder_is_left_out_when_it_is_one_of_ours_by_identity(): void {
		$lower = $this->dir . '/own';
		$upper = $this->dir . '/OWN';
		if ( ! is_dir( $upper ) ) {
			$this->markTestSkipped( 'Needs a case-insensitive file system.' );
		}
		$repo = new Repository( $lower, $upper );

		$this->assertSame( [ [ 'new.wpress', Backup::OWN ] ], array_map( static fn( Backup $b ): array => [ $b->name, $b->source ], $repo->all() ) );
		$this->assertSame( [ [ 'new.wpress', Backup::OWN ] ], array_map( static fn( Backup $b ): array => [ $b->name, $b->source ], ( new Repository( $this->dir . '/x', $upper, $lower ) )->all() ), 'Also the moving folder.' );
	}

	public function test_an_empty_file_in_the_moving_folder_is_no_backup(): void {
		$this->moveFolders();
		touch( $this->dir . '/b/site-20260101-000000-001.wpress' );
		$repo = new Repository( $this->dir . '/a', '', $this->dir . '/b' );

		$this->assertNull( $repo->find( 'site-20260101-000000-001.wpress' ) );
		$this->assertNotContains( 'site-20260101-000000-001.wpress', array_map( static fn( Backup $b ): string => $b->name, $repo->all() ) );
	}

	/** Current folder a = {a, b}, moving folder b = {b, c}; the external folder keeps old.wpress. */
	private function moveFolders(): void {
		mkdir( $this->dir . '/a' );
		mkdir( $this->dir . '/b' );
		foreach ( [ 'a/a.wpress' => 1000, 'a/b.wpress' => 2000, 'b/b.wpress' => 2000, 'b/c.wpress' => 3000 ] as $rel => $mtime ) {
			file_put_contents( $this->dir . '/' . $rel, 'data' );
			touch( $this->dir . '/' . $rel, $mtime );
		}
		touch( $this->dir . '/ext/old.wpress', 500 );
	}
}
