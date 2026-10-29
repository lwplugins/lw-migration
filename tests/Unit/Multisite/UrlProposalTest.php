<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Multisite\UrlProposal;
use PHPUnit\Framework\TestCase;

final class UrlProposalTest extends TestCase {

	/** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool, 3: string}> */
	public static function cases(): array {
		$sub = [ 'BlogID' => 2, 'Domain' => 'b2.old.test', 'Path' => '/' ];
		$dir = [ 'BlogID' => 2, 'Domain' => 'old.test', 'Path' => '/b2/' ];
		return [
			'subdomain source → subdomain target'    => [ $sub, 'http://old.test', true, 'https://b2.new.test' ],
			'subdomain source → subdirectory target' => [ $sub, 'http://old.test', false, 'https://new.test/b2' ],
			'subdirectory source → subdomain target' => [ $dir, 'http://old.test', true, 'https://b2.new.test' ],
			'www on the old main is ignored'         => [ [ 'BlogID' => 2, 'Domain' => 'b2.old.test', 'Path' => '/' ], 'http://www.old.test', true, 'https://b2.new.test' ],
			'a custom domain is kept'                => [ [ 'BlogID' => 4, 'Domain' => 'Shop.Example', 'Path' => '/' ], 'http://old.test', false, 'https://shop.example' ],
			'a nested old main path'                 => [ [ 'BlogID' => 3, 'Domain' => 'old.test', 'Path' => '/net/b3/' ], 'http://old.test/net', false, 'https://new.test/b3' ],
			'single site: first label'               => [ [ 'BlogID' => null, 'HomeURL' => 'https://www.shop.example', 'Domain' => 'www.shop.example', 'Path' => '/' ], 'https://www.shop.example', false, 'https://new.test/shop' ],
			'single site: last path segment wins'    => [ [ 'BlogID' => null, 'HomeURL' => 'https://shop.example/store', 'Domain' => 'shop.example', 'Path' => '/store/' ], 'https://shop.example/store', true, 'https://store.new.test' ],
			'a host that only ends like the main'    => [ [ 'BlogID' => 2, 'Domain' => 'notold.test', 'Path' => '/' ], 'http://old.test', false, 'https://notold.test' ],
			'a custom domain keeps its path'         => [ [ 'BlogID' => 5, 'Domain' => 'other.example', 'Path' => '/blog/' ], 'http://old.test', true, 'https://other.example/blog' ],
			'an old port is not part of the name'    => [ [ 'BlogID' => 2, 'Domain' => 'old.test:8080', 'Path' => '/b2/' ], 'http://old.test:8080', false, 'https://new.test/b2' ],
		];
	}

	/**
	 * @dataProvider cases
	 * @param array<string, mixed> $old
	 */
	public function test_proposal( array $old, string $oldMainHome, bool $subdomain, string $expected ): void {
		$to = TargetNetwork::fromArray( [ 'subdomain' => $subdomain, 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https' ] );
		$this->assertSame( $expected, UrlProposal::for( SiteEntry::fromArray( $old ), $oldMainHome, $to ) );
	}

	public function test_the_target_port_and_path_are_kept(): void {
		$to = TargetNetwork::fromArray( [ 'subdomain' => false, 'domain' => '127.0.0.1', 'port' => 8897, 'path' => '/wp/', 'scheme' => 'http' ] );
		$this->assertSame( 'http://127.0.0.1:8897/wp/b2', UrlProposal::for( SiteEntry::fromArray( [ 'BlogID' => 2, 'Domain' => 'old.test', 'Path' => '/b2/' ] ), 'http://old.test', $to ) );
	}

	public function test_a_custom_domain_with_a_port_gets_the_target_port_once(): void {
		$to = TargetNetwork::fromArray( [ 'subdomain' => true, 'domain' => 'new.test', 'port' => 8897, 'path' => '/', 'scheme' => 'http' ] );
		$this->assertSame( 'http://shop.example:8897', UrlProposal::for( SiteEntry::fromArray( [ 'BlogID' => 4, 'Domain' => 'shop.example:8080', 'Path' => '/' ] ), 'http://old.test:8080', $to ) );
	}

	public function test_the_old_main_site_proposes_the_target_main_address(): void {
		$to = TargetNetwork::fromArray( [ 'subdomain' => false, 'domain' => 'new.test', 'path' => '/', 'scheme' => 'https' ] );
		$this->assertSame( 'https://new.test', UrlProposal::for( SiteEntry::fromArray( [ 'BlogID' => 1, 'Domain' => 'old.test', 'Path' => '/' ] ), 'http://old.test', $to ) );
	}
}
