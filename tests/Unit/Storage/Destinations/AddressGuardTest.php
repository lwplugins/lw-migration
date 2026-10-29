<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressGuardTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function addresses(): array {
		return [
			'this network'            => [ '0.0.0.0', false ],
			'0/8'                     => [ '0.1.2.3', false ],
			'metadata'                => [ '169.254.169.254', false ],
			'link-local'              => [ '169.254.0.1', false ],
			'alibaba metadata'        => [ '100.100.100.200', false ],
			'multicast'               => [ '224.0.0.1', false ],
			'reserved'                => [ '240.0.0.1', false ],
			'broadcast'               => [ '255.255.255.255', false ],
			'unspecified v6'          => [ '::', false ],
			'link-local v6'           => [ 'fe80::1', false ],
			'link-local v6 top'       => [ 'febf::1', false ],
			'multicast v6'            => [ 'ff02::1', false ],
			'aws v6 metadata'         => [ 'fd00:ec2::254', false ],
			'mapped metadata'         => [ '::ffff:a9fe:a9fe', false ],
			'mapped dotted'           => [ '::ffff:169.254.169.254', false ],
			'compat metadata'         => [ '::a9fe:a9fe', false ],
			'nat64 metadata'          => [ '64:ff9b::a9fe:a9fe', false ],
			'not an address'          => [ 'metadata.google.internal', false ],
			'loopback'                => [ '127.0.0.1', true ],
			'loopback v6'             => [ '::1', true ],
			'10/8'                    => [ '10.0.0.5', true ],
			'172.16/12'               => [ '172.16.0.1', true ],
			'192.168/16'              => [ '192.168.1.10', true ],
			'ula'                     => [ 'fd12:3456::1', true ],
			'other fd00 address'      => [ 'fd00:ec2::253', true ],
			'carrier-grade nat'       => [ '100.64.0.1', true ],
			'100.100.100.201'         => [ '100.100.100.201', true ],
			'public'                  => [ '93.184.216.34', true ],
			'mapped loopback'         => [ '::ffff:7f00:1', true ],
			'public v6'               => [ '2606:4700::1111', true ],
		];
	}

	#[DataProvider( 'addresses' )]
	public function test_every_range_is_decided( string $ip, bool $allowed ): void {
		$this->assertSame( $allowed, AddressGuard::allowed( $ip ) );
	}

	public function test_one_refused_address_among_allowed_ones_refuses_the_host(): void {
		$this->assertTrue( AddressGuard::allAllowed( [ '10.0.0.1', '::1' ] ) );
		$this->assertFalse( AddressGuard::allAllowed( [ '10.0.0.1', '169.254.169.254' ] ) );
		$this->assertFalse( AddressGuard::allAllowed( [] ) );
	}

	public function test_a_literal_is_its_own_address_and_a_name_is_resolved_and_filtered(): void {
		$guard = new AddressGuard( static fn( string $host ): array => 'minio.test' === $host ? [ '10.0.0.5', 'junk', '10.0.0.5', '::1' ] : [] );

		$this->assertSame( [], $guard->addresses( '2852039166' ), 'A decimal form is a name here, not an address.' );
		$this->assertSame( [ '10.0.0.5', '::1' ], $guard->addresses( 'minio.test' ) );
		$this->assertSame( [ '::1' ], $guard->addresses( '::1' ) );
		$this->assertSame( [], $guard->addresses( 'nowhere.test' ) );
	}

	/**
	 * Issue #2: the stricter rule of link calls and URL imports.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function webAddresses(): array {
		$refused  = AddressGuard::WEB_REFUSED;
		$internal = AddressGuard::WEB_INTERNAL;
		$public   = AddressGuard::WEB_PUBLIC;

		return [
			'metadata'              => [ '169.254.169.254', $refused ],
			'alibaba metadata'      => [ '100.100.100.200', $refused ],
			'carrier-grade nat'     => [ '100.64.0.1', $refused ],
			'carrier-grade nat top' => [ '100.127.255.254', $refused ],
			'benchmark'             => [ '198.18.0.1', $refused ],
			'benchmark top'         => [ '198.19.255.254', $refused ],
			'multicast'             => [ '239.1.2.3', $refused ],
			'reserved'              => [ '240.0.0.1', $refused ],
			'this network'          => [ '0.0.0.0', $refused ],
			'link-local v6'         => [ 'fe80::1', $refused ],
			'multicast v6'          => [ 'ff02::1', $refused ],
			'mapped cgnat'          => [ '::ffff:100.64.0.1', $refused ],
			'nat64 benchmark'       => [ '64:ff9b::c612:1', $refused ],
			'not an address'        => [ 'b.example', $refused ],
			'loopback'              => [ '127.0.0.1', $internal ],
			'loopback v6'           => [ '::1', $internal ],
			'10/8'                  => [ '10.0.0.5', $internal ],
			'172.16/12'             => [ '172.31.255.1', $internal ],
			'192.168/16'            => [ '192.168.1.10', $internal ],
			'ietf assignments'      => [ '192.0.0.8', $internal ],
			'test-net-1'            => [ '192.0.2.1', $internal ],
			'6to4 relay'            => [ '192.88.99.1', $internal ],
			'test-net-2'            => [ '198.51.100.7', $internal ],
			'test-net-3'            => [ '203.0.113.9', $internal ],
			'ula'                   => [ 'fd12:3456::1', $internal ],
			'mapped loopback'       => [ '::ffff:127.0.0.1', $internal ],
			'below cgnat'           => [ '100.63.255.255', $public ],
			'above cgnat'           => [ '100.128.0.1', $public ],
			'below benchmark'       => [ '198.17.255.255', $public ],
			'above 172.16/12'       => [ '172.32.0.1', $public ],
			'public'                => [ '93.184.216.34', $public ],
			'public v6'             => [ '2606:4700::1111', $public ],
		];
	}

	#[DataProvider( 'webAddresses' )]
	public function test_every_range_is_decided_for_web_requests( string $ip, string $kind ): void {
		$this->assertSame( $kind, AddressGuard::webKind( $ip ) );
	}

	public function test_internal_addresses_need_the_host_allowed_and_refused_ones_never_pass(): void {
		$this->assertTrue( AddressGuard::webAllowed( [ '93.184.216.34', '2606:4700::1111' ], false ) );
		$this->assertFalse( AddressGuard::webAllowed( [ '93.184.216.34', '127.0.0.1' ], false ) );
		$this->assertTrue( AddressGuard::webAllowed( [ '93.184.216.34', '127.0.0.1' ], true ) );
		$this->assertFalse( AddressGuard::webAllowed( [ '127.0.0.1', '100.64.0.1' ], true ), 'Allowing the host never opens a refused range.' );
		$this->assertFalse( AddressGuard::webAllowed( [], true ) );
	}

	public function test_host_and_port_are_split(): void {
		$this->assertSame( [ 'a.test', 443 ], AddressGuard::split( 'a.test', 443 ) );
		$this->assertSame( [ 'a.test', 9000 ], AddressGuard::split( 'a.test:9000', 443 ) );
		$this->assertSame( [ '::1', 9000 ], AddressGuard::split( '[::1]:9000', 443 ) );
		$this->assertSame( [ '::1', 80 ], AddressGuard::split( '[::1]', 80 ) );
	}
}
