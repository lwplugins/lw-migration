<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Multisite\TargetNetworkReader;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class TargetNetworkReaderTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = (object) [ 'base_prefix' => 'wp_' ];
		Functions\when( 'is_subdomain_install' )->justReturn( false );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'get_networks' )->justReturn( 1 );
		Functions\when( 'get_site_option' )->justReturn( '1' );
		Functions\when( 'get_subdirectory_reserved_names' )->justReturn( [ 'page', 'Blog', 'wp-admin', 5 ] );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/srv/wp-content/uploads/',
				'baseurl' => 'http://127.0.0.1:8093/wp-content/uploads/',
			]
		);
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/\\' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_reads_the_main_site_with_its_port_apart(): void {
		Functions\when( 'get_site' )->justReturn(
			(object) [
				'domain' => '127.0.0.1:8093',
				'path'   => '/',
			]
		);
		Functions\when( 'get_home_url' )->justReturn( 'http://127.0.0.1:8093' );

		$to = TargetNetworkReader::read();

		$this->assertFalse( $to->subdomain );
		$this->assertSame( [ '127.0.0.1', 8093, '/', 'http' ], [ $to->domain, $to->port, $to->path, $to->scheme ] );
		$this->assertSame( 'http://127.0.0.1:8093', $to->mainUrl() );
		$this->assertSame( [ 1, 1, 1, 'wp_' ], [ $to->networkId, $to->networks, $to->mainSiteId, $to->basePrefix ] );
		$this->assertSame( [ '/srv/wp-content/uploads', 'http://127.0.0.1:8093/wp-content/uploads' ], [ $to->uploadsDir, $to->uploadsUrl ] );
		$this->assertFalse( $to->uploadBlogsDir, 'Without UPLOADBLOGSDIR the files rewrite is off.' );
		$this->assertSame( [ 'page', 'blog', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'files' ], $to->reserved );
	}

	public function test_a_subdomain_network_on_https_with_more_networks_and_another_main_site(): void {
		Functions\when( 'is_subdomain_install' )->justReturn( true );
		Functions\when( 'get_networks' )->justReturn( 2 );
		Functions\when( 'get_main_site_id' )->justReturn( 3 );
		Functions\when( 'get_site' )->justReturn(
			(object) [
				'domain' => 'New.Test',
				'path'   => '/',
			]
		);
		Functions\when( 'get_home_url' )->justReturn( 'https://new.test' );

		$to = TargetNetworkReader::read();

		$this->assertTrue( $to->subdomain );
		$this->assertSame( [ 'new.test', null, 'https' ], [ $to->domain, $to->port, $to->scheme ] );
		$this->assertSame( [ 2, 3 ], [ $to->networks, $to->mainSiteId ] );
	}

	public function test_the_port_comes_only_from_the_main_site_row(): void {
		Functions\when( 'get_site' )->justReturn(
			(object) [
				'domain' => 'new.test',
				'path'   => '/',
			]
		);
		Functions\when( 'get_home_url' )->justReturn( 'http://new.test:8080' );

		$to = TargetNetworkReader::read();

		$this->assertNull( $to->port, 'wp_blogs.domain has no port, so the sites are stored without one.' );
		$this->assertSame( 'http://new.test', $to->mainUrl() );
	}

	public function test_the_sites_a_network_archive_does_not_contain(): void {
		Functions\when( 'get_sites' )->justReturn( [ '1', '2', '3', '4', '6' ] );
		Functions\when( 'get_home_url' )->alias( static fn( int $id ): string => 'https://new.test/b' . $id );

		$this->assertSame(
			[
				[ 'id' => 4, 'url' => 'https://new.test/b4' ],
				[ 'id' => 6, 'url' => 'https://new.test/b6' ],
			],
			TargetNetworkReader::unlisted( [ 1, 2, 3, null ] )
		);
	}

	public function test_the_site_at_an_address(): void {
		Functions\when( 'domain_exists' )->alias( static fn( string $domain, string $path, int $network ): ?string => [ 'new.test', '/shop/', 2 ] === [ $domain, $path, $network ] ? '7' : null );

		$this->assertSame( 7, TargetNetworkReader::siteAt( 'new.test', '/shop/', 2 ) );
		$this->assertNull( TargetNetworkReader::siteAt( 'new.test', '/none/', 2 ) );
	}
}
