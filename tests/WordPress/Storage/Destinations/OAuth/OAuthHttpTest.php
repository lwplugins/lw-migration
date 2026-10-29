<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan Task 4 (spec 21.8, E7) with preflight P-C2: every request through
 * ProviderHttp (checked, pinned, TLS checked, no redirect followed), the
 * Bearer token only to a provider API host, decided before any token is
 * fetched.
 */
final class OAuthHttpTest extends MonkeyTestCase {

	private const NOW = 1_800_000_000;

	public int $refreshes = 0;

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver( static fn( string $host ): array => 'metadata.example' === $host ? [ '169.254.169.254' ] : [ '93.184.216.34' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function http( RecordedHttp $recorded ): OAuthHttp {
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$vault->put( 'abcd1234', [ 'refreshToken' => 'r', 'accessToken' => ( self::NOW + 3600 ) . ':old', TokenKeeper::GRANT => 'g1' ] );
		$test     = $this;
		$endpoint = new class( $test ) implements TokenEndpoint {
			public function __construct( private OAuthHttpTest $test ) {
			}
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				++$this->test->refreshes;
				return new TokenSet( 'fresh', OAuthHttpTest::now() + 3600 );
			}
		};
		$keeper = new TokenKeeper( 'abcd1234', new OAuthApp( Provider::google(), 'c', 's' ), (array) $vault->open( 'abcd1234' ), $endpoint, $vault, static fn(): int => self::NOW );

		return new OAuthHttp( $keeper, $this->curl->wrap( $recorded->send() ), static fn(): int => self::NOW );
	}

	public static function now(): int {
		return self::NOW;
	}

	public function test_a_request_carries_the_bearer_token_and_safe_transport_options(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{"ok":true}', [ 'Location' => 'https://x.test/a', 'X-Other' => 'no' ] ) ] );

		$answer = $this->http( $recorded )->request( 'GET', 'https://www.googleapis.com/drive/v3/about', [ 'Accept' => 'application/json' ] );

		$args = $recorded->requests[0][1];
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( [ 'Accept' => 'application/json', 'Authorization' => 'Bearer old' ], $args['headers'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( CloudTimeout::seconds(), $args['timeout'] );
		$this->assertSame( OAuthHttp::MAX_JSON, $args['limit_response_size'] );
		$this->assertSame( 1, $this->curl->seen, 'Checked and pinned.' );
		$this->assertSame( [ 'location' => 'https://x.test/a' ], $answer['headers'] );
		$this->assertSame( [ 'ok' => true ], $this->http( new RecordedHttp() )->json( $answer ) );
		$this->assertSame( 'Google Drive', $this->http( new RecordedHttp() )->label() );
		$this->assertSame( self::NOW, $this->http( new RecordedHttp() )->now() );
	}

	public function test_the_timeout_and_the_answer_limit_can_be_set_and_a_download_may_have_none(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 206, 'abc' ), RecordedHttp::answer( 200, 'abc' ) ] );
		$http     = $this->http( $recorded );

		$http->request( 'GET', 'https://www.googleapis.com/drive/v3/files/x', [], '', [ 'timeout' => 33, 'limit' => 3 ] );
		$http->request( 'GET', 'https://www.googleapis.com/drive/v3/files/x', [], '', [ 'limit' => null ] );

		$this->assertSame( [ 33, 3 ], [ $recorded->requests[0][1]['timeout'], $recorded->requests[0][1]['limit_response_size'] ] );
		$this->assertArrayNotHasKey( 'limit_response_size', $recorded->requests[1][1] );
	}

	public function test_a_401_refreshes_once_and_retries_with_the_new_token(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 401 ), RecordedHttp::answer( 200, '{}' ) ] );

		$answer = $this->http( $recorded )->request( 'GET', 'https://graph.microsoft.com/v1.0/me/drive' );

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( 1, $this->refreshes );
		$this->assertSame( 'Bearer old', $recorded->requests[0][1]['headers']['Authorization'] );
		$this->assertSame( 'Bearer fresh', $recorded->requests[1][1]['headers']['Authorization'] );
	}

	public function test_a_second_401_is_returned_and_not_retried_again(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 401 ), RecordedHttp::answer( 401 ) ] );

		$this->assertSame( 401, $this->http( $recorded )->request( 'GET', 'https://api.dropboxapi.com/2/x' )['status'] );
		$this->assertCount( 2, $recorded->requests );
		$this->assertSame( 1, $this->refreshes );
	}

	public function test_without_auth_no_authorization_header_and_no_refresh(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 401 ) ] );

		$answer = $this->http( $recorded )->request( 'PUT', 'https://upload.example/session', [], 'bytes', [ 'auth' => false ] );

		$this->assertSame( 401, $answer['status'] );
		$this->assertArrayNotHasKey( 'Authorization', $recorded->requests[0][1]['headers'] );
		$this->assertSame( 'bytes', $recorded->requests[0][1]['body'] );
		$this->assertCount( 1, $recorded->requests );
		$this->assertSame( 0, $this->refreshes );
	}

	/**
	 * Preflight P-C2 / O8: the Bearer token goes only to a provider API host —
	 * asking for it elsewhere is a programming error, found before any token is fetched or sent.
	 */
	public function test_auth_to_another_host_is_refused_before_any_token_is_used(): void {
		$recorded = new RecordedHttp();

		try {
			$this->http( $recorded )->request( 'PUT', 'https://upload.example/session', [], 'bytes' );
			$this->fail( 'A token to another host is a programming error.' );
		} catch ( \LogicException $e ) {
			$this->assertStringNotContainsString( 'old', $e->getMessage() );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_a_caller_never_sets_its_own_authorization_header(): void {
		foreach ( [ [], [ 'auth' => false ] ] as $options ) {
			$recorded = new RecordedHttp();
			try {
				$this->http( $recorded )->request( 'GET', 'https://www.googleapis.com/drive/v3/about', [ ' authorization' => 'Bearer stolen' ], '', $options );
				$this->fail( 'Only OAuthHttp sets Authorization.' );
			} catch ( \LogicException $e ) {
				$this->assertStringNotContainsString( 'stolen', $e->getMessage() );
			}
			$this->assertSame( [], $recorded->requests );
		}
	}

	public function test_a_transport_error_is_network_and_names_no_address(): void {
		try {
			$this->http( new RecordedHttp( [ new FakeWpError( 'cURL error 6: Could not resolve host: secret-session.example' ) ] ) )->request( 'GET', 'https://secret-session.example/up?token=abc', [], '', [ 'auth' => false ] );
			$this->fail( 'It fails.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
			$this->assertStringNotContainsString( 'token=abc', $e->getMessage() );
			$this->assertStringNotContainsString( 'secret-session', $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refusedAddresses(): array {
		return [
			'plain http'       => [ 'http://upload.example/session' ],
			'another port'     => [ 'https://upload.example:8443/session' ],
			'userinfo'         => [ 'https://u:p@upload.example/session' ],
			'metadata address' => [ 'https://metadata.example/latest' ],
		];
	}

	#[DataProvider( 'refusedAddresses' )]
	public function test_an_address_off_the_rules_is_refused_before_sending( string $url ): void {
		$recorded = new RecordedHttp();

		try {
			$this->http( $recorded )->request( 'PUT', $url, [], '', [ 'auth' => false ] );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'upload.example', $e->getMessage() );
		}
		$this->assertSame( [], $recorded->requests );
		$this->assertSame( 0, $this->refreshes );
	}

	public function test_a_refused_api_address_fetches_no_token(): void {
		$recorded = new RecordedHttp();
		$this->expectException( PermanentFailure::class );

		try {
			$this->http( $recorded )->request( 'GET', 'http://www.googleapis.com/drive/v3/about' );
		} finally {
			$this->assertSame( [], $recorded->requests );
			$this->assertSame( 0, $this->refreshes );
		}
	}

	public function test_the_address_and_the_body_are_marked_sensitive(): void {
		foreach ( ( new \ReflectionMethod( OAuthHttp::class, 'request' ) )->getParameters() as $parameter ) {
			if ( in_array( $parameter->getName(), [ 'url', 'body' ], true ) ) {
				$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ), $parameter->getName() );
			}
		}
	}

	public function test_the_tokens_are_reachable_for_the_destination(): void {
		$this->assertSame( 'Google Drive', $this->http( new RecordedHttp() )->tokens()->app()->provider->label );
	}
}
