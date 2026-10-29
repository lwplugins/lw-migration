<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\TargetNetwork;
use PHPUnit\Framework\TestCase;

final class TargetNetworkTest extends TestCase {

	public function test_the_main_address_has_no_trailing_slash_and_keeps_the_port(): void {
		$this->assertSame( 'https://new.test', TargetNetwork::fromArray( [ 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https' ] )->mainUrl() );
		$this->assertSame( 'http://127.0.0.1:8093/wp', TargetNetwork::fromArray( [ 'domain' => '127.0.0.1', 'port' => 8093, 'path' => '/wp/' ] )->mainUrl() );
	}

	public function test_a_blog_domain_carries_the_network_port(): void {
		$this->assertSame( 'shop.new.test', TargetNetwork::fromArray( [ 'domain' => 'new.test' ] )->blogDomain( 'shop.new.test' ) );
		$this->assertSame( '127.0.0.1:8093', TargetNetwork::fromArray( [ 'domain' => '127.0.0.1', 'port' => 8093 ] )->blogDomain( '127.0.0.1' ) );
	}

	public function test_round_trip(): void {
		$to = new TargetNetwork( true, 'new.test', '/', 'https', 8443, 1, 1, true, '/x/wp-content/uploads', 'https://new.test:8443/wp-content/uploads', 'wp_', 1, [ 'page', 'wp-admin' ] );
		$this->assertEquals( $to, TargetNetwork::fromArray( $to->toArray() ) );
	}

	public function test_the_uploads_folder_relative_to_the_main_address(): void {
		$this->assertSame( 'wp-content/uploads', TargetNetwork::fromArray( [ 'path' => '/', 'uploadsUrl' => 'https://new.test/wp-content/uploads' ] )->uploadsRel() );
		$this->assertSame( 'wp-content/uploads', TargetNetwork::fromArray( [ 'path' => '/wp/', 'uploadsUrl' => 'https://new.test/wp/wp-content/uploads' ] )->uploadsRel() );
		$this->assertSame( 'files/media', TargetNetwork::fromArray( [ 'path' => '/', 'uploadsUrl' => 'https://cdn.test/files/media' ] )->uploadsRel() );
	}
}
