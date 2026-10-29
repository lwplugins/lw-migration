<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Backups;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * A deleted backup takes its transfer links with it (issue 8): every delete
 * path goes through Repository::deleteIn().
 */
final class RepositoryRevokesSharesTest extends MonkeyTestCase {

	private const NAME = 'site-20261009-120000-001.wpress';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		file_put_contents( $this->dir . '/own/' . self::NAME, 'backup' );
		file_put_contents( $this->dir . '/ext/' . self::NAME, 'backup' );
		FakeWpdb::install();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_delete_and_delete_in_revoke_the_links_of_that_folder_only(): void {
		$shares = new Shares();
		$own    = $shares->create( Backup::OWN, self::NAME, 1, time(), 600, Fingerprint::of( $this->dir . '/own/' . self::NAME ) );
		$ext    = $shares->create( Backup::EXTERNAL, self::NAME, 1, time(), 600, Fingerprint::of( $this->dir . '/ext/' . self::NAME ) );
		$repo   = new Repository( $this->dir . '/own', $this->dir . '/ext', '', $shares );

		$this->assertTrue( $repo->deleteIn( self::NAME, Backup::OWN ) );
		$this->assertSame( [ $ext['id'] ], array_column( $shares->all(), 'id' ) );
		$this->assertTrue( $repo->delete( self::NAME ) );
		$this->assertSame( [], $shares->all() );
		$this->assertNotNull( $own['id'] );
	}

	public function test_a_failed_delete_keeps_the_links(): void {
		$shares = new Shares();
		$shares->create( Backup::OWN, self::NAME, 1, time(), 600, 'x' );
		$repo = new Repository( $this->dir . '/own', $this->dir . '/ext', '', $shares );

		$this->assertFalse( $repo->deleteIn( 'gone-20261009-120000-001.wpress', Backup::OWN ) );
		$this->assertCount( 1, $shares->all() );
	}

	public function test_the_wordpress_repository_revokes_too(): void {
		$root = 'lw-migration-abcdefabcdef';
		FakeWpdb::install( [ Options::STORAGE_DIR => $root ] );
		$made = ! is_dir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR . '/' . $root . '/backups', 0755, true );
		file_put_contents( WP_CONTENT_DIR . '/' . $root . '/backups/' . self::NAME, 'backup' );
		try {
			$shares = new Shares();
			$shares->create( Backup::OWN, self::NAME, 1, time(), 600, 'x' );

			$this->assertTrue( Repository::fromWordPress( StorageDir::fromWordPress() )->delete( self::NAME ) );
			$this->assertSame( [], $shares->all() );
		} finally {
			TempDir::remove( WP_CONTENT_DIR . '/' . $root );
			if ( $made ) {
				TempDir::remove( WP_CONTENT_DIR );
			}
		}
	}

	public function test_a_fingerprint_follows_the_file_not_the_name(): void {
		$path = $this->dir . '/own/' . self::NAME;
		$was  = Fingerprint::of( $path );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', $was );
		$this->assertSame( $was, Fingerprint::of( $path ) );

		$mtime = (int) filemtime( $path );
		unlink( $path );
		file_put_contents( $path, 'backup' ); // Same name, size and mtime; another file.
		touch( $path, $mtime );
		$this->assertNotSame( $was, Fingerprint::of( $path ) );
		$this->assertSame( '', Fingerprint::of( $this->dir . '/own/missing.wpress' ) );
	}
}
