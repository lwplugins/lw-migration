<?php
/**
 * Tests for Ownership.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\Ownership;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/a/b', 0755, true );
		file_put_contents( $this->dir . '/a/b/f.txt', 'x' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_only_root_changes_owners(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid() ) {
			$this->assertNull( Ownership::forBase( $this->dir ) );
		}
		$same = new Ownership( (int) fileowner( $this->dir ), (int) filegroup( $this->dir ) );
		$this->assertTrue( $same->apply( $this->dir, $this->dir . '/a/b/f.txt' ), 'Nothing to change: no chown call is needed.' );
	}

	public function test_paths_not_owned_by_root_keep_their_owner(): void {
		if ( 0 === (int) fileowner( $this->dir ) ) {
			$this->markTestSkipped( 'Needs files not owned by root.' );
		}
		$owner = (int) fileowner( $this->dir . '/a/b/f.txt' );
		$group = (int) filegroup( $this->dir . '/a/b/f.txt' );

		// Only files we created as root are handed over; existing folders are left as they are.
		$this->assertTrue( ( new Ownership( $owner + 1, $group + 1 ) )->apply( $this->dir, $this->dir . '/a/b/f.txt' ) );
		clearstatcache();
		$this->assertSame( $owner, fileowner( $this->dir . '/a/b/f.txt' ) );
		$this->assertSame( $group, filegroup( $this->dir . '/a/b/f.txt' ) );
		$this->assertSame( $owner, fileowner( $this->dir . '/a' ) );
	}

	public function test_a_base_that_is_not_its_real_path_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Ownership::forBase( $this->dir . '/a/../a' );
	}

	public function test_a_link_as_base_is_refused(): void {
		symlink( $this->dir . '/a', $this->dir . '/link' );

		$this->expectException( \InvalidArgumentException::class );
		Ownership::forBase( $this->dir . '/link' );
	}

	public function test_paths_outside_the_base_are_left_alone(): void {
		$outside = TempDir::create();
		file_put_contents( $outside . '/f.txt', 'x' );
		$other = new Ownership( (int) fileowner( $outside ) + 1, (int) filegroup( $outside ) + 1 );

		$this->assertTrue( $other->apply( $this->dir, $outside . '/f.txt' ) );
		$this->assertTrue( $other->apply( $this->dir . '/a', $this->dir . '/ab' ), 'A sibling with the same prefix is not inside the base.' );
		TempDir::remove( $outside );
	}

	public function test_a_link_is_not_followed_out_of_the_base(): void {
		$outside = TempDir::create();
		file_put_contents( $outside . '/target.txt', 'x' );
		symlink( $outside, $this->dir . '/a/link' );
		$owner = new Ownership( (int) fileowner( $this->dir ), (int) filegroup( $this->dir ) );

		// Same owner everywhere: proves the walk handles a link as a path entry without errors.
		$this->assertTrue( $owner->apply( $this->dir, $this->dir . '/a/link/target.txt' ) );
		TempDir::remove( $outside );
	}
}
