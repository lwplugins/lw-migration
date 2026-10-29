<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec auth-lwplugins-com §2.5, the pure part (no WordPress function).
 * Review Focus 1.
 */
final class RelayAddressTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: array<int, string>, 2: string|null}>
	 */
	public static function cases(): array {
		$pretty = '/wp-json/lw-migration/v1/oauth/callback';
		$plain  = '/?rest_route=/lw-migration/v1/oauth/callback';

		return [
			'https pretty'              => [ 'https://example.com' . $pretty, [], null ],
			'https pretty in a folder'  => [ 'https://example.com/blog' . $pretty, [], null ],
			'https index.php pretty'    => [ 'https://example.com/index.php' . $pretty, [], null ],
			'https plain'               => [ 'https://example.com' . $plain, [], null ],
			'https plain index.php'     => [ 'https://example.com/index.php?rest_route=/lw-migration/v1/oauth/callback', [], null ],
			'https port 443'            => [ 'https://example.com:443' . $pretty, [], null ],
			'punycode host'             => [ 'https://xn--rvztr-fra.example' . $pretty, [], null ],
			'http'                      => [ 'http://example.com' . $pretty, [], RelayAddress::HTTPS ],
			'http on an allowed host'   => [ 'http://site-a.test' . $plain, [ 'site-a.test' ], null ],
			'http on another host'      => [ 'http://site-b.test' . $plain, [ 'site-a.test' ], RelayAddress::HTTPS ],
			'ftp'                       => [ 'ftp://example.com' . $pretty, [], RelayAddress::HTTPS ],
			'ipv4'                      => [ 'https://203.0.113.5' . $pretty, [], RelayAddress::IP ],
			'ipv6'                      => [ 'https://[2001:db8::1]' . $pretty, [], RelayAddress::IP ],
			'port 8443'                 => [ 'https://example.com:8443' . $pretty, [], RelayAddress::ADDRESS ],
			'userinfo'                  => [ 'https://user:pw@example.com' . $pretty, [], RelayAddress::ADDRESS ],
			'fragment'                  => [ 'https://example.com' . $pretty . '#x', [], RelayAddress::ADDRESS ],
			'upper case host'           => [ 'https://Example.COM' . $pretty, [], null ],
			'upper case scheme'         => [ 'HTTPS://example.com' . $pretty, [], null ],
			'http host any port, cased' => [ 'http://Site-A.test:8080' . $plain, [ 'site-a.test' ], null ],
			'single label host'         => [ 'https://intranet' . $pretty, [], RelayAddress::ADDRESS ],
			'numeric last label'        => [ 'https://example.123' . $pretty, [], RelayAddress::ADDRESS ],
			'short ipv4'                => [ 'https://127.1' . $pretty, [], RelayAddress::ADDRESS ],
			'hex last label'            => [ 'https://0x7f.0.0.1' . $pretty, [], RelayAddress::ADDRESS ],
			'empty port'                => [ 'https://example.com:' . $pretty, [], RelayAddress::ADDRESS ],
			'zero padded port'          => [ 'https://example.com:0443' . $pretty, [], RelayAddress::ADDRESS ],
			'non ascii byte'            => [ 'https://example.com/árv' . $pretty, [], RelayAddress::ADDRESS ],
			'backslash'                 => [ 'https://example.com/a\\b' . $pretty, [], RelayAddress::ADDRESS ],
			'hash'                      => [ 'https://example.com' . $pretty . '#', [], RelayAddress::ADDRESS ],
			'path bang'                 => [ 'https://example.com/a!b' . $pretty, [], RelayAddress::ROUTE ],
			'path parenthesis'          => [ 'https://example.com/blog(old)/?rest_route=/lw-migration/v1/oauth/callback', [], RelayAddress::ROUTE ],
			'path bracket'              => [ 'https://example.com/a[b]' . $pretty, [], RelayAddress::ROUTE ],
			'path allowed punctuation'  => [ 'https://example.com/a-b_c.d~e$f&g+h,i:j;k=l@m' . $pretty, [], null ],
			'bracketed test host'       => [ 'http://[site-a.test]' . $plain, [ 'site-a.test' ], RelayAddress::ADDRESS ],
			'percent 25'                => [ 'https://example.com/a%25b' . $pretty, [], RelayAddress::ROUTE ],
			'percent 2F'                => [ 'https://example.com/a%2Fb' . $pretty, [], RelayAddress::ROUTE ],
			'percent 41'                => [ 'https://example.com/%41' . $pretty, [], RelayAddress::ROUTE ],
			'dot segment first'         => [ 'https://example.com/.' . $pretty, [], RelayAddress::ROUTE ],
			'dot dot segment'           => [ 'https://example.com/x/..' . $pretty, [], RelayAddress::ROUTE ],
			'double slash'              => [ 'https://example.com/' . $pretty, [], RelayAddress::ROUTE ],
			'empty query'               => [ 'https://example.com' . $pretty . '?', [], RelayAddress::ADDRESS ],
			'trailing dot host'         => [ 'https://example.com.' . $pretty, [], RelayAddress::ADDRESS ],
			'too long'                  => [ 'https://example.com/' . str_repeat( 'a', 2048 ) . $pretty, [], RelayAddress::ADDRESS ],
			'a space'                   => [ 'https://example.com' . $pretty . ' ', [], RelayAddress::ADDRESS ],
			'not an address'            => [ 'http:///nothing', [], RelayAddress::ADDRESS ],
			'another route'             => [ 'https://example.com/wp-json/other/v1/oauth/callback', [], RelayAddress::ROUTE ],
			'a changed REST prefix'     => [ 'https://example.com/api/lw-migration/v1/oauth/callback', [], RelayAddress::ROUTE ],
			'pretty with a query'       => [ 'https://example.com' . $pretty . '?x=1', [], RelayAddress::ROUTE ],
			'plain with another param'  => [ 'https://example.com/?rest_route=/lw-migration/v1/oauth/callback&x=1', [], RelayAddress::ROUTE ],
			'plain with encoded slash'  => [ 'https://example.com/?rest_route=%2Flw-migration%2Fv1%2Foauth%2Fcallback', [], RelayAddress::ROUTE ],
			'plain on another file'     => [ 'https://example.com/wp-admin/admin.php?rest_route=/lw-migration/v1/oauth/callback', [], RelayAddress::ROUTE ],
			'pretty with a dot segment' => [ 'https://example.com' . $pretty . '/..', [], RelayAddress::ROUTE ],
		];
	}

	/**
	 * @param array<int, string> $httpHosts Hosts allowed on http.
	 */
	#[DataProvider( 'cases' )]
	public function test_the_relay_return_rule( string $uri, array $httpHosts, ?string $expected ): void {
		$this->assertSame( $expected, RelayAddress::firstBroken( $uri, $httpHosts ) );
	}

	public function test_the_address_is_normalised_like_the_relay_does(): void {
		$pretty = '/wp-json/lw-migration/v1/oauth/callback';

		$this->assertSame( 'https://example.com/blog' . $pretty, RelayAddress::normalized( 'HTTPS://Example.COM:443/blog' . $pretty ) );
		$this->assertSame( 'http://site-a.test:8080/?rest_route=/lw-migration/v1/oauth/callback', RelayAddress::normalized( 'http://Site-A.test:8080/?rest_route=/lw-migration/v1/oauth/callback', [ 'site-a.test' ] ) );
		$this->assertNull( RelayAddress::normalized( 'http://example.com' . $pretty ) );
	}
}
