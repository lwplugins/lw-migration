<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\DirectTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Plan Task 3 with preflight P-C2 (every token request through GuardedHttp,
 * hosted-preset mode), P-I1 (HttpErrors classifies) and P-M10 (only a code
 * from a closed list reaches a message).
 */
final class DirectTokenEndpointTest extends MonkeyTestCase {

	private const NOW = 1_800_000_000;

	private const VERIFIER = 'verifier-1-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private CurlHooks $curl;

	/** @var array<int, string> Hosts the resolver was asked for. */
	private array $asked = [];

	/** @var array<int, string> What every name resolves to. */
	private array $ips = [ '142.250.74.10' ];

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver(
			function ( string $host ): array {
				$this->asked[] = $host;
				return $this->ips;
			}
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function endpoint( RecordedHttp $http ): DirectTokenEndpoint {
		return new DirectTokenEndpoint( $this->curl->wrap( $http->send() ), static fn(): int => self::NOW );
	}

	/**
	 * @return array<string, string>
	 */
	private static function form( RecordedHttp $http, int $i = 0 ): array {
		parse_str( (string) $http->requests[ $i ][1]['body'], $form );
		return $form;
	}

	public function test_google_exchange_posts_the_code_verifier_and_secret_and_reads_the_tokens(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"ya29.a","expires_in":3599,"refresh_token":"1//r","scope":"https://www.googleapis.com/auth/drive.file","token_type":"Bearer"}' ) ] );

		$set = $this->endpoint( $http )->exchange( new OAuthApp( Provider::google(), 'cid', 'csecret' ), 'code-1', self::VERIFIER, 'https://site.test/cb' );

		[ $url, $args ] = $http->requests[0];
		$this->assertSame( 'https://oauth2.googleapis.com/token', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( DirectTokenEndpoint::TIMEOUT, $args['timeout'] );
		$this->assertSame( DirectTokenEndpoint::MAX_ANSWER, $args['limit_response_size'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $args['headers']['Content-Type'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame(
			[
				'grant_type'    => 'authorization_code',
				'code'          => 'code-1',
				'redirect_uri'  => 'https://site.test/cb',
				'code_verifier' => self::VERIFIER,
				'client_id'     => 'cid',
				'client_secret' => 'csecret',
			],
			self::form( $http )
		);
		$this->assertSame( 'ya29.a', $set->accessToken );
		$this->assertSame( self::NOW + 3599, $set->expiresAt );
		$this->assertSame( '1//r', $set->refreshToken );
	}

	public function test_the_token_host_is_checked_pinned_and_sent_with_curl_forced(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"sl.b","expires_in":14400}' ) ] );

		$this->endpoint( $http )->refresh( new OAuthApp( Provider::dropbox(), 'key', 'sec' ), 'refresh-1' );

		$this->assertSame( [ 'api.dropboxapi.com' ], $this->asked );
		$this->assertSame( 1, $this->curl->seen, 'One pin hook while the request is sent.' );
		$this->assertSame( 'WpOrg\\Requests\\Transport\\Curl', ltrim( (string) ( $this->curl->options['transport'] ?? '' ), '\\' ) );
		$this->assertTrue( $this->curl->clean() );
	}

	public function test_a_refused_address_sends_nothing(): void {
		$this->ips = [ '169.254.169.254' ];
		$http      = new RecordedHttp();

		try {
			$this->endpoint( $http )->refresh( new OAuthApp( Provider::google(), 'cid', 'the-secret-value' ), 'the-refresh-token' );
			$this->fail( 'A metadata address is refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $http->requests, 'The secret never left.' );
	}

	public function test_an_answer_cURL_never_saw_is_dropped(): void {
		$this->curl->curl = false;
		$http             = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"ya29.a","expires_in":3599}' ) ] );

		$this->expectException( PermanentFailure::class );

		$this->endpoint( $http )->refresh( new OAuthApp( Provider::google(), 'cid', 'sec' ), 'r' );
	}

	public function test_microsoft_repeats_the_scope_and_returns_the_id_token(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"eyA","token_type":"Bearer","expires_in":3600,"refresh_token":"M.r","id_token":"h.p.s"}' ) ] );

		$set = $this->endpoint( $http )->exchange( new OAuthApp( Provider::microsoft(), 'cid', 'sec' ), 'c', self::VERIFIER, 'https://site.test/cb' );

		$this->assertSame( 'https://login.microsoftonline.com/common/oauth2/v2.0/token', $http->requests[0][0] );
		$this->assertSame( Provider::microsoft()->scope(), self::form( $http )['scope'] );
		$this->assertSame( 'h.p.s', $set->idToken );
	}

	public function test_dropbox_refresh_keeps_no_new_refresh_token_and_reads_the_account(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"sl.b","expires_in":14400,"token_type":"bearer","account_id":"dbid:AA"}' ) ] );

		$set = $this->endpoint( $http )->refresh( new OAuthApp( Provider::dropbox(), 'key', 'sec' ), 'refresh-1' );

		$this->assertSame(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'refresh-1',
				'client_id'     => 'key',
				'client_secret' => 'sec',
			],
			self::form( $http )
		);
		$this->assertSame( '', $set->refreshToken, 'No rotation: the stored refresh token stays.' );
		$this->assertSame( 'dbid:AA', $set->accountId );
	}

	public function test_an_app_without_a_secret_sends_none(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '{"access_token":"sl.b","expires_in":14400}' ) ] );

		$this->endpoint( $http )->refresh( new OAuthApp( Provider::dropbox(), 'key', '' ), 'refresh-1' );

		$this->assertArrayNotHasKey( 'client_secret', self::form( $http ) );
	}

	public function test_invalid_grant_on_refresh_is_reauth_and_on_exchange_is_invalid(): void {
		$answer = RecordedHttp::answer( 400, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}' );
		$app    = new OAuthApp( Provider::google(), 'cid', 'sec' );

		try {
			$this->endpoint( new RecordedHttp( [ $answer ] ) )->refresh( $app, 'r' );
			$this->fail( 'A revoked grant fails.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
			$this->assertStringNotContainsString( 'revoked', $e->getMessage() );
		}
		try {
			$this->endpoint( new RecordedHttp( [ $answer ] ) )->exchange( $app, 'c', self::VERIFIER, 'https://site.test/cb' );
			$this->fail( 'A used code fails.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
	}

	public function test_a_refresh_that_needs_the_user_again_is_reauth(): void {
		foreach ( [ 'interaction_required', 'consent_required' ] as $code ) {
			try {
				$this->endpoint( new RecordedHttp( [ RecordedHttp::answer( 400, '{"error":"' . $code . '"}' ) ] ) )->refresh( new OAuthApp( Provider::microsoft(), 'cid', 'sec' ), 'r' );
				$this->fail( 'It fails.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::REAUTH, $e->reason, $code );
			}
		}
	}

	public function test_a_refused_client_is_auth_and_never_repeats_the_secret(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 401, '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret provided."}' ) ] );

		try {
			$this->endpoint( $http )->refresh( new OAuthApp( Provider::microsoft(), 'cid', 'the-secret-value' ), 'the-refresh-token' );
			$this->fail( 'A refused client fails.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
			$this->assertStringNotContainsString( 'the-secret-value', $e->getMessage() );
			$this->assertStringNotContainsString( 'the-refresh-token', $e->getMessage() );
			$this->assertStringNotContainsString( 'AADSTS', $e->getMessage(), 'Provider text is never echoed.' );
		}
	}

	public function test_only_a_known_error_code_reaches_the_message(): void {
		$app = new OAuthApp( Provider::google(), 'cid', 'sec' );
		foreach ( [ [ '{"error":"invalid_scope"}', 'invalid_scope' ], [ '{"error":"made_up_code"}', '(400)' ], [ '{"error":"Bad\nthing"}', '(400)' ], [ 'not json', '(400)' ] ] as [ $body, $shown ] ) {
			try {
				$this->endpoint( new RecordedHttp( [ RecordedHttp::answer( 400, $body ) ] ) )->refresh( $app, 'r' );
				$this->fail( 'It fails.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::AUTH, $e->reason );
				$this->assertStringContainsString( $shown, $e->getMessage() );
				$this->assertStringNotContainsString( 'made_up_code', $e->getMessage() );
			}
		}
	}

	public function test_busy_server_and_network_failures_are_transient(): void {
		$app = new OAuthApp( Provider::dropbox(), 'k', 's' );
		$cases = [
			[ RecordedHttp::answer( 429, '', [ 'Retry-After' => '30' ] ), TransientFailure::BUSY, 30 ],
			[ RecordedHttp::answer( 503 ), TransientFailure::SERVER, null ],
			[ RecordedHttp::answer( 400, '{"error":"temporarily_unavailable"}', [ 'Retry-After' => '5' ] ), TransientFailure::BUSY, 5 ],
			[ new FakeWpError( 'cURL error 28: Operation timed out' ), TransientFailure::NETWORK, null ],
			[ new FakeWpError( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL' ), TransientFailure::NETWORK, null ],
		];
		foreach ( $cases as [ $answer, $reason, $after ] ) {
			try {
				$this->endpoint( new RecordedHttp( [ $answer ] ) )->refresh( $app, 'r' );
				$this->fail( 'It fails.' );
			} catch ( TransientFailure $e ) {
				$this->assertSame( $reason, $e->reason );
				$this->assertSame( $after, $e->retryAfter );
			}
		}
	}

	public function test_a_certificate_problem_and_501_are_permanent(): void {
		$app = new OAuthApp( Provider::google(), 'c', 's' );
		foreach ( [ new FakeWpError( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), RecordedHttp::answer( 501 ), RecordedHttp::answer( 302, '', [ 'Location' => 'https://elsewhere.example/' ] ) ] as $answer ) {
			try {
				$this->endpoint( new RecordedHttp( [ $answer ] ) )->refresh( $app, 'r' );
				$this->fail( 'It fails.' );
			} catch ( CloudFailure $e ) {
				$this->assertInstanceOf( PermanentFailure::class, $e );
				$this->assertStringNotContainsString( 'elsewhere', $e->getMessage() );
			}
		}
	}

	public function test_an_answer_without_an_access_token_is_invalid(): void {
		$this->expectException( PermanentFailure::class );

		$this->endpoint( new RecordedHttp( [ RecordedHttp::answer( 200, '{"token_type":"Bearer"}' ) ] ) )->refresh( new OAuthApp( Provider::google(), 'c', 's' ), 'r' );
	}

	public function test_an_answer_at_the_size_cap_is_invalid(): void {
		$body = '{"access_token":"a","expires_in":3600,"pad":"' . str_repeat( 'x', DirectTokenEndpoint::MAX_ANSWER ) . '"}';
		$body = substr( $body, 0, DirectTokenEndpoint::MAX_ANSWER );

		try {
			$this->endpoint( new RecordedHttp( [ RecordedHttp::answer( 200, $body ) ] ) )->refresh( new OAuthApp( Provider::google(), 'c', 's' ), 'r' );
			$this->fail( 'A cut answer fails.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
	}

	public function test_missing_inputs_are_refused_before_sending(): void {
		$http = new RecordedHttp();
		$cases = [
			[ fn() => $this->endpoint( $http )->refresh( new OAuthApp( Provider::google(), 'cid', 's' ), '' ), PermanentFailure::REAUTH ],
			[ fn() => $this->endpoint( $http )->refresh( new OAuthApp( Provider::google(), '', 's' ), 'r' ), PermanentFailure::AUTH ],
			[ fn() => $this->endpoint( $http )->exchange( new OAuthApp( Provider::google(), 'cid', 's' ), '', self::VERIFIER, 'https://site.test/cb' ), PermanentFailure::INVALID ],
			[ fn() => $this->endpoint( $http )->exchange( new OAuthApp( Provider::google(), 'cid', 's' ), 'c', 'short', 'https://site.test/cb' ), PermanentFailure::INVALID ],
		];
		foreach ( $cases as [ $call, $reason ] ) {
			try {
				$call();
				$this->fail( 'It is refused.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( $reason, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_secret_carrying_parameters_are_marked_sensitive(): void {
		$checks = [
			[ DirectTokenEndpoint::class, 'exchange', [ 'code', 'verifier' ] ],
			[ DirectTokenEndpoint::class, 'refresh', [ 'refreshToken' ] ],
			[ OAuthApp::class, '__construct', [ 'clientSecret' ] ],
			[ TokenSet::class, '__construct', [ 'accessToken', 'refreshToken', 'idToken' ] ],
			[ TokenSet::class, 'fromAnswer', [ 'json' ] ],
		];
		foreach ( $checks as [ $class, $method, $names ] ) {
			foreach ( ( new \ReflectionMethod( $class, $method ) )->getParameters() as $parameter ) {
				if ( in_array( $parameter->getName(), $names, true ) ) {
					$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ), $class . '::' . $method . ' $' . $parameter->getName() );
				}
			}
		}
	}
}
