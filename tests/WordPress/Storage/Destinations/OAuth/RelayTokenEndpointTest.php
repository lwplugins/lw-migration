<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Spec auth-lwplugins-com §2.3/§2.4/§2.6/§7 and Review Focus 3–4.
 */
final class RelayTokenEndpointTest extends MonkeyTestCase {

	private const NOW = 1_800_000_000;

	private const VERIFIER = 'verifier-1-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private const STATE = 'eyJrIjoiYSJ9.c2lnbmF0dXJl';

	private CurlHooks $curl;

	/** @var array<int, string> Hosts the resolver was asked for. */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver(
			function ( string $host ): array {
				$this->asked[] = $host;
				return [ '203.0.113.10' ];
			}
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		Relay::useForTests( null );
		parent::tearDown();
	}

	private function endpoint( RecordedHttp $http ): RelayTokenEndpoint {
		return new RelayTokenEndpoint( $this->curl->wrap( $http->send() ), static fn(): int => self::NOW );
	}

	private static function app( ?Provider $provider = null ): OAuthApp {
		return new OAuthApp( $provider ?? Provider::google(), '', '' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function body( RecordedHttp $http, int $i = 0 ): array {
		return (array) json_decode( (string) $http->requests[ $i ][1]['body'], true );
	}

	private static function failure( \Closure $call ): CloudFailure {
		try {
			$call();
		} catch ( CloudFailure $e ) {
			return $e;
		}
		self::fail( 'A failure was expected.' );
	}

	public function test_redeem_posts_json_to_the_fixed_host(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"ya29.a","expires_in":3599,"refresh_token":"1//r","scope":"https://www.googleapis.com/auth/drive.file","token_type":"Bearer"}' ) ] );

		$set = $this->endpoint( $http )->redeem( self::app(), 'code-1', self::VERIFIER, self::STATE );

		[ $url, $args ] = $http->requests[0];
		$this->assertSame( 'https://auth.lwplugins.com/v1/token', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( RelayTokenEndpoint::TIMEOUT, $args['timeout'] );
		$this->assertSame( RelayTokenEndpoint::MAX_ANSWER, $args['limit_response_size'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame(
			[
				'v'             => 1,
				'provider'      => 'google',
				'code'          => 'code-1',
				'code_verifier' => self::VERIFIER,
				'relay_state'   => self::STATE,
			],
			self::body( $http )
		);
		$this->assertSame( [ 'auth.lwplugins.com' ], $this->asked, 'Checked and pinned like a hosted preset.' );
		$this->assertSame( 1, $this->curl->seen );
		$this->assertTrue( $this->curl->clean() );
		$this->assertSame( [ 'ya29.a', self::NOW + 3599, '1//r' ], [ $set->accessToken, $set->expiresAt, $set->refreshToken ] );
	}

	public function test_refresh_posts_only_the_provider_and_the_refresh_token(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"sl.b","expires_in":14400,"token_type":"bearer"}' ) ] );

		$set = $this->endpoint( $http )->refresh( self::app( Provider::dropbox() ), 'rt-1' );

		$this->assertSame( 'https://auth.lwplugins.com/v1/refresh', $http->requests[0][0] );
		$this->assertSame(
			[
				'v'             => 1,
				'provider'      => 'dropbox',
				'refresh_token' => 'rt-1',
			],
			self::body( $http )
		);
		$this->assertSame( [ 'sl.b', '' ], [ $set->accessToken, $set->refreshToken ], 'No rotation: the stored refresh token stays.' );
		$this->assertStringNotContainsString( 'rt-1', $http->requests[0][0], 'Never in the address.' );
	}

	public function test_only_the_token_fields_of_the_answer_are_read(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"a","expires_in":3600,"token_type":"Bearer","account_id":"dbid:x","error_description":"x"}' ) ] );

		$set = $this->endpoint( $http )->refresh( self::app(), 'rt' );

		$this->assertSame( '', $set->accountId );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: bool, 3: string, 4: string}>
	 */
	public static function refusals(): array {
		$leak = ',"error_description":"LEAK-123"';

		return [
			'invalid_grant on a refresh'          => [ 400, '{"error":"invalid_grant"' . $leak . '}', true, PermanentFailure::REAUTH, 'withdrawn' ],
			'invalid_grant on an exchange'        => [ 400, '{"error":"invalid_grant"}', false, PermanentFailure::INVALID, 'Start the connection again' ],
			'invalid_request on a refresh'        => [ 400, '{"error":"invalid_request"}', true, PermanentFailure::REAUTH, 'withdrawn' ],
			'invalid_request on an exchange'      => [ 400, '{"error":"invalid_request"}', false, PermanentFailure::INVALID, 'Start the connection again' ],
			'rate limited'                        => [ 429, '{"error":"rate_limited"}', true, TransientFailure::BUSY, 'LW sign-in service asked to slow down' ],
			'provider unavailable'                => [ 503, '{"error":"temporarily_unavailable"}', true, TransientFailure::SERVER, 'Google Drive is not available right now' ],
			'misconfigured on a refresh'          => [ 503, '{"error":"relay_misconfigured"' . $leak . '}', true, TransientFailure::SERVER, 'LW sign-in service cannot refresh the connection right now; we are on it' ],
			'misconfigured on an exchange'        => [ 503, '{"error":"relay_misconfigured"}', false, TransientFailure::SERVER, 'cannot complete the connection right now; we are on it' ],
			'a gateway error without a body'      => [ 502, '', true, TransientFailure::SERVER, '(502)' ],
			'not found'                           => [ 404, '', true, PermanentFailure::INVALID, 'refused the request (404)' ],
			'an unknown code'                     => [ 400, '{"error":"something_new"' . $leak . '}', true, PermanentFailure::INVALID, 'refused the request (400)' ],
			'a redirect is not followed'          => [ 302, '', true, PermanentFailure::INVALID, 'refused the request (302)' ],
		];
	}

	/**
	 * Review Focus 4: nothing of the body reaches a message.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
	public function test_error_codes_map_to_our_failures( int $status, string $body, bool $refreshing, string $reason, string $words ): void {
		$http     = new RecordedHttp( [ RecordedHttp::answer( $status, $body, [ 'retry-after' => '30' ] ) ] );
		$endpoint = $this->endpoint( $http );

		$e = self::failure( fn() => $refreshing ? $endpoint->refresh( self::app(), 'rt' ) : $endpoint->redeem( self::app(), 'c', self::VERIFIER, self::STATE ) );

		$this->assertSame( $reason, $e->reason );
		$this->assertStringContainsString( $words, $e->getMessage() );
		$this->assertStringNotContainsString( 'LEAK-123', $e->getMessage() );
		$this->assertStringNotContainsString( 'auth.lwplugins.com', $e->getMessage() );
		if ( $e instanceof TransientFailure && in_array( $status, [ 429, 503 ], true ) ) {
			$this->assertSame( 30, $e->retryAfter, 'The relay\'s Retry-After is kept (also on relay_misconfigured).' );
		}
	}

	public function test_a_transport_failure_is_passing(): void {
		$http = new RecordedHttp( [ new FakeWpError( 'cURL error 28: Operation timed out' ) ] );

		$e = self::failure( fn() => $this->endpoint( $http )->refresh( self::app(), 'rt' ) );

		$this->assertInstanceOf( TransientFailure::class, $e );
		$this->assertSame( TransientFailure::NETWORK, $e->reason );
		$this->assertStringNotContainsString( 'cURL', $e->getMessage() );
	}

	public function test_an_oversized_or_unreadable_answer_is_invalid(): void {
		foreach ( [ '{"access_token":"' . str_repeat( 'a', RelayTokenEndpoint::MAX_ANSWER ) . '"}', 'not json', '{"access_token":"a b"}', '{"refresh_token":"r"}' ] as $body ) {
			$http = new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] );

			$e = self::failure( fn() => $this->endpoint( $http )->refresh( self::app(), 'rt' ) );

			$this->assertSame( PermanentFailure::INVALID, $e->reason, substr( $body, 0, 30 ) );
		}
	}

	public function test_redeem_refuses_bad_input_before_sending(): void {
		$http     = new RecordedHttp();
		$endpoint = $this->endpoint( $http );
		foreach ( [ [ '', self::VERIFIER, self::STATE ], [ 'c', 'short', self::STATE ], [ 'c', self::VERIFIER, 'no-dot' ], [ 'c', self::VERIFIER, str_repeat( 'a', RelayTokenEndpoint::MAX_STATE ) . '.b' ] ] as [ $code, $verifier, $state ] ) {
			$this->assertSame( PermanentFailure::INVALID, self::failure( fn() => $endpoint->redeem( self::app(), $code, $verifier, $state ) )->reason );
		}
		$this->assertSame( PermanentFailure::REAUTH, self::failure( fn() => $endpoint->refresh( self::app(), '' ) )->reason );
		$this->assertSame( [], $http->requests );
	}

	public function test_exchange_without_the_relay_state_is_a_programming_error(): void {
		$this->expectException( \LogicException::class );

		$this->endpoint( new RecordedHttp() )->exchange( self::app(), 'c', self::VERIFIER, 'https://site.test/cb' );
	}

	public function test_relay_states_are_two_base64url_parts_of_bounded_length(): void {
		$this->assertSame( 4096, RelayTokenEndpoint::MAX_STATE, 'The relay\'s state.MaxTokenLength.' );
		$this->assertTrue( RelayTokenEndpoint::isRelayState( self::STATE ) );
		$this->assertTrue( RelayTokenEndpoint::isRelayState( str_repeat( 'a', RelayTokenEndpoint::MAX_STATE - 2 ) . '.b' ), 'Exactly the longest.' );
		foreach ( [ '', 'a', 'a.', '.b', 'a.b.c', 'a+b.c', 'a b.c', str_repeat( 'a', RelayTokenEndpoint::MAX_STATE ) . '.b' ] as $bad ) {
			$this->assertFalse( RelayTokenEndpoint::isRelayState( $bad ), $bad );
		}
	}

	public function test_the_test_seam_moves_the_requests(): void {
		Relay::useForTests( 'https://lwm-relay.invalid' );
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"a","expires_in":3600}' ) ] );

		$this->endpoint( $http )->refresh( self::app(), 'rt' );

		$this->assertSame( 'https://lwm-relay.invalid/v1/refresh', $http->requests[0][0] );
		$this->assertSame( [ 'lwm-relay.invalid' ], $this->asked );
	}

	/**
	 * @return array<string, array{0: array<int, string>, 1: bool, 2: mixed, 3: string, 4: string, 5: string}>
	 */
	public static function sendRefusals(): array {
		return [
			'a name that does not resolve' => [ [], true, null, TransientFailure::class, TransientFailure::NETWORK, 'could not be reached' ],
			'a refused network address'    => [ [ '169.254.169.254' ], true, null, PermanentFailure::class, PermanentFailure::INVALID, 'did not send the request to the LW sign-in service' ],
			'a request WordPress blocked'  => [ [ '203.0.113.10' ], false, 'blocked', PermanentFailure::class, PermanentFailure::INVALID, 'did not send the request to the LW sign-in service' ],
			'an answer cURL never saw'     => [ [ '203.0.113.10' ], false, 'answer', PermanentFailure::class, PermanentFailure::INVALID, 'did not send the request to the LW sign-in service' ],
		];
	}

	/**
	 * Ruling R8: GuardedHttp's refusals keep their class and reason, but
	 * never name the relay's host.
	 *
	 * @param array<int, string> $ips     Resolver answer.
	 * @param bool               $curl    Whether the transport fires the cURL hooks.
	 * @param mixed              $answer  null (nothing sent), 'blocked' (a WP_Error) or 'answer' (a 200).
	 * @param string             $class   Failure class.
	 * @param string             $reason  Failure reason.
	 * @param string             $words   Our text.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'sendRefusals' )]
	public function test_refusals_before_sending_never_name_the_relay_host( array $ips, bool $curl, $answer, string $class, string $reason, string $words ): void {
		AddressGuard::useResolver( static fn(): array => $ips );
		$this->curl->curl = $curl;
		$http             = new RecordedHttp( 'blocked' === $answer ? [ new FakeWpError( 'http_request_not_executed: blocked auth.lwplugins.com' ) ] : ( 'answer' === $answer ? [ RecordedHttp::answer( 200, '{"access_token":"a"}' ) ] : [] ) );

		$e = self::failure( fn() => $this->endpoint( $http )->refresh( self::app(), 'rt' ) );

		$this->assertInstanceOf( $class, $e );
		$this->assertSame( $reason, $e->reason );
		$this->assertStringContainsString( $words, $e->getMessage() );
		$this->assertStringNotContainsString( 'auth.lwplugins.com', $e->getMessage() );
		$this->assertStringNotContainsString( 'http_request_not_executed', $e->getMessage() );
		$this->assertSame( null === $answer ? 0 : 1, count( $http->requests ) );
	}

	/**
	 * Review Focus 3: an outage during a scheduled refresh is passing and
	 * keeps the stored tokens for the next run.
	 */
	public function test_a_relay_outage_during_a_refresh_keeps_the_stored_tokens(): void {
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$vault = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$vault->put( 'abcd1234', [ 'refreshToken' => 'rt', 'accessToken' => '1:old', 'grant' => 'g' ] );
		$http   = new RecordedHttp( [ RecordedHttp::answer( 503, '{"error":"relay_misconfigured"}' ) ] );
		$keeper = new TokenKeeper( 'abcd1234', self::app(), (array) $vault->open( 'abcd1234' ), $this->endpoint( $http ), $vault, static fn(): int => self::NOW );

		$e = self::failure( fn() => $keeper->renew() );

		$this->assertInstanceOf( TransientFailure::class, $e );
		$this->assertSame( [ 'refreshToken' => 'rt', 'accessToken' => '1:old', 'grant' => 'g' ], $vault->open( 'abcd1234' ) );
	}
}
