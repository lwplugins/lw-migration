<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use LightweightPlugins\Migration\Multisite\SiteAddress;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Multisite\UrlProposal;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * SiteAddress is pure, but its refusals are translated, so it lives in the WordPress suite.
 */
final class SiteAddressTest extends MonkeyTestCase {

	public function test_a_subdirectory_address(): void {
		$this->assertSame(
			[
				'domain' => 'new.test',
				'path'   => '/shop/',
				'url'    => 'https://new.test/shop',
			],
			SiteAddress::check( 'https://new.test/shop', self::dir() )
		);
		$this->assertSame( '/shop/', SiteAddress::check( 'https://New.Test/Shop/', self::dir() )['path'], 'Host and name are lower-cased first.' );
	}

	public function test_a_subdomain_address_and_a_custom_domain(): void {
		$this->assertSame(
			[
				'domain' => 'shop.new.test',
				'path'   => '/',
				'url'    => 'https://shop.new.test',
			],
			SiteAddress::check( 'https://shop.new.test/', self::sub() )
		);
		$this->assertSame( 'shop.example', SiteAddress::check( 'https://shop.example', self::sub() )['domain'], 'Custom domains are allowed on a subdomain install.' );
	}

	public function test_a_network_on_a_port_keeps_it_in_the_domain(): void {
		$to = TargetNetwork::fromArray( [ 'domain' => '127.0.0.1', 'port' => 8093, 'path' => '/', 'scheme' => 'http', 'reserved' => [ 'page' ] ] );
		$this->assertSame(
			[
				'domain' => '127.0.0.1:8093',
				'path'   => '/shop/',
				'url'    => 'http://127.0.0.1:8093/shop',
			],
			SiteAddress::check( 'http://127.0.0.1:8093/shop', $to )
		);
		$this->assertRefused( 'http://127.0.0.1/shop', $to, 'port' );
		$this->assertRefused( 'http://127.0.0.1:8094/shop', $to, 'port' );
	}

	public function test_a_network_below_a_path(): void {
		$to = TargetNetwork::fromArray( [ 'domain' => 'new.test', 'path' => '/wp/', 'scheme' => 'https' ] );
		$this->assertSame( '/wp/shop/', SiteAddress::check( 'https://new.test/wp/shop', $to )['path'] );
		$this->assertRefused( 'https://new.test/shop', $to, 'https://new.test/wp/<name>' );
		$this->assertRefused( 'https://new.test/wp', $to, 'main site' );
	}

	/** @return array<string, array{0: string, 1: bool, 2: string}> */
	public static function refusals(): array {
		return [
			'other scheme'                   => [ 'http://new.test/shop', false, 'Use the network\'s scheme (https).' ],
			'subdirectory with another host' => [ 'https://other.test/shop', false, 'On this network a site\'s address is https://new.test/<name>.' ],
			'two segments'                   => [ 'https://new.test/a/b', false, 'letters, digits and hyphens' ],
			'underscore in the name'         => [ 'https://new.test/my_shop', false, 'letters, digits and hyphens' ],
			'a reserved name'                => [ 'https://new.test/page', false, '"page"' ],
			'wp-admin'                       => [ 'https://new.test/wp-admin', false, '"wp-admin"' ],
			'wp-json'                        => [ 'https://new.test/WP-JSON', false, '"wp-json"' ],
			'the main address'               => [ 'https://new.test/', false, 'The main site cannot be replaced by a subsite import: choose another address.' ],
			'the main address, subdomain'    => [ 'https://new.test', true, 'The main site cannot be replaced' ],
			'www of the main, subdomain'     => [ 'https://www.new.test', true, 'The main site cannot be replaced' ],
			'a path on a subdomain install'  => [ 'https://shop.new.test/shop', true, 'no path' ],
			'a bad host label'               => [ 'https://sh_op.new.test', true, 'not a valid host name' ],
			'a single-label host'            => [ 'https://shop', true, 'not a valid host name' ],
			'a port the network lacks'       => [ 'https://new.test:8080/shop', false, 'port' ],
			'javascript'                     => [ 'javascript:alert(1)', false, 'full http or https address' ],
			'no host'                        => [ 'https:///shop', false, 'full http or https address' ],
			'spaces'                         => [ 'https://new.test/my shop', false, 'full http or https address' ],
			'a line break'                   => [ "https://new.test/shop\n", false, 'full http or https address' ],
			'a query'                        => [ 'https://new.test/shop?x=1', false, 'full http or https address' ],
			'a fragment'                     => [ 'https://new.test/shop#x', false, 'full http or https address' ],
			'user and password'              => [ 'https://u:p@new.test/shop', false, 'full http or https address' ],
			'empty'                          => [ '', false, 'full http or https address' ],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_refused( string $url, bool $subdomain, string $reason ): void {
		$this->assertRefused( $url, $subdomain ? self::sub() : self::dir(), $reason );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
	public static function refusedProposals(): array {
		return [
			'a single site named like a reserved folder' => [ [ 'HomeURL' => 'https://blog.example' ], 'https://new.test/blog', '"blog"' ],
			'an underscore in the old host'             => [ [ 'HomeURL' => 'https://my_shop.example' ], 'https://new.test/my_shop', 'letters, digits and hyphens' ],
			'a multi-label subdomain'                   => [ [ 'BlogID' => 2, 'Domain' => 'a.b.old.test', 'Path' => '/' ], 'https://new.test/a.b', 'letters, digits and hyphens' ],
		];
	}

	/**
	 * The proposal follows the reference even where this network refuses it; the user changes it (review I3).
	 *
	 * @dataProvider refusedProposals
	 * @param array<string, mixed> $old
	 */
	public function test_some_proposals_are_refused_and_must_be_changed( array $old, string $proposal, string $reason ): void {
		$to = TargetNetwork::fromArray(
			[
				'subdomain' => false,
				'domain'    => 'new.test',
				'path'      => '/',
				'scheme'    => 'https',
				'reserved'  => [ 'blog' ],
			]
		);
		$this->assertSame( $proposal, UrlProposal::for( SiteEntry::fromArray( $old ), 'http://old.test', $to ) );
		$this->assertRefused( $proposal, $to, $reason );
	}

	private function assertRefused( string $url, TargetNetwork $to, string $reason ): void {
		try {
			SiteAddress::check( $url, $to );
			$this->fail( 'The address was accepted: ' . $url );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( $reason, $e->getMessage() );
		}
	}

	private static function dir(): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain' => false,
				'domain'    => 'new.test',
				'path'      => '/',
				'scheme'    => 'https',
				'reserved'  => [ 'page' ],
			]
		);
	}

	private static function sub(): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain' => true,
				'domain'    => 'new.test',
				'path'      => '/',
				'scheme'    => 'https',
			]
		);
	}
}
