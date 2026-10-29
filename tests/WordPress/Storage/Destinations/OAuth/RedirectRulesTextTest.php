<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectRules;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * check() = the pure firstBroken() plus our translated text; and the
 * redirect address itself (rest_url()).
 */
final class RedirectRulesTextTest extends MonkeyTestCase {

	/**
	 * @return array<string, array{0: Provider, 1: string, 2: string, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'https'  => [ Provider::google(), 'http://example.com/cb', RedirectRules::HTTPS, 'Google Drive' ],
			'ip'     => [ Provider::google(), 'https://203.0.113.5/cb', RedirectRules::IP, 'Google' ],
			'domain' => [ Provider::google(), 'https://shop.test/cb', RedirectRules::DOMAIN, 'Google' ],
			'query'  => [ Provider::microsoft(), 'https://example.com/?rest_route=/x', RedirectRules::QUERY, 'Microsoft' ],
			'length' => [ Provider::microsoft(), 'https://example.com/' . str_repeat( 'a', 300 ), RedirectRules::LENGTH, 'Microsoft' ],
			'chars'  => [ Provider::microsoft(), 'https://example.com/a;b', RedirectRules::CHARS, 'Microsoft' ],
			'idn'    => [ Provider::microsoft(), 'https://xn--bcher-kva.example/cb', RedirectRules::IDN, 'Microsoft' ],
		];
	}

	#[DataProvider( 'refusals' )]
	public function test_every_refusal_has_our_text( Provider $provider, string $uri, string $code, string $name ): void {
		$refusal = RedirectRules::check( $provider, $uri );

		$this->assertSame( $code, $refusal['code'] ?? null );
		$this->assertStringContainsString( $name, $refusal['text'] ?? '' );
		$this->assertStringNotContainsString( 'example.com', $refusal['text'] ?? '', 'The text never echoes the address.' );
	}

	public function test_an_acceptable_address_is_null(): void {
		$this->assertNull( RedirectRules::check( Provider::google(), 'https://example.com/wp-json/lw-migration/v1/oauth/callback' ) );
	}

	public function test_the_redirect_address_is_this_sites_rest_route(): void {
		Functions\when( 'rest_url' )->alias( static fn( string $path ): string => 'https://site.test/wp-json/' . $path );

		$this->assertSame( 'https://site.test/wp-json/lw-migration/v1/oauth/callback', RedirectUri::current() );
	}
}
