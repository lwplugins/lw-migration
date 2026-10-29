<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\OAuthApi;
use LightweightPlugins\Migration\Rest\OAuthRoutes;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class OAuthRoutesTest extends MonkeyTestCase {

	private const CB = 'https://www.example.com/wp-json/lw-migration/v1/oauth/callback';

	private const PAGE = 'https://www.example.com/wp-admin/admin.php?page=lw-migration';

	public function test_connect_needs_the_nonce_check_and_the_callback_its_own_cookie_check(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $ns, string $route, array $args ) use ( &$registered ): void {
				$registered[ $route ] = $args;
			}
		);

		OAuthRoutes::register();

		$connect  = $registered['/destinations/(?P<did>[a-f0-9]{8})/connect'][0];
		$callback = $registered['/oauth/callback'][0];
		$this->assertSame( 'POST', $connect['methods'] );
		$this->assertSame( [ Permission::class, 'check' ], $connect['permission_callback'] );
		$this->assertSame( 'GET', $callback['methods'] );
		$this->assertSame( [ Permission::class, 'oauthCallback' ], $callback['permission_callback'] );
		$this->assertFalse( $callback['show_in_index'] );
	}

	/**
	 * O4/O5/P-M9: the user is the login cookie's; the answer is a 302 to
	 * our page, never cached, without a Referer.
	 */
	#[RunInSeparateProcess]
	public function test_the_callback_finishes_for_the_cookie_user_and_redirects_to_our_page(): void {
		[ $api, $state ] = $this->started( 7 );
		$this->cookieUser( 7 );

		$response = OAuthRoutes::redirect( $this->request( 'GET', [ 'code' => 'c', 'state' => $state ] ), static fn(): OAuthApi => $api );

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame(
			[
				'Location'        => self::PAGE . '&lwm-oauth=connected&lwm-destination=aaaa1111#destinations',
				'Cache-Control'   => 'no-store',
				'Referrer-Policy' => 'no-referrer',
			],
			$response->get_headers()
		);
	}

	#[RunInSeparateProcess]
	public function test_the_state_of_another_cookie_user_finishes_nothing(): void {
		[ $api, $state ] = $this->started( 7 );
		$this->cookieUser( 8 );

		$response = OAuthRoutes::redirect( $this->request( 'GET', [ 'code' => 'c', 'state' => $state ] ), static fn(): OAuthApi => $api );

		$this->assertSame( self::PAGE . '&lwm-oauth=expired#destinations', $response->get_headers()['Location'] );
	}

	/**
	 * Core runs a HEAD request through the GET handler: it uses nothing up.
	 */
	#[RunInSeparateProcess]
	public function test_a_head_request_uses_no_state(): void {
		[ $api, $state ] = $this->started( 7 );
		$this->cookieUser( 7 );

		$head = OAuthRoutes::redirect( $this->request( 'HEAD', [ 'code' => 'c', 'state' => $state ] ), static fn(): OAuthApi => $api );
		$get  = OAuthRoutes::redirect( $this->request( 'GET', [ 'code' => 'c', 'state' => $state ] ), static fn(): OAuthApi => $api );

		$this->assertSame( self::PAGE . '&lwm-oauth=failed#destinations', $head->get_headers()['Location'] );
		$this->assertStringContainsString( 'lwm-oauth=connected', $get->get_headers()['Location'] );
	}

	#[RunInSeparateProcess]
	public function test_an_unexpected_failure_is_the_failed_redirect_without_a_message(): void {
		$this->stubs();
		$this->cookieUser( 7 );

		$response = OAuthRoutes::redirect(
			$this->request( 'GET', [ 'state' => 'x' ] ),
			static function (): OAuthApi {
				throw new \RuntimeException( 'secret detail' );
			}
		);

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( self::PAGE . '&lwm-oauth=failed#destinations', $response->get_headers()['Location'] );
		$this->assertNull( $response->get_data() );
	}

	private function stubs(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-request-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-response-class.php';
		FakeOptions::install();
		FakeWpdb::install();
		FakeTransients::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://www.example.com/wp-admin/' . $path );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-7' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '&' . http_build_query( $args ) );
	}

	private function cookieUser( int $id ): void {
		Functions\when( 'wp_validate_auth_cookie' )->justReturn( $id );
		Functions\when( 'user_can' )->justReturn( true );
	}

	/**
	 * @param array<string, mixed> $query Query.
	 */
	private function request( string $method, array $query ): \WP_REST_Request {
		$request        = new \WP_REST_Request( $method, '/lw-migration/v1/oauth/callback' );
		$request->query = $query;

		return $request;
	}

	/**
	 * An API over one connectable profile, and a state issued to $user.
	 *
	 * @return array{0: OAuthApi, 1: string}
	 */
	private function started( int $user ): array {
		$this->stubs();
		$endpoint = new class() implements TokenEndpoint {
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				return new TokenSet( 'access-1', 2_000_000_000, 'refresh-1' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
		};
		$vault        = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$destinations = new Destinations( new ProfileRepository(), $vault, new DestinationRegistry( [ new FakeOAuthType( $vault, $endpoint ) ] ) );
		$destinations->profiles()->save( new Profile( 'aaaa1111', 'fakeoauth', 'Drive', [ 'clientId' => 'client-1' ] ) );
		$vault->put( 'aaaa1111', [ 'clientSecret' => 'secret-1' ] );
		$api = new OAuthApi( $destinations, new OAuthState(), static fn(): string => self::CB, static fn(): string => self::PAGE, static fn(): int => time() );
		parse_str( (string) parse_url( $api->start( 'aaaa1111', $user )['url'], PHP_URL_QUERY ), $query );

		return [ $api, (string) $query['state'] ];
	}
}
