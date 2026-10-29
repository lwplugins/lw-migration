<?php
/**
 * Tests for UploadRoutes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\UploadRoutes;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use PHPUnit\Framework\TestCase;

final class UploadRoutesTest extends TestCase {

	public function test_a_subsite_archive_for_a_single_site_routes_as_before(): void {
		$routes = UploadRoutes::forSubsite( 2 );
		$this->assertSame( [ 'rel' => '2026/a.jpg', 'blog' => '' ], $routes->route( 'uploads/sites/2/2026/a.jpg' ) );
		$this->assertSame( [ 'rel' => '2026/a.jpg', 'blog' => '' ], $routes->route( 'blogs.dir/2/files/2026/a.jpg' ) );
		$this->assertSame( [ 'rel' => '2026/a.jpg', 'blog' => '' ], $routes->route( 'uploads/2026/a.jpg' ) );
		$this->assertSame( [ 'rel' => 'sites/3/a.jpg', 'blog' => '' ], $routes->route( 'uploads/sites/3/a.jpg' ), 'Another blog\'s uploads keep their path, as before.' );
		$this->assertNull( $routes->route( 'blogs.dir/3/files/a.jpg' ) );
		$this->assertNull( $routes->route( 'plugins/x/x.php' ) );
	}

	public function test_a_single_site_archive_keeps_every_uploads_path(): void {
		$routes = UploadRoutes::forSubsite( null );
		$this->assertSame( [ 'rel' => 'sites/2/a.jpg', 'blog' => '' ], $routes->route( 'uploads/sites/2/a.jpg' ) );
		$this->assertNull( $routes->route( 'blogs.dir/2/files/a.jpg' ) );
		$this->assertSame( [ [ 'uploads/', '' ] ], $routes->prefixes() );
	}

	public function test_a_sites_archive_moves_each_blog_to_its_new_id(): void {
		$map    = self::map( [ [ 1, 7 ], [ 3, 8 ] ] ); // Old => new.
		$routes = UploadRoutes::forNetwork( ArchiveKind::SITES, $map, false );
		$this->assertSame( [ 'rel' => 'sites/8/2026/b3.txt', 'blog' => 'sites/8/' ], $routes->route( 'uploads/sites/3/2026/b3.txt' ) );
		$this->assertSame( [ 'rel' => 'sites/8/2026/b3.txt', 'blog' => 'sites/8/' ], $routes->route( 'blogs.dir/3/files/2026/b3.txt' ) );
		$this->assertSame( [ 'rel' => 'sites/7/2026/main.txt', 'blog' => 'sites/7/' ], $routes->route( 'uploads/2026/main.txt' ), 'The old main site lands in its new subsite.' );
		$this->assertFalse( $routes->route( 'uploads/sites/4/x.txt' ), 'A blog the archive maps nowhere is skipped.' );
		$this->assertFalse( $routes->route( 'blogs.dir/4/files/x.txt' ) );
		$this->assertFalse( $routes->route( 'uploads/sites/30/x.txt' ), 'Blog 3 does not own blog 30.' );
		$this->assertNull( $routes->route( 'themes/t/style.css' ) );
	}

	public function test_a_sites_archive_without_its_main_site_skips_the_main_uploads(): void {
		$routes = UploadRoutes::forNetwork( ArchiveKind::SITES, self::map( [ [ 2, 9 ] ] ), false );
		$this->assertSame( [ 'rel' => 'sites/9/a.txt', 'blog' => 'sites/9/' ], $routes->route( 'uploads/sites/2/a.txt' ) );
		$this->assertFalse( $routes->route( 'uploads/2026/a.txt' ), 'Nothing of the network\'s main uploads is written.' );
	}

	public function test_a_single_site_archive_lands_in_its_new_subsite(): void {
		$routes = UploadRoutes::forNetwork( ArchiveKind::SINGLE, self::map( [ [ null, 5 ] ] ), false );
		$this->assertSame( [ 'rel' => 'sites/5/2026/a.jpg', 'blog' => 'sites/5/' ], $routes->route( 'uploads/2026/a.jpg' ) );
		$this->assertSame( [ 'rel' => 'sites/5/sites/2/a.jpg', 'blog' => 'sites/5/' ], $routes->route( 'uploads/sites/2/a.jpg' ), 'A single site\'s own folder named sites stays inside it.' );
		$this->assertFalse( $routes->route( 'blogs.dir/2/files/a.jpg' ), 'The network\'s legacy files folder is never written.' );
	}

	public function test_a_network_archive_keeps_ids_and_moves_legacy_files(): void {
		$routes = UploadRoutes::forNetwork( ArchiveKind::NETWORK, self::map( [ [ 1, 1 ], [ 2, 2 ] ] ), false );
		$this->assertSame( [ 'rel' => 'sites/2/x.txt', 'blog' => 'sites/2/' ], $routes->route( 'uploads/sites/2/x.txt' ) );
		$this->assertSame( [ 'rel' => 'sites/2/x.txt', 'blog' => 'sites/2/' ], $routes->route( 'blogs.dir/2/files/x.txt' ) );
		$this->assertSame( [ 'rel' => '2026/m.txt', 'blog' => '' ], $routes->route( 'uploads/2026/m.txt' ) );
		$this->assertSame( [ 'rel' => 'sites/5/x.txt', 'blog' => '' ], $routes->route( 'uploads/sites/5/x.txt' ), 'A network archive is not strict.' );
	}

	public function test_a_network_with_legacy_files_keeps_blogs_dir_under_wp_content(): void {
		$routes = UploadRoutes::forNetwork( ArchiveKind::NETWORK, self::map( [ [ 1, 1 ], [ 2, 2 ] ] ), true );
		$this->assertNull( $routes->route( 'blogs.dir/2/files/x.txt' ), 'It falls through to the wp-content route.' );
		$this->assertSame( [ 'rel' => 'sites/2/x.txt', 'blog' => 'sites/2/' ], $routes->route( 'uploads/sites/2/x.txt' ) );
	}

	public function test_an_unresolved_map_is_refused(): void {
		$map = BlogMap::fromArray( [ [ 'old' => [ 'BlogID' => 2 ] ] ] );

		$this->expectException( \LogicException::class );
		UploadRoutes::forNetwork( ArchiveKind::SITES, $map, false );
	}

	public function test_prefixes_name_every_routed_folder_with_the_main_uploads_last(): void {
		$routes = UploadRoutes::forNetwork( ArchiveKind::SITES, self::map( [ [ 1, 7 ], [ 3, 8 ] ] ), false );
		$this->assertSame(
			[
				[ 'blogs.dir/3/files/', 'sites/8/' ],
				[ 'blogs.dir/3/', 'sites/8/' ],
				[ 'uploads/sites/3/', 'sites/8/' ],
				[ 'uploads/', 'sites/7/' ],
			],
			$routes->prefixes()
		);
	}

	/**
	 * @param array<int, array{0: int|null, 1: int}> $pairs Old blog id => new id.
	 */
	private static function map( array $pairs ): BlogMap {
		$maps = [];
		foreach ( $pairs as [ $old, $new ] ) {
			$maps[] = [
				'old'   => null === $old ? [] : [ 'BlogID' => $old ],
				'newId' => $new,
			];
		}

		return BlogMap::fromArray( $maps );
	}
}
