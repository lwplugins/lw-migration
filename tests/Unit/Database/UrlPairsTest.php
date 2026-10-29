<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\InsertCheck;
use LightweightPlugins\Migration\Database\Replacer;
use LightweightPlugins\Migration\Database\SiteTarget;
use LightweightPlugins\Migration\Database\SqlValue;
use LightweightPlugins\Migration\Database\UrlPairs;
use LightweightPlugins\Migration\Multisite\BlogUrlMove;
use LightweightPlugins\Migration\Package\PackageConfig;
use PHPUnit\Framework\TestCase;

final class UrlPairsTest extends TestCase {

	private function target(): SiteTarget {
		return new SiteTarget( 'https://new.test', 'https://new.test', 'https://new.test/wp-content/uploads/', 'wp-content/uploads', '/srv/new/', '/srv/new/wp-content' );
	}

	private function package( array $extra = [] ): PackageConfig {
		return PackageConfig::fromArray(
			$extra + [
				'SiteURL'   => 'http://www.old.test',
				'HomeURL'   => 'http://www.old.test',
				'WordPress' => [ 'UploadsURL' => 'http://www.old.test/wp-content/uploads/', 'Absolute' => '/var/old/', 'Content' => '/var/old/wp-content' ],
				'Replace'   => [ 'OldValues' => [ 'Old Co' ], 'NewValues' => [ 'New Co' ] ],
			]
		);
	}

	public function test_site_url_variants(): void {
		$p = UrlPairs::build( $this->package(), $this->target() )->pairs();

		$this->assertSame( 'https://new.test', $p['http://www.old.test'] );
		$this->assertSame( 'https://new.test', $p['https://www.old.test'] );
		$this->assertSame( '//new.test', $p['//www.old.test'] );
		$this->assertSame( 'https://new.test', $p['http://old.test'] );
		$this->assertSame( urlencode( 'https://new.test' ), $p[ urlencode( 'http://www.old.test' ) ] );
		$this->assertSame( rawurlencode( 'https://new.test' ), $p[ rawurlencode( 'http://old.test' ) ] );
		$this->assertSame( 'https:\/\/new.test', $p['http:\/\/www.old.test'] );
		$this->assertSame( "='new.test", $p["='www.old.test"] );
		$this->assertSame( '="new.test', $p['="old.test'] );
		$this->assertSame( '@new.test', $p['@www.old.test'] );
		$this->assertSame( '@new.test', $p['@old.test'] );
	}

	public function test_paths_uploads_and_custom_pairs(): void {
		$p = UrlPairs::build( $this->package(), $this->target() )->pairs();

		$this->assertSame( 'https://new.test/wp-content/uploads/', $p['http://www.old.test/wp-content/uploads/'] );
		$this->assertSame( '/srv/new/', $p['/var/old/'] );
		$this->assertSame( '/srv/new/wp-content', $p['/var/old/wp-content'] );
		$this->assertSame( 'New Co', $p['Old Co'] );
		$this->assertArrayNotHasKey( '/wp-content/uploads/', $p ); // identity pair skipped
	}

	public function test_no_email_replace_and_internal_urls(): void {
		$p = UrlPairs::build(
			$this->package( [ 'NoEmailReplace' => true, 'InternalSiteURL' => 'http://10.0.0.5:8080', 'InternalHomeURL' => 'not a url' ] ),
			$this->target()
		)->pairs();
		$this->assertArrayNotHasKey( '@old.test', $p );
		$this->assertSame( 'https://new.test', $p['http://10.0.0.5:8080'] );
		$this->assertArrayNotHasKey( 'not a url', $p );
	}

	public function test_raw_domain_path_pairs(): void {
		$raw = UrlPairs::build( $this->package(), $this->target() )->raw();
		$this->assertSame( "'new.test','/'", $raw["'www.old.test','/'"] );
	}

	/**
	 * The raw pairs run on the statement text, so both sides are SQL-escaped:
	 * a quote or backslash in the old or new address can neither miss the
	 * dumped literal nor end it early.
	 */
	public function test_raw_domain_path_pairs_are_sql_escaped(): void {
		$to  = new SiteTarget( "https://new.test/it's\\x", "https://new.test/it's\\x", 'https://new.test/u/', 'wp-content/uploads', '/s/', '/s/wp-content' );
		$pkg = PackageConfig::fromArray(
			[
				'SiteURL' => "http://old.test/o'ld",
				'HomeURL' => "http://old.test/o'ld",
			]
		);
		$u   = UrlPairs::build( $pkg, $to );
		$sql = 'INSERT INTO `lwt_blogs` VALUES (1,1,' . SqlValue::encode( 'old.test', 'varchar(200)' ) . ',' . SqlValue::encode( "/o'ld/", 'varchar(100)' ) . ",'2020-01-01');";

		$out = ( new Replacer( $u->pairs(), $u->raw() ) )->statement( $sql );

		$this->assertTrue( ( new InsertCheck( 'lwt_' ) )->isSafe( $out ), $out );
		$this->assertSame( "INSERT INTO `lwt_blogs` VALUES (1,1,'new.test','/it\\'s\\\\x/','2020-01-01');", $out );
	}

	public function test_same_site_produces_no_url_pairs(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'https://new.test', 'HomeURL' => 'https://new.test', 'WordPress' => [ 'UploadsURL' => 'https://new.test/wp-content/uploads/', 'Absolute' => '/srv/new/', 'Content' => '/srv/new/wp-content' ] ] );
		$p   = UrlPairs::build( $pkg, $this->target() )->pairs();
		$this->assertArrayNotHasKey( 'https://new.test', $p );
	}

	private function noEmptyKeys( UrlPairs $u ): void {
		$this->assertArrayNotHasKey( '', $u->pairs() );
		$this->assertArrayNotHasKey( '', $u->raw() );
	}

	public function test_home_wins_over_site_when_old_urls_are_equal(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$to  = new SiteTarget( 'https://new.test/wp', 'https://new.test', 'https://new.test/wp-content/uploads/', 'wp-content/uploads', '/srv/new/', '/srv/new/wp-content' );
		$this->assertSame( 'https://new.test', UrlPairs::build( $pkg, $to )->pairs()['http://old.test'] );
	}

	public function test_custom_pair_wins_over_generated(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test', 'Replace' => [ 'OldValues' => [ 'http://old.test' ], 'NewValues' => [ 'https://custom.test' ] ] ] );
		$this->assertSame( 'https://custom.test', UrlPairs::build( $pkg, $this->target() )->pairs()['http://old.test'] );
	}

	public function test_scheme_less_site_and_home_are_ignored(): void {
		$u = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'old.test', 'HomeURL' => 'old.test' ] ), $this->target() );
		$this->assertArrayNotHasKey( 'old.test', $u->pairs() );
		$this->noEmptyKeys( $u );
	}

	/**
	 * @dataProvider bad_rel_paths
	 */
	public function test_bad_uploads_rel_path_skips_default_pair( string $rel ): void {
		$to = new SiteTarget( 'https://new.test', 'https://new.test', 'https://new.test/u/', $rel, '/srv/new/', '/srv/new/wp-content' );
		$this->assertArrayNotHasKey( '/wp-content/uploads/', UrlPairs::build( $this->package(), $to )->pairs() );
	}

	public static function bad_rel_paths(): array {
		return [ 'empty' => [ '' ], 'slash' => [ '/' ], 'absolute' => [ '/abs/uploads' ], 'dotdot' => [ 'a/../b' ] ];
	}

	public function test_uploads_trailing_slash_follows_old(): void {
		$with = UrlPairs::build( $this->package( [ 'WordPress' => [ 'UploadsURL' => 'http://old.test/up/' ] ] ), $this->target() )->pairs();
		$this->assertSame( 'https://new.test/wp-content/uploads/', $with['http://old.test/up/'] );
		$without = UrlPairs::build( $this->package( [ 'WordPress' => [ 'UploadsURL' => 'http://old.test/up' ] ] ), $this->target() )->pairs();
		$this->assertSame( 'https://new.test/wp-content/uploads', $without['http://old.test/up'] );
	}

	public function test_subdirectory_site_both_directions(): void {
		$to = new SiteTarget( 'https://new.test/blog', 'https://new.test/blog', 'https://new.test/blog/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u  = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test/shop', 'HomeURL' => 'http://old.test/shop' ] ), $to );
		$this->assertSame( "'new.test','/blog/'", $u->raw()["'old.test','/shop/'"] );
		$this->assertSame( "='new.test/blog", $u->pairs()["='old.test/shop"] );
		$this->assertSame( '="new.test/blog', $u->pairs()['="old.test/shop'] );
		$this->assertSame( 'https://new.test/blog', $u->pairs()['http://old.test/shop'] );

		$flat = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test/shop', 'HomeURL' => 'http://old.test/shop' ] ), $this->target() );
		$this->assertSame( "'new.test','/'", $flat->raw()["'old.test','/shop/'"] );
		$back = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] ), $to );
		$this->assertSame( "'new.test','/blog/'", $back->raw()["'old.test','/'"] );
	}

	public function test_port_in_host(): void {
		$to = new SiteTarget( 'https://new.test:8443', 'https://new.test:8443', 'https://new.test:8443/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u  = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test:8080', 'HomeURL' => 'http://old.test:8080' ] ), $to );
		$this->assertSame( "='new.test:8443", $u->pairs()["='old.test:8080"] );
		$this->assertSame( "'new.test:8443','/'", $u->raw()["'old.test:8080','/'"] );
	}

	public function test_single_site_mail_pairs_never_carry_a_port(): void {
		$to = new SiteTarget( 'http://localhost:8781', 'http://localhost:8781', 'http://localhost:8781/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u  = UrlPairs::build( PackageConfig::fromArray( [ 'SiteURL' => 'https://old.example', 'HomeURL' => 'https://old.example', 'InternalSiteURL' => 'http://10.0.0.5:8080' ] ), $to );

		$this->assertSame( '@localhost', $u->pairs()['@old.example'] );
		$this->assertSame( '@localhost', $u->pairs()['@10.0.0.5'] );
		$this->assertArrayNotHasKey( '@10.0.0.5:8080', $u->pairs() );
		foreach ( $u->pairs() as $key => $value ) {
			if ( str_starts_with( (string) $key, '@' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/:\d+$/', (string) $key . ' ' . $value );
			}
		}
		$this->assertSame( 'alice@localhost', strtr( 'alice@old.example', $u->pairs() ) );
	}

	public function test_non_string_and_empty_values_never_produce_empty_key(): void {
		$u = UrlPairs::build(
			PackageConfig::fromArray( [ 'SiteURL' => 5, 'HomeURL' => [], 'InternalSiteURL' => '', 'WordPress' => [ 'UploadsURL' => null, 'Absolute' => 7, 'Content' => '' ], 'Replace' => [ 'OldValues' => [ '', 3, 'x' ], 'NewValues' => [ 'y', 'z', '' ] ] ] ),
			$this->target()
		);
		$this->noEmptyKeys( $u );
	}

	public function test_core_paths_stay_on_site_url_when_home_differs(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$to  = new SiteTarget( 'https://new.test', 'https://new.test/shop', 'https://new.test/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u   = UrlPairs::build( $pkg, $to );
		$r   = new Replacer( $u->pairs(), $u->raw() );
		$this->assertSame( 'https://new.test/wp-content/uploads/a.jpg', $r->value( 'http://old.test/wp-content/uploads/a.jpg' ) );
		$this->assertSame( 'https://new.test/wp-content/themes/x/a.css', $r->value( 'http://old.test/wp-content/themes/x/a.css' ) );
		$this->assertSame( 'https://new.test/wp-admin/admin-ajax.php', $r->value( 'http://old.test/wp-admin/admin-ajax.php' ) );
		$this->assertSame( 'https://new.test/wp-includes/js/a.js', $r->value( 'http://old.test/wp-includes/js/a.js' ) );
		$this->assertSame( 'https://new.test/shop/about', $r->value( 'http://old.test/about' ) );
	}

	public function test_no_core_path_pairs_when_site_equals_home_everywhere(): void {
		$pairs = UrlPairs::build( $this->package(), $this->target() )->pairs();
		foreach ( array_keys( $pairs ) as $key ) {
			$this->assertStringNotContainsString( 'wp-admin', (string) $key );
			$this->assertStringNotContainsString( 'wp-includes', (string) $key );
		}
		$this->assertArrayNotHasKey( 'http://www.old.test/wp-content', $pairs );
	}

	public function test_blog_moves_come_after_user_pairs_and_before_the_main_pairs(): void {
		$pkg   = PackageConfig::fromArray(
			[
				'SiteURL'   => 'http://old.test',
				'HomeURL'   => 'http://old.test',
				'WordPress' => [ 'UploadsURL' => 'http://old.test/wp-content/uploads/' ],
				'Replace'   => [ 'OldValues' => [ 'Old Co' ], 'NewValues' => [ 'New Co' ] ],
			]
		);
		$u     = UrlPairs::forNetwork( $pkg, $this->target(), [ self::move( 2, 5, 'http://old.test/b2', 'https://new.test/b2' ) ] );
		$pairs = $u->pairs();
		$keys  = array_keys( $pairs );

		$this->assertSame( 'Old Co', $keys[0], 'The user pairs stay first.' );
		$this->assertSame( 'https://new.test/b2', $pairs['http://old.test/b2'] );
		$this->assertSame( 'https://new.test', $pairs['http://old.test'] );
		$this->assertLessThan( array_search( 'http://old.test', $keys, true ), array_search( 'http://old.test/b2', $keys, true ), 'A blog move comes before the main-site pairs.' );
		$this->assertSame( "'new.test','/b2/'", $u->raw()["'old.test','/b2/'"] );
		$this->assertSame( "'new.test','/'", $u->raw()["'old.test','/'"] );
		$this->assertSame( '/wp-content/uploads/sites/5/', $pairs['/wp-content/uploads/sites/2/'] );
		$this->assertSame( '/wp-content/uploads/sites/5/', $pairs['/wp-content/blogs.dir/2/files/'] );
		$this->assertSame( 'https://new.test/wp-content/uploads/sites/5/', $pairs['http://old.test/b2/files/'] );
		$this->assertSame( 'https://new.test/wp-content/uploads/sites/5/', $pairs['http://old.test/wp-content/uploads/sites/2/'] );
		$r = new Replacer( $pairs, $u->raw() );
		$this->assertSame( 'https://new.test/b2/hello/ https://new.test/about', $r->value( 'http://old.test/b2/hello/ http://old.test/about' ) );
		$this->assertSame( 'https://new.test/wp-content/uploads/sites/5/2026/a.jpg', $r->value( 'http://old.test/wp-content/uploads/sites/2/2026/a.jpg' ) );
	}

	public function test_an_old_main_blog_takes_the_uploads_root_pair(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$to  = new SiteTarget( 'https://new.test', 'https://new.test', 'https://new.test/content/uploads/', 'content/uploads', '/srv/new/', '/srv/new/wp-content' );
		$u   = UrlPairs::forNetwork( $pkg, $to, [ self::move( 1, 7, 'http://old.test', 'https://new.test/main', 'content/uploads' ) ] );

		$this->assertSame( '/content/uploads/sites/7/', $u->pairs()['/wp-content/uploads/'], 'The later main-site pair for the same key is not added.' );
		$this->assertSame( 'https://new.test/main', $u->pairs()['http://old.test'] );
		$this->assertArrayNotHasKey( 'http://old.test/files/', $u->pairs(), 'The main site never had ms-files addresses.' );
	}

	public function test_a_subdomain_move_keeps_the_main_host_mail_addresses_on_the_main_site(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$u   = UrlPairs::forNetwork( $pkg, $this->target(), [ self::move( 2, 5, 'http://old.test/b2', 'https://b2.new.test' ), self::move( 3, 6, 'http://shop.old.test', 'https://shop.new.test' ) ] );

		$this->assertSame( '@new.test', $u->pairs()['@old.test'] );
		$this->assertSame( '@shop.new.test', $u->pairs()['@shop.old.test'] );
		$this->assertSame( "'b2.new.test','/'", $u->raw()["'old.test','/b2/'"] );
	}

	public function test_a_blogs_dir_network_keeps_the_legacy_files_paths(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test', 'HomeURL' => 'http://old.test' ] );
		$u   = UrlPairs::forNetwork( $pkg, $this->target(), [ self::move( 2, 5, 'http://old.test/b2', 'https://new.test/b2', 'wp-content/uploads', true ) ] );

		$this->assertSame( '/wp-content/blogs.dir/5/files/', $u->pairs()['/wp-content/blogs.dir/2/files/'] );
		$this->assertSame( '/wp-content/blogs.dir/5/files/', $u->pairs()['/wp-content/uploads/sites/2/'] );
		$this->assertArrayNotHasKey( 'http://old.test/b2/files/', $u->pairs() );
	}

	public function test_single_site_builds_are_unchanged(): void {
		$to = new SiteTarget( 'https://new.test/wp', 'https://new.test', 'https://new.test/content/uploads/', 'content/uploads', '/srv/new/', '/srv/new/wp-content' );
		$pk = PackageConfig::fromArray(
			[
				'SiteURL'         => 'http://www.old.test/wp',
				'HomeURL'         => 'http://www.old.test',
				'InternalSiteURL' => 'http://10.0.0.5:8080/wp',
				'InternalHomeURL' => 'http://10.0.0.5:8080',
				'WordPress'       => [ 'UploadsURL' => 'http://www.old.test/wp-content/uploads/', 'Absolute' => '/var/old/', 'Content' => '/var/old/wp-content' ],
				'Replace'         => [ 'OldValues' => [ 'Old Co', 'x' ], 'NewValues' => [ 'New Co', '' ] ],
			]
		);
		$u  = UrlPairs::build( $pk, $to );

		// Taken from build() before the network split (232 pairs, 8 raw pairs, in this order);
		// since then only the mail keys of the internal address lost their port ("@10.0.0.5").
		$this->assertSame( '930f4b72253a647784d08869b49229508346f49e', sha1( (string) json_encode( [ $u->pairs(), $u->raw() ] ) ) );
		$network = UrlPairs::forNetwork( $pk, $to, [] );
		$this->assertSame( $u->pairs(), $network->pairs(), 'Without moves the network pairs are the single-site ones.' );
		$this->assertSame( $u->raw(), $network->raw() );
	}

	private static function move( ?int $old, int $new, string $oldUrl, string $newUrl, string $rel = 'wp-content/uploads', bool $blogsDir = false ): BlogUrlMove {
		$sites = null === $old || $old <= 1 ? '' : 'sites/' . $old . '/';
		$host  = (string) preg_replace( '#^(https?://[^/]+).*#', '$1', $newUrl );

		return new BlogUrlMove(
			oldBlogId: $old,
			newBlogId: $new,
			oldSiteUrl: $oldUrl,
			oldInternalSiteUrl: '',
			oldHomeUrl: $oldUrl,
			oldInternalHomeUrl: '',
			oldUploadsUrl: 'http://old.test/wp-content/uploads/' . $sites,
			newSiteUrl: $newUrl,
			newHomeUrl: $newUrl,
			newUploadsUrl: $host . '/' . $rel . '/sites/' . $new . '/',
			uploadsRel: $rel,
			uploadBlogsDir: $blogsDir
		);
	}

	public function test_network_mail_pairs_never_carry_a_port(): void {
		$pkg = PackageConfig::fromArray( [ 'SiteURL' => 'http://old.test:8080', 'HomeURL' => 'http://old.test:8080' ] );
		$to  = new SiteTarget( 'https://new.test:8093', 'https://new.test:8093', 'https://new.test:8093/wp-content/uploads/', 'wp-content/uploads', '/srv/', '/srv/wp-content' );
		$u   = UrlPairs::forNetwork( $pkg, $to, [ self::move( 3, 6, 'http://shop.old.test:8080', 'https://shop.new.test:8093' ) ] );

		$this->assertSame( '@new.test', $u->pairs()['@old.test'] );
		$this->assertSame( '@shop.new.test', $u->pairs()['@shop.old.test'] );
		foreach ( array_keys( $u->pairs() ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/^@.*:\d+$/', (string) $key );
		}
	}
}
