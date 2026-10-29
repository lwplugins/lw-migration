<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure part of RedirectRules (no WordPress function on this path).
 */
final class RedirectRulesTest extends TestCase {

	private const CB = '/wp-json/lw-migration/v1/oauth/callback';

	/**
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public static function cases(): array {
		$plain = '/?rest_route=/lw-migration/v1/oauth/callback';

		return [
			'google https domain'           => [ 'google', 'https://example.com' . self::CB, null ],
			'google http domain'            => [ 'google', 'http://example.com' . self::CB, RedirectRules::HTTPS ],
			'google http localhost'         => [ 'google', 'http://localhost:8080' . self::CB, null ],
			'google https localhost'        => [ 'google', 'https://localhost' . self::CB, null ],
			'google http 127.0.0.1'         => [ 'google', 'http://127.0.0.1' . self::CB, null ],
			'google bare ip'                => [ 'google', 'https://203.0.113.5' . self::CB, RedirectRules::IP ],
			'google bare ipv6'              => [ 'google', 'https://[2001:db8::1]' . self::CB, RedirectRules::IP ],
			'google .test'                  => [ 'google', 'https://shop.test' . self::CB, RedirectRules::DOMAIN ],
			'google .local upper case'      => [ 'google', 'https://Shop.LOCAL' . self::CB, RedirectRules::DOMAIN ],
			'google no dot'                 => [ 'google', 'https://intranet' . self::CB, RedirectRules::DOMAIN ],
			'google plain permalinks'       => [ 'google', 'https://example.com' . $plain, null ],
			'google ftp scheme'             => [ 'google', 'ftp://example.com' . self::CB, RedirectRules::HTTPS ],
			'google unparseable'            => [ 'google', 'http:///nothing', RedirectRules::HTTPS ],
			'dropbox http domain'           => [ 'dropbox', 'http://example.com' . self::CB, RedirectRules::HTTPS ],
			'dropbox http localhost'        => [ 'dropbox', 'http://localhost' . self::CB, null ],
			'dropbox bare ip https'         => [ 'dropbox', 'https://203.0.113.5' . self::CB, null ],
			'dropbox plain permalinks'      => [ 'dropbox', 'https://example.com' . $plain, null ],
			'microsoft https domain'        => [ 'microsoft', 'https://example.com' . self::CB, null ],
			'microsoft http domain'         => [ 'microsoft', 'http://example.com' . self::CB, RedirectRules::HTTPS ],
			'microsoft http localhost'      => [ 'microsoft', 'http://localhost' . self::CB, null ],
			'microsoft http 127.0.0.1'      => [ 'microsoft', 'http://127.0.0.1' . self::CB, RedirectRules::HTTPS ],
			'microsoft http ipv6 loopback'  => [ 'microsoft', 'http://[::1]' . self::CB, RedirectRules::HTTPS ],
			'dropbox http 127.0.0.1'        => [ 'dropbox', 'http://127.0.0.1' . self::CB, null ],
			'microsoft query common tenant' => [ 'microsoft', 'https://example.com' . $plain, RedirectRules::QUERY ],
			'microsoft special characters'  => [ 'microsoft', "https://example.com/it's" . self::CB, RedirectRules::CHARS ],
			'microsoft comma'               => [ 'microsoft', 'https://example.com/a,b' . self::CB, RedirectRules::CHARS ],
			'microsoft idn'                 => [ 'microsoft', 'https://xn--bcher-kva.example' . self::CB, RedirectRules::IDN ],
			'microsoft idn raw'             => [ 'microsoft', 'https://bücher.example' . self::CB, RedirectRules::IDN ],
			'microsoft too long'            => [ 'microsoft', 'https://example.com/' . str_repeat( 'a', 240 ) . self::CB, RedirectRules::LENGTH ],
			'microsoft exactly 256'         => [ 'microsoft', 'https://example.com/' . str_repeat( 'a', 256 - 20 ), null ],
		];
	}

	#[DataProvider( 'cases' )]
	public function test_each_rule( string $provider, string $uri, ?string $code ): void {
		$p = match ( $provider ) {
			'google' => Provider::google(),
			'dropbox' => Provider::dropbox(),
			default => Provider::microsoft(),
		};

		$this->assertSame( $code, RedirectRules::firstBroken( $p, $uri ) );
	}

	public function test_a_work_only_tenant_may_have_a_query(): void {
		$this->assertNull( RedirectRules::firstBroken( Provider::microsoft( 'organizations' ), 'https://example.com/?rest_route=/x' ) );
		$this->assertSame( RedirectRules::QUERY, RedirectRules::firstBroken( Provider::microsoft( 'consumers' ), 'https://example.com/?rest_route=/x' ) );
	}
}
