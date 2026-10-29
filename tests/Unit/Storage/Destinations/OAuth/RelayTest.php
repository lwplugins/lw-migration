<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use PHPUnit\Framework\TestCase;

/**
 * Spec auth-lwplugins-com §2.1: the authorize address, and the test-only seam.
 */
final class RelayTest extends TestCase {

	private const RETURN = 'https://example.com/wp-json/lw-migration/v1/oauth/callback';

	protected function tearDown(): void {
		Relay::useForTests( null );
		parent::tearDown();
	}

	public function test_the_authorize_address_carries_exactly_the_protocol_parameters(): void {
		$url = Relay::authorizeUrl( Provider::google(), self::RETURN, str_repeat( 'a', 64 ), str_repeat( 'B', 43 ) );

		$this->assertSame(
			'https://auth.lwplugins.com/v1/authorize?v=1&provider=google&return=https%3A%2F%2Fexample.com%2Fwp-json%2Flw-migration%2Fv1%2Foauth%2Fcallback&state=' . str_repeat( 'a', 64 ) . '&code_challenge=' . str_repeat( 'B', 43 ) . '&code_challenge_method=S256',
			$url
		);
	}

	public function test_onedrive_asks_for_the_app_folder_and_nothing_names_a_client(): void {
		parse_str( (string) parse_url( Relay::authorizeUrl( Provider::microsoft(), self::RETURN, str_repeat( 'a', 64 ), str_repeat( 'B', 43 ) ), PHP_URL_QUERY ), $query );
		parse_str( (string) parse_url( Relay::authorizeUrl( Provider::dropbox(), self::RETURN, str_repeat( 'a', 64 ), str_repeat( 'B', 43 ) ), PHP_URL_QUERY ), $dropbox );

		$this->assertSame( [ 'v', 'provider', 'return', 'state', 'code_challenge', 'code_challenge_method', 'access' ], array_keys( $query ) );
		$this->assertSame( [ 'microsoft', 'appfolder' ], [ $query['provider'], $query['access'] ] );
		$this->assertSame( [ 'v', 'provider', 'return', 'state', 'code_challenge', 'code_challenge_method' ], array_keys( $dropbox ) );
	}

	public function test_the_seam_moves_the_base_and_resets_it(): void {
		$this->assertSame( Relay::BASE, Relay::base() );
		$this->assertSame( [], Relay::httpHosts() );

		Relay::useForTests( 'https://lwm-relay.invalid', [ 'site-a.test' ] );
		$this->assertSame( 'https://lwm-relay.invalid', Relay::base() );
		$this->assertSame( [ 'site-a.test' ], Relay::httpHosts() );
		$this->assertStringStartsWith( 'https://lwm-relay.invalid/v1/authorize?', Relay::authorizeUrl( Provider::dropbox(), self::RETURN, str_repeat( 'a', 64 ), str_repeat( 'B', 43 ) ) );

		Relay::useForTests( null );
		$this->assertSame( Relay::BASE, Relay::base() );
		$this->assertSame( [], Relay::httpHosts() );
	}

	public function test_the_seam_lower_cases_the_http_hosts(): void {
		Relay::useForTests( 'https://lwm-relay.invalid', [ 'Site-A.Test' ] );
		$this->assertSame( [ 'site-a.test' ], Relay::httpHosts() );
	}

	public function test_the_seam_takes_only_scheme_host_and_port(): void {
		foreach ( [ 'https://x.invalid/path', 'HTTPS://X.INVALID', 'ftp://x.invalid', 'https://x.invalid:443/', 'https://', 'x.invalid' ] as $bad ) {
			try {
				Relay::useForTests( $bad );
				$this->fail( 'Refused: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( Relay::BASE, Relay::base(), 'A refused base changes nothing.' );
			}
		}
	}
}
