<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayAddress;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Our own text for every refusal; it never repeats the address.
 */
final class RelayAddressTextTest extends MonkeyTestCase {

	protected function tearDown(): void {
		Relay::useForTests( null );
		parent::tearDown();
	}

	public function test_every_refusal_has_our_text_without_the_address(): void {
		$cases = [
			RelayAddress::HTTPS   => 'http://secret-host.example/wp-json/lw-migration/v1/oauth/callback',
			RelayAddress::IP      => 'https://203.0.113.5/wp-json/lw-migration/v1/oauth/callback',
			RelayAddress::ROUTE   => 'https://secret-host.example/api/lw-migration/v1/oauth/callback',
			RelayAddress::ADDRESS => 'https://secret-host.example:8443/wp-json/lw-migration/v1/oauth/callback',
		];
		$texts = [];
		foreach ( $cases as $code => $uri ) {
			$refusal = RelayAddress::check( $uri );

			$this->assertSame( $code, $refusal['code'] ?? null, $uri );
			$this->assertNotSame( '', $refusal['text'] );
			$this->assertStringNotContainsString( 'secret-host', $refusal['text'] );
			$this->assertStringNotContainsString( '203.0.113.5', $refusal['text'] );
			$texts[] = $refusal['text'];
		}
		$this->assertCount( 4, array_unique( $texts ) );
		$this->assertNull( RelayAddress::check( 'https://example.com/wp-json/lw-migration/v1/oauth/callback' ) );
	}

	public function test_check_uses_the_seams_http_hosts_only(): void {
		$uri = 'http://site-a.test/?rest_route=/lw-migration/v1/oauth/callback';

		$this->assertSame( RelayAddress::HTTPS, RelayAddress::check( $uri )['code'] ?? null );
		Relay::useForTests( 'https://lwm-relay.invalid', [ 'site-a.test' ] );
		$this->assertNull( RelayAddress::check( $uri ) );
	}
}
