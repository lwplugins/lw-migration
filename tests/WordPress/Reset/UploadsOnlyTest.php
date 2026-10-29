<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Reset\CoreFolders;
use LightweightPlugins\Migration\Reset\UploadsOnly;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class UploadsOnlyTest extends MonkeyTestCase {

	private string $root;

	private string $uploads;

	protected function setUp(): void {
		parent::setUp();
		$this->root    = TempDir::create();
		$this->uploads = $this->root . '/wp-content/uploads';
		foreach ( [ 'uploads/2026/10/a.jpg', 'uploads/backups-x/site.wpress', 'themes/t/functions.php' ] as $file ) {
			@mkdir( dirname( $this->root . '/wp-content/' . $file ), 0755, true );
			file_put_contents( $this->root . '/wp-content/' . $file, 'x' );
		}
		symlink( $this->root . '/wp-content/themes/t', $this->uploads . '/linked' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		parent::tearDown();
	}

	public function test_only_files_whose_real_folder_is_in_uploads_and_not_kept_may_go(): void {
		$guard = new UploadsOnly( $this->uploads, FolderIdentity::ids( [ $this->uploads . '/backups-x' ] ) );
		$this->assertSame( $this->uploads . '/2026/10/a.jpg', $guard->filter( $this->uploads . '/2026/10/a.jpg' ) );
		$this->assertSame( '', $guard->filter( $this->root . '/wp-content/themes/t/functions.php' ), 'An absolute path out of uploads.' );
		$this->assertSame( '', $guard->filter( $this->uploads . '/2026/../../themes/t/functions.php' ), 'Dots out of uploads.' );
		$this->assertSame( '', $guard->filter( $this->uploads . '/linked/functions.php' ), 'A linked folder whose real place is a theme.' );
		$this->assertSame( '', $guard->filter( $this->uploads . '/backups-x/site.wpress' ), 'Our backups.' );
		$this->assertSame( '', $guard->filter( 'relative/a.jpg' ) );
		$this->assertSame( '', ( new UploadsOnly( $this->root . '/missing', [] ) )->filter( $this->uploads . '/2026/10/a.jpg' ), 'No uploads folder, nothing goes.' );
	}

	public function test_core_deletes_the_attachment_with_the_guard_on_and_takes_it_off_after(): void {
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploads ] );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/\\' ) );
		$filters = [];
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority ) use ( &$filters ): bool {
				$filters[ $hook ] = [ $callback, $priority ];
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			static function ( string $hook, $callback, int $priority ) use ( &$filters ): bool {
				$found = isset( $filters[ $hook ] ) && $filters[ $hook ] === [ $callback, $priority ];
				unset( $filters[ $hook ] );
				return $found;
			}
		);
		$asked = [];
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( int $id, bool $force ) use ( &$filters, &$asked ) {
				[ $callback, $priority ] = $filters['wp_delete_file'];
				$this->assertSame( PHP_INT_MAX, $priority, 'Last, after every other filter.' );
				foreach ( [ $this->uploads . '/2026/10/a.jpg', $this->root . '/wp-content/themes/t/functions.php' ] as $file ) {
					$asked[] = $callback( $file );
				}
				throw new \RuntimeException( 'core failed' );
			}
		);
		try {
			UploadsOnly::delete( 7, [] );
			$this->fail( 'The failure passes on.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'core failed', $e->getMessage() );
		}
		$this->assertSame( [ $this->uploads . '/2026/10/a.jpg', '' ], $asked );
		$this->assertSame( [], $filters, 'The guard is off again.' );
	}

	public function test_the_site_folders_above_uploads_never_block_a_genuine_upload(): void {
		$kept  = FolderIdentity::ids( [ $this->uploads . '/backups-x' ] ) + CoreFolders::ids( SiteFixture::make( $this->root ) );
		$guard = new UploadsOnly( $this->uploads, $kept );
		$this->assertSame( $this->uploads . '/2026/10/a.jpg', $guard->filter( $this->uploads . '/2026/10/a.jpg' ), 'wp-content and ABSPATH lie above uploads: not a reason.' );
		$this->assertSame( '', $guard->filter( $this->root . '/wp-content/themes/t/functions.php' ) );
		$this->assertSame( '', $guard->filter( $this->uploads . '/backups-x/site.wpress' ) );
	}

	public function test_a_folder_that_does_not_exist_lets_nothing_go(): void {
		$guard = new UploadsOnly( $this->uploads, [] );
		$this->assertSame( '', $guard->filter( $this->uploads . '/gone/x.jpg' ), 'No real folder: fail closed.' );
	}
}
