<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\OAuthApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * M7c Task 7 with preflight P-C1: start() and finish() of a connect; M7d
 * Task 5: the same through the LW sign-in service.
 */
final class OAuthApiTest extends MonkeyTestCase {

	private const CB = 'https://www.example.com/wp-json/lw-migration/v1/oauth/callback'; // Google refuses a .test host.

	private const PAGE = 'https://www.example.com/wp-admin/admin.php?page=lw-migration';

	private string $redirect = self::CB;

	/** @var array<int, array{0: string, 1: string, 2: string}> code, verifier, redirect */
	public array $exchanges = [];

	public int $refreshes = 0;

	/** The type's clock (the callback's is fixed at 1 800 000 000). */
	public int $clock = 1_800_000_000;

	public ?PermanentFailure $exchangeFailure = null;

	public ?TokenSet $answer = null;

	/** fn(): void — runs inside the exchange (another request meanwhile). */
	public ?\Closure $duringExchange = null;

	private Destinations $destinations;

	private SecretVault $vault;

	private FakeOAuthType $type;

	private FakeWpdb $db;

	private ?RecordedHttp $relayHttp = null;

	private ?Destinations $lwDestinations = null;

	private const TOKENS = '{"access_token":"at-1","expires_in":3600,"refresh_token":"rt-1","token_type":"Bearer"}';

	/** The relay's own check of the site state and the challenge (auth-lwplugins-com internal/httpapi/authorize.go). */
	private const RELAY_STATE_RULE = '/^[A-Za-z0-9._~-]{16,256}$/';

	private const RELAY_CHALLENGE_RULE = '/^[A-Za-z0-9_-]{43}$/';

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->db = FakeWpdb::install();
		FakeTransients::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-7' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '&' . http_build_query( $args ) );
		$test     = $this;
		$endpoint = new class( $test ) implements TokenEndpoint {
			public function __construct( private OAuthApiTest $test ) {
			}
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				$this->test->exchanges[] = [ $code, $verifier, $redirectUri ];
				if ( null !== $this->test->duringExchange ) {
					( $this->test->duringExchange )();
				}
				if ( null !== $this->test->exchangeFailure ) {
					throw $this->test->exchangeFailure;
				}
				return $this->test->answer ?? new TokenSet( 'access-' . count( $this->test->exchanges ), 2_000_000_000, 'refresh-' . count( $this->test->exchanges ) );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				++$this->test->refreshes;
				return new TokenSet( 'access-r', 2_000_000_000 );
			}
		};
		$this->vault        = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$this->type         = new FakeOAuthType( $this->vault, $endpoint, null, fn(): int => $this->clock );
		$this->destinations = new Destinations( new ProfileRepository(), $this->vault, new DestinationRegistry( [ $this->type, new S3Type(), new OneDriveType( $this->vault, $endpoint, null, fn(): int => $this->clock ) ] ) );
		foreach ( [ 'aaaa1111', 'bbbb2222' ] as $id ) {
			$this->destinations->profiles()->save( new Profile( $id, 'fakeoauth', 'Drive ' . $id, [ 'clientId' => 'client-1' ] ) );
			$this->vault->put( $id, [ 'clientSecret' => 'secret-1' ] );
		}
		$this->destinations->profiles()->save( new Profile( 'dddd4444', 'onedrive', 'OneDrive', [ 'clientId' => 'client-1', 'tenant' => 'common', 'access' => 'appfolder' ] ) );
		$this->vault->put( 'dddd4444', [ 'clientSecret' => 'secret-1' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		Relay::useForTests( null );
		parent::tearDown();
	}

	private function api(): OAuthApi {
		return new OAuthApi( $this->destinations, new OAuthState( static fn(): int => 1_800_000_000 ), fn(): string => $this->redirect, static fn(): string => self::PAGE, static fn(): int => 1_800_000_000 );
	}

	private function stateOf( string $url ): string {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return (string) $query['state'];
	}

	private function started( string $id = 'aaaa1111', int $user = 7 ): string {
		return $this->stateOf( $this->api()->start( $id, $user )['url'] );
	}

	/**
	 * A first connect of aaaa1111 that succeeded.
	 *
	 * @return array{0: array<string, string>, 1: array<string, int|string>} Secrets and connection after it.
	 */
	private function connected(): array {
		$this->assertStringContainsString( 'lwm-oauth=connected', $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 ) );

		return [ (array) $this->vault->open( 'aaaa1111' ), $this->destinations->find( 'aaaa1111' )->connection ];
	}

	/**
	 * An OAuthApi whose `fakeoauth` type connects profile eeee5555 (LW app)
	 * through a recorded LW sign-in service, and aaaa1111 (own app) directly.
	 *
	 * @param array<int, array<string, mixed>> $answers Relay answers.
	 */
	private function lwApi( array $answers = [] ): OAuthApi {
		if ( null === $this->lwDestinations ) {
			RecordedHttp::install();
			Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
			AddressGuard::useResolver( static fn( string $host ): array => [ '203.0.113.10' ] );
			$curl                 = ( new CurlHooks() )->install();
			$this->relayHttp      = new RecordedHttp( $answers );
			$relay                = new RelayTokenEndpoint( $curl->wrap( $this->relayHttp->send() ), static fn(): int => 1_800_000_000 );
			$type                 = new FakeOAuthType( $this->vault, $this->type->endpoint(), null, fn(): int => $this->clock, $relay );
			$this->lwDestinations = new Destinations( new ProfileRepository(), $this->vault, new DestinationRegistry( [ $type ] ) );
			$this->lwDestinations->profiles()->save( new Profile( 'eeee5555', 'fakeoauth', 'LW Drive', [ OAuthMode::OWN_APP => false ] ) );
		}

		return new OAuthApi( $this->lwDestinations, new OAuthState( static fn(): int => 1_800_000_000 ), fn(): string => $this->redirect, static fn(): string => self::PAGE, static fn(): int => 1_800_000_000 );
	}

	/**
	 * @return array<string, string> The query of a start()'s address.
	 */
	private static function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	private static function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		self::fail( 'Refused.' );
	}

	/**
	 * Issue #12: the connect asks the provider straight which account the
	 * new access token belongs to and records it with the tokens (sealed),
	 * never in the connection.
	 */
	public function test_a_connect_records_the_account_the_provider_names(): void {
		[ $secrets, $connection ] = $this->connected();

		$this->assertSame( 'google:fake-account', $secrets['identity'] );
		$this->assertSame( [ 'access-1' ], $this->type->identified, 'Asked with the exchanged access token.' );
		$this->assertArrayNotHasKey( 'identity', $connection );
	}

	public function test_a_reconnect_records_the_new_account(): void {
		$this->connected();
		$this->type->identity = 'google:other';

		$this->assertSame( 'google:other', $this->connected()[0]['identity'] );
	}

	public function test_a_connect_whose_account_the_provider_does_not_confirm_stores_nothing(): void {
		$this->type->identifyFailure = new PermanentFailure( 'No account.', PermanentFailure::INVALID );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertArrayNotHasKey( 'refreshToken', (array) $this->vault->open( 'aaaa1111' ) );
	}

	/**
	 * Issue #12: through the LW app every refreshed access token is asked at
	 * the provider before it is kept; an own-app refresh makes no extra call.
	 */
	public function test_only_an_lw_app_refresh_is_asked_at_the_provider(): void {
		$api   = $this->lwApi( [ RecordedHttp::answer( 200, self::TOKENS ), RecordedHttp::answer( 200, '{"access_token":"at-2","expires_in":3600,"token_type":"Bearer"}' ) ] );
		$query = self::query( $api->start( 'eeee5555', 7 )['url'] );
		$api->finish( [ 'lwm_relay' => '1', 'code' => 'code-1', 'state' => $query['state'], 'relay_state' => 'cGF5bG9hZA.bWFj' ], 7 );
		$lwType = $this->lwDestinations->registry()->get( 'fakeoauth' );
		$this->assertInstanceOf( FakeOAuthType::class, $lwType );

		$this->assertSame( 'at-2', $lwType->http( $this->lwDestinations->find( 'eeee5555' ), (array) $this->vault->open( 'eeee5555' ) )->tokens()->renew() );
		$this->assertSame( [ 'at-1', 'at-2' ], $lwType->identified );

		$this->connected();
		$this->assertSame( 'access-r', $this->type->http( $this->destinations->find( 'aaaa1111' ), (array) $this->vault->open( 'aaaa1111' ) )->tokens()->renew() );
		$this->assertSame( [ 'access-1' ], $this->type->identified, 'Own app: only the connect asked.' );
	}

	public function test_an_lw_app_refresh_for_another_account_stores_nothing(): void {
		$api   = $this->lwApi( [ RecordedHttp::answer( 200, self::TOKENS ), RecordedHttp::answer( 200, '{"access_token":"at-2","expires_in":3600,"refresh_token":"rt-2","token_type":"Bearer"}' ) ] );
		$query = self::query( $api->start( 'eeee5555', 7 )['url'] );
		$api->finish( [ 'lwm_relay' => '1', 'code' => 'code-1', 'state' => $query['state'], 'relay_state' => 'cGF5bG9hZA.bWFj' ], 7 );
		$lwType = $this->lwDestinations->registry()->get( 'fakeoauth' );
		$this->assertInstanceOf( FakeOAuthType::class, $lwType );
		$lwType->identity = 'google:attacker';
		$before           = $this->vault->open( 'eeee5555' );

		try {
			$lwType->http( $this->lwDestinations->find( 'eeee5555' ), (array) $before )->tokens()->renew();
			$this->fail( 'Another account is refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		$this->assertSame( $before, $this->vault->open( 'eeee5555' ) );
	}

	public function test_connect_returns_the_authorize_url_with_state_and_challenge(): void {
		$url = $this->api()->start( 'aaaa1111', 7 )['url'];

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		$this->assertSame( 'client-1', $query['client_id'] );
		$this->assertSame( self::CB, $query['redirect_uri'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $query['state'] );
		$this->assertSame( 43, strlen( $query['code_challenge'] ) );
	}

	/**
	 * Review Focus 5.
	 */
	public function test_connect_refuses_an_address_the_provider_rejects(): void {
		$this->redirect = 'http://www.example.com/wp-json/lw-migration/v1/oauth/callback';

		$e = self::refusal( fn() => $this->api()->start( 'aaaa1111', 7 ) );

		$this->assertSame( [ 'lw_migration_redirect_refused', 422, 'https' ], [ $e->errorCode, $e->status, $e->data['reason'] ] );
		$this->assertSame( [], FakeTransients::$store, 'No state is issued for an address that cannot work.' );
	}

	public function test_connect_needs_a_stored_client_secret(): void {
		$this->vault->forget( 'aaaa1111' );

		$e = self::refusal( fn() => $this->api()->start( 'aaaa1111', 7 ) );

		$this->assertSame( [ 'lw_migration_invalid', 400, 'clientSecret' ], [ $e->errorCode, $e->status, $e->data['field'] ] );
		$this->assertSame( [], FakeTransients::$store );
	}

	public function test_connect_answers_404_for_a_missing_or_malformed_profile(): void {
		foreach ( [ 'cccc3333', 'AAAA1111', '../x' ] as $id ) {
			$e = self::refusal( fn() => $this->api()->start( $id, 7 ) );

			$this->assertSame( [ 'lw_migration_destination_missing', 404 ], [ $e->errorCode, $e->status ], $id );
		}
	}

	public function test_connect_refuses_a_profile_without_an_account(): void {
		$this->destinations->profiles()->save( new Profile( 'cccc3333', 's3', 'Bucket', [] ) );

		$e = self::refusal( fn() => $this->api()->start( 'cccc3333', 7 ) );

		$this->assertSame( [ 'lw_migration_invalid', 400, 'type' ], [ $e->errorCode, $e->status, $e->data['field'] ] );
	}

	public function test_connect_answers_409_when_the_state_cannot_be_stored(): void {
		Functions\when( 'set_transient' )->justReturn( false );

		$e = self::refusal( fn() => $this->api()->start( 'aaaa1111', 7 ) );

		$this->assertSame( [ 'lw_migration_oauth_unavailable', 409 ], [ $e->errorCode, $e->status ] );
	}

	public function test_the_callback_stores_tokens_sealed_and_the_connection(): void {
		$state = $this->started();

		$back = $this->api()->finish( [ 'code' => 'code-1', 'state' => $state ], 7 );

		$this->assertSame( self::PAGE . '&lwm-oauth=connected&lwm-destination=aaaa1111#destinations', $back );
		$this->assertSame( 'code-1', $this->exchanges[0][0] );
		$this->assertSame( self::CB, $this->exchanges[0][2], 'The recorded redirect address (O3).' );
		$secrets = $this->vault->open( 'aaaa1111' );
		$this->assertSame( 'refresh-1', $secrets[ TokenKeeper::REFRESH ] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $secrets[ TokenKeeper::GRANT ], 'Committed under a grant.' );
		$this->assertStringNotContainsString( 'refresh-1', (string) json_encode( $this->db->rows ), 'Sealed, never plain in a row.' );
		$profile = $this->destinations->find( 'aaaa1111' );
		$this->assertSame( 'admin@example.com', $profile->connection['account'] );
		$this->assertSame( self::CB, $profile->connection['redirectUri'] );
		$this->assertSame( ( new OAuthState() )->clientMark( 'client-1' ), $profile->connection['client'] );
		$this->assertSame( TokenKeeper::connectionId( $secrets[ TokenKeeper::GRANT ] ), $profile->connection['id'], 'The connection is the vault generation (review I1).' );
		$this->assertSame( [ 'client', 'id', 'connectedAt', 'redirectUri', 'account' ], array_keys( $profile->connection ), 'The fixed keys first (review m1).' );
		$this->assertSame( Profile::OK, $profile->status, 'The account check proved the token.' );
		$this->assertSame( '', $profile->message );
		$this->assertSame( 0, $this->refreshes );
	}

	/**
	 * Task 6 m3: every connection is distinct, even with the same account in the same second.
	 */
	public function test_every_connection_gets_its_own_id(): void {
		[ , $first ] = $this->connected();
		[ , $second ] = $this->connected();

		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertSame( array_diff_key( $first, [ 'id' => 0 ] ), array_diff_key( $second, [ 'id' => 0 ] ) );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_a_replayed_callback_exchanges_nothing(): void {
		$state = $this->started();
		$this->api()->finish( [ 'code' => 'code-1', 'state' => $state ], 7 );

		$back = $this->api()->finish( [ 'code' => 'code-1', 'state' => $state ], 7 );

		$this->assertSame( self::PAGE . '&lwm-oauth=expired#destinations', $back, 'No profile id without a state.' );
		$this->assertCount( 1, $this->exchanges );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_the_callback_writes_only_the_profile_of_the_state(): void {
		$state = $this->started();

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state, 'lwm-destination' => 'bbbb2222', 'did' => 'bbbb2222', 'profile' => 'bbbb2222' ], 7 );

		$this->assertStringContainsString( 'lwm-destination=aaaa1111', $back );
		$this->assertArrayHasKey( TokenKeeper::REFRESH, $this->vault->open( 'aaaa1111' ) );
		$this->assertArrayNotHasKey( TokenKeeper::REFRESH, $this->vault->open( 'bbbb2222' ) );
		$this->assertSame( [], $this->destinations->find( 'bbbb2222' )->connection );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_another_users_callback_is_refused_without_an_exchange(): void {
		$state = $this->started();

		$this->assertStringContainsString( 'lwm-oauth=expired', $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 8 ) );
		$this->assertStringContainsString( 'lwm-oauth=expired', $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 ), 'Used up by the refused try.' );
		$this->assertSame( [], $this->exchanges );
	}

	public function test_a_callback_without_a_login_user_or_with_a_malformed_state_exchanges_nothing(): void {
		$state = $this->started();

		foreach ( [ [ [ 'code' => 'c', 'state' => $state ], 0 ], [ [ 'code' => 'c', 'state' => [ $state ] ], 7 ], [ [ 'code' => 'c' ], 7 ] ] as [ $query, $user ] ) {
			$this->assertStringContainsString( 'lwm-oauth=expired', $this->api()->finish( $query, $user ) );
		}
		$this->assertSame( [], $this->exchanges );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_a_changed_client_id_refuses_the_callback(): void {
		$state   = $this->started();
		$profile = $this->destinations->find( 'aaaa1111' );
		$profile->settings['clientId'] = 'client-2';
		$this->destinations->profiles()->save( $profile );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=refused', $back );
		$this->assertSame( [], $this->exchanges );
		$this->assertArrayNotHasKey( TokenKeeper::REFRESH, $this->vault->open( 'aaaa1111' ) );
	}

	/**
	 * Task 10 review I1: a connect started under one OneDrive tenant or
	 * permission is refused at the callback once either changed (the state
	 * binds them with the Client ID): no exchange, no token.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function grantChanges(): array {
		return [
			'another permission' => [ [ 'access' => 'files' ] ],
			'another tenant'     => [ [ 'tenant' => 'organizations' ] ],
		];
	}

	/**
	 * @param array<string, string> $change Settings changed after the connect started.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'grantChanges' )]
	public function test_a_onedrive_grant_change_after_the_connect_started_refuses_the_callback( array $change ): void {
		$state             = $this->started( 'dddd4444' );
		$profile           = $this->destinations->find( 'dddd4444' );
		$profile->settings = $change + $profile->settings;
		$this->destinations->profiles()->save( $profile );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=refused', $back );
		$this->assertSame( [], $this->exchanges );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'dddd4444' ) );
		$this->assertSame( [], $this->destinations->find( 'dddd4444' )->connection );
		$this->assertSame( Profile::ERROR, $this->destinations->find( 'dddd4444' )->status );
	}

	/**
	 * Task 10 review I1: a grant setting changed during the exchange — the
	 * connection CAS refuses it like a changed Client ID; nothing is stored.
	 */
	public function test_a_grant_changed_during_the_exchange_stores_nothing(): void {
		$state                = $this->started();
		$this->duringExchange = function (): void {
			$profile                    = $this->destinations->find( 'aaaa1111' );
			$profile->settings['grant'] = 'other';
			$this->destinations->profiles()->replace( $profile, true, true );
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=refused', $back );
		$this->assertSame( [], $this->destinations->find( 'aaaa1111' )->connection );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
	}

	/**
	 * Task 10 review I1: tokens of a connection made under other grant
	 * settings are never used (connection.client binds them too), while an
	 * unchanged profile keeps working.
	 */
	public function test_a_connection_of_other_grant_settings_is_never_used(): void {
		[ $secrets ] = $this->connected();
		$this->type->make( $this->destinations->find( 'aaaa1111' ), $secrets );
		$this->assertNotNull( $this->type->made, 'Unchanged: usable.' );
		$profile                    = $this->destinations->find( 'aaaa1111' );
		$profile->settings['grant'] = 'other';

		try {
			$this->type->make( $profile, $secrets );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
	}

	public function test_a_denied_consent_keeps_the_profile_and_says_denied(): void {
		$state = $this->started();

		$back = $this->api()->finish( [ 'error' => 'access_denied', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=denied', $back );
		$this->assertSame( [], $this->exchanges );
		$this->assertSame( Profile::NEW, $this->destinations->find( 'aaaa1111' )->status );
	}

	/**
	 * O6: the provider's words never reach the page or the profile.
	 */
	public function test_a_provider_error_is_failed_and_its_text_never_shown(): void {
		$state = $this->started();

		$back = $this->api()->finish( [ 'error' => 'server_error', 'error_description' => 'Evil <script>', 'code' => 'c', 'state' => $state ], 7 );

		$this->assertSame( self::PAGE . '&lwm-oauth=failed&lwm-destination=aaaa1111#destinations', $back );
		$this->assertSame( [], $this->exchanges );
		$this->assertStringNotContainsString( 'Evil', $this->destinations->find( 'aaaa1111' )->message );
	}

	public function test_a_missing_or_overlong_code_fails_without_an_exchange(): void {
		foreach ( [ '', str_repeat( 'c', 4097 ) ] as $code ) {
			$this->assertStringContainsString( 'lwm-oauth=failed', $this->api()->finish( [ 'code' => $code, 'state' => $this->started() ], 7 ) );
		}
		$this->assertSame( [], $this->exchanges );
	}

	public function test_a_failed_exchange_marks_the_profile_and_stores_no_token(): void {
		$this->exchangeFailure = new PermanentFailure( 'The sign-in at Google Drive could not be completed.', PermanentFailure::INVALID );
		$state                 = $this->started();

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( Profile::ERROR, $this->destinations->find( 'aaaa1111' )->status );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
	}

	public function test_an_answer_without_a_refresh_token_stores_nothing(): void {
		$this->answer = new TokenSet( 'access-x', 2_000_000_000 );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
		$this->assertSame( [], $this->destinations->find( 'aaaa1111' )->connection );
	}

	/**
	 * P-C1 (replaces the plan's "a failed connect step drops the fresh
	 * tokens"): a failed account check stores nothing and drops nothing.
	 */
	public function test_a_failed_reconnect_keeps_the_working_tokens_and_connection(): void {
		[ $secrets, $connection ] = $this->connected();
		$this->type->connectFailure = new PermanentFailure( 'Google Drive refused the request (no permission).', PermanentFailure::DENIED );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ), 'The old tokens and grant stay.' );
		$this->assertSame( $connection, $this->destinations->find( 'aaaa1111' )->connection );
		$this->assertStringContainsString( 'no permission', $this->destinations->find( 'aaaa1111' )->message );
	}

	/**
	 * M7c final review m1: a destination that waits for a new sign-in keeps
	 * waiting for it after a failed reconnect (so the card still offers
	 * Reconnect); only the message changes.
	 */
	public function test_a_failed_reconnect_of_a_revoked_connection_keeps_it_waiting_for_a_new_sign_in(): void {
		$this->connected();
		$this->destinations->profiles()->mark( 'aaaa1111', Profile::REAUTH, 'Connect the account again.' );
		$this->type->connectFailure = new PermanentFailure( 'Google Drive refused the request (no permission).', PermanentFailure::DENIED );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$profile = $this->destinations->find( 'aaaa1111' );
		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( Profile::REAUTH, $profile->status );
		$this->assertStringContainsString( 'no permission', $profile->message );
	}

	public function test_a_reconnect_of_a_destination_waiting_for_a_new_sign_in_is_ok(): void {
		$this->connected();
		$this->destinations->profiles()->mark( 'aaaa1111', Profile::REAUTH, 'Connect the account again.' );

		$this->connected();

		$profile = $this->destinations->find( 'aaaa1111' );
		$this->assertSame( Profile::OK, $profile->status );
		$this->assertSame( '', $profile->message );
	}

	public function test_a_connect_without_a_confirmed_account_keeps_the_previous_status(): void {
		$this->connected();
		$this->destinations->profiles()->mark( 'aaaa1111', Profile::ERROR, 'Earlier failure.' );
		$this->type->identifyFailure = new PermanentFailure( 'No account.', PermanentFailure::INVALID );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( Profile::ERROR, $this->destinations->find( 'aaaa1111' )->status );
	}

	public function test_a_denied_reconnect_keeps_the_working_connection(): void {
		[ $secrets, $connection ] = $this->connected();

		$back = $this->api()->finish( [ 'error' => 'access_denied', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=denied', $back );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ) );
		$this->assertSame( $connection, $this->destinations->find( 'aaaa1111' )->connection );
	}

	/**
	 * P-C1: the account check holds the fresh tokens in memory only — a 401
	 * there (a new access token needed) fails the connect without a refresh.
	 */
	public function test_the_account_check_never_refreshes_or_stores_a_token(): void {
		[ $secrets ] = $this->connected();
		$this->type->onConnect = static function ( OAuthHttp $http ): void {
			$http->tokens()->renew();
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( 0, $this->refreshes, 'No refresh request.' );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ) );
		$this->assertStringContainsString( 'Choose Connect again', $this->destinations->find( 'aaaa1111' )->message );
	}

	public function test_the_account_check_keeper_cannot_write(): void {
		[ $secrets ] = $this->connected();
		$caught      = null;
		$this->type->onConnect = static function ( OAuthHttp $http ) use ( &$caught ): void {
			try {
				$http->tokens()->store( new TokenSet( 'access-z', 2_000_000_000, 'refresh-z' ) );
			} catch ( TransientFailure $e ) {
				$caught = $e;
				throw $e;
			}
		};

		$this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertInstanceOf( TransientFailure::class, $caught );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ) );
	}

	public function test_the_account_check_uses_the_fresh_access_token(): void {
		$seen                  = null;
		$this->type->onConnect = static function ( OAuthHttp $http ) use ( &$seen ): void {
			$seen = $http->tokens()->token();
		};

		$this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertSame( 'access-1', $seen );
	}

	/**
	 * P-C1: a profile deleted while the code was exchanged stays deleted,
	 * its secrets too.
	 */
	public function test_a_profile_deleted_during_the_exchange_stays_deleted(): void {
		$state                = $this->started();
		$this->duringExchange = fn() => $this->destinations->forget( 'aaaa1111' );

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertSame( self::PAGE . '&lwm-oauth=failed&lwm-destination=aaaa1111#destinations', $back );
		$this->assertNull( $this->destinations->find( 'aaaa1111' ) );
		$this->assertSame( [], $this->vault->fields( 'aaaa1111' ) );
	}

	/**
	 * P-C1 / O9: a Client ID changed while the code was exchanged gets no connection and no token.
	 */
	public function test_a_client_id_changed_during_the_exchange_stores_nothing(): void {
		$state                = $this->started();
		$this->duringExchange = function (): void {
			$profile                       = $this->destinations->find( 'aaaa1111' );
			$profile->settings['clientId'] = 'client-2';
			$this->destinations->profiles()->replace( $profile, true, true );
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $state ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=refused', $back );
		$this->assertSame( [], $this->destinations->find( 'aaaa1111' )->connection );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
	}

	/**
	 * Review I1: the token write fails after the connection was stored — the
	 * new connection and the old tokens are two generations, so nothing is
	 * sent with them; the profile asks for a new connect.
	 */
	public function test_a_failed_token_write_after_the_connection_fails_closed(): void {
		[ $secrets ]           = $this->connected();
		$this->type->onConnect = function (): void {
			$this->db->failOn = Options::DESTINATION_SECRETS;
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->db->failOn = null;
		$profile          = $this->destinations->find( 'aaaa1111' );
		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ), 'The old tokens were not touched.' );
		$this->assertSame( Profile::ERROR, $profile->status );
		$this->assertStringContainsString( 'Choose Connect again', $profile->message );
		try {
			$this->destinations->open( $profile );
			$this->fail( 'Never made with the other generation\'s tokens.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::BUSY, $e->reason, 'Right after the connect: it may still be between its writes.' );
		}
		$this->clock += 300;
		try {
			$this->destinations->open( $profile );
			$this->fail( 'Never made with the other generation\'s tokens.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason, 'Round 2: later the run ends and asks for a new connect.' );
			$this->assertStringContainsString( 'Choose Connect again', $e->getMessage() );
		}
		$this->assertNull( $this->type->made, 'No client, so no request.' );
	}

	/**
	 * Review I1: a job that reads the profile between the connection write
	 * and the token write waits instead of using account A's tokens for B.
	 */
	public function test_a_reader_between_the_two_writes_waits(): void {
		$this->connected();
		$seen                  = null;
		$this->type->onConnect = function () use ( &$seen ): void {
			$this->db->beforeWrite = function () use ( &$seen ): void { // Before the connection write.
				$this->db->beforeWrite = function () use ( &$seen ): void { // Before the token write.
					try {
						$this->destinations->open( $this->destinations->find( 'aaaa1111' ) );
						$seen = 'made';
					} catch ( TransientFailure $e ) {
						$seen = $e->reason;
					}
				};
			};
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=connected', $back );
		$this->assertSame( TransientFailure::BUSY, $seen );
	}

	/**
	 * Round 2: a first connect read between its two writes waits (BUSY) — it
	 * is not "not connected yet" (REAUTH would mark the profile `reauth`,
	 * and only that path reaches the reconnect mail).
	 */
	public function test_a_first_connect_reader_between_the_two_writes_waits(): void {
		$seen                  = null;
		$this->type->onConnect = function () use ( &$seen ): void {
			$this->db->beforeWrite = function () use ( &$seen ): void {
				$this->db->beforeWrite = function () use ( &$seen ): void {
					try {
						$this->destinations->open( $this->destinations->find( 'aaaa1111' ) );
						$seen = 'made';
					} catch ( \LightweightPlugins\Migration\Storage\Destinations\CloudFailure $e ) {
						$seen = $e->reason;
					}
				};
			};
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=connected', $back );
		$this->assertSame( TransientFailure::BUSY, $seen );
		$this->assertSame( Profile::OK, $this->destinations->find( 'aaaa1111' )->status );
	}

	public function test_a_reconnect_is_used_with_its_own_tokens(): void {
		$this->connected();
		$this->connected();

		$this->destinations->open( $this->destinations->find( 'aaaa1111' ) );

		$this->assertSame( 'access-2', $this->type->made->tokens()->token() );
	}

	/**
	 * Review m2: the connection row cannot be written — our failure, the
	 * working connection stays.
	 */
	public function test_a_failed_connection_write_keeps_the_working_connection(): void {
		[ $secrets, $connection ] = $this->connected();
		$this->type->onConnect    = function (): void {
			$this->db->failOn = Options::DESTINATIONS;
		};

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$this->db->failOn = null;
		$this->assertSame( self::PAGE . '&lwm-oauth=failed&lwm-destination=aaaa1111#destinations', $back );
		$this->assertSame( $secrets, $this->vault->open( 'aaaa1111' ) );
		$this->assertSame( $connection, $this->destinations->find( 'aaaa1111' )->connection );
	}

	/**
	 * Review m1: an account label too long to keep is refused with our text, not dropped.
	 */
	public function test_an_over_long_account_label_is_refused_with_our_text(): void {
		$this->type->connected = [ 'account' => str_repeat( 'a', 3000 ) ];

		$back = $this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );

		$profile = $this->destinations->find( 'aaaa1111' );
		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertSame( [], $profile->connection );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
		$this->assertStringContainsString( 'too long', $profile->message );
		$this->assertStringNotContainsString( 'aaaa', $profile->message );
	}

	/**
	 * Review m1: a type that answers reserved, malformed or too many entries is a bug, refused before any write.
	 */
	public function test_reserved_malformed_or_too_many_entries_are_refused(): void {
		$six = [];
		foreach ( [ 'account', 'folderId', 'b', 'c', 'd', 'e' ] as $key ) {
			$six[ $key ] = 'x';
		}
		foreach ( [ [ 'client' => 'x' ], [ 'id' => 'x' ], [ 'noticed' => 1 ], [ 'connectedAt' => 1 ], [ 'bad key' => 'x' ], [ 'account' => [ 'x' ] ], [ 'folderId' => str_repeat( 'f', 3000 ) ], $six ] as $i => $extra ) {
			$this->type->connected = $extra;
			try {
				$this->api()->finish( [ 'code' => 'c', 'state' => $this->started() ], 7 );
				$this->fail( 'Refused: ' . $i );
			} catch ( \LogicException $e ) {
				$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ), (string) $i );
				$this->assertSame( [], $this->destinations->find( 'aaaa1111' )->connection, (string) $i );
			}
		}
	}

	public function test_the_redirect_address_carries_only_fixed_codes_and_a_profile_id(): void {
		$this->assertSame( self::PAGE . '&lwm-oauth=failed#destinations', OAuthApi::address( self::PAGE, OAuthApi::FAILED, 'https://evil.example' ) );
		$this->assertSame( self::PAGE . '&lwm-oauth=denied&lwm-destination=aaaa1111#destinations', OAuthApi::address( self::PAGE, OAuthApi::DENIED, 'aaaa1111' ) );
	}

	public function test_lw_connect_goes_to_the_relay_with_the_site_state_and_challenge(): void {
		$url   = $this->lwApi()->start( 'eeee5555', 7 )['url'];
		$query = self::query( $url );

		$this->assertStringStartsWith( 'https://auth.lwplugins.com/v1/authorize?', $url );
		$this->assertSame( [ 'v', 'provider', 'return', 'state', 'code_challenge', 'code_challenge_method' ], array_keys( $query ) );
		$this->assertSame( [ '1', 'google', self::CB, 'S256' ], [ $query['v'], $query['provider'], $query['return'], $query['code_challenge_method'] ] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $query['state'] );
		$this->assertMatchesRegularExpression( self::RELAY_STATE_RULE, $query['state'], 'The relay accepts the site state.' );
		$this->assertMatchesRegularExpression( self::RELAY_CHALLENGE_RULE, $query['code_challenge'], 'The relay accepts the challenge.' );
		$this->assertStringNotContainsString( 'client', $url );
		$this->assertSame( [ 'relayAnchor' ], $this->vault->fields( 'eeee5555' ), 'A secrets row for the token write.' );
	}

	public function test_lw_connect_sends_the_address_as_the_relay_will_use_it(): void {
		$this->redirect = 'https://WWW.Example.COM:443/wp-json/lw-migration/v1/oauth/callback';

		$query = self::query( $this->lwApi()->start( 'eeee5555', 7 )['url'] );

		$this->assertSame( self::CB, $query['return'] );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_lw_connect_refuses_an_address_the_relay_rejects(): void {
		$this->redirect = 'https://www.example.com/api/lw-migration/v1/oauth/callback';

		$e = self::refusal( fn() => $this->lwApi()->start( 'eeee5555', 7 ) );

		$this->assertSame( [ 'lw_migration_redirect_refused', 422, 'route' ], [ $e->errorCode, $e->status, $e->data['reason'] ] );
		$this->assertStringNotContainsString( 'example.com', $e->getMessage() );
		$this->assertSame( [], FakeTransients::$store, 'No state is issued.' );
		$this->assertSame( [], $this->vault->fields( 'eeee5555' ) );
	}

	public function test_lw_connect_needs_no_client_id_or_secret_but_an_own_app_still_does(): void {
		$api = $this->lwApi();
		$this->lwDestinations->profiles()->save( new Profile( 'ffff6666', 'fakeoauth', 'Own Drive', [ OAuthMode::OWN_APP => true ] ) );

		$e = self::refusal( fn() => $api->start( 'ffff6666', 7 ) );

		$this->assertSame( [ 'lw_migration_invalid', 400, 'clientId' ], [ $e->errorCode, $e->status, $e->data['field'] ] );
		$this->assertSame( [], $this->vault->fields( 'ffff6666' ) );
	}

	public function test_a_relayed_callback_redeems_the_code_through_the_relay_and_connects(): void {
		$api   = $this->lwApi( [ RecordedHttp::answer( 200, self::TOKENS ) ] );
		$query = self::query( $api->start( 'eeee5555', 7 )['url'] );

		$back = $api->finish( [ 'lwm_relay' => '1', 'code' => 'code-1', 'state' => $query['state'], 'relay_state' => 'cGF5bG9hZA.bWFj' ], 7 );

		$this->assertSame( self::PAGE . '&lwm-oauth=connected&lwm-destination=eeee5555#destinations', $back );
		$body = (array) json_decode( (string) $this->relayHttp->requests[0][1]['body'], true );
		$this->assertSame( 'https://auth.lwplugins.com/v1/token', $this->relayHttp->requests[0][0] );
		$this->assertSame( [ 'v', 'provider', 'code', 'code_verifier', 'relay_state' ], array_keys( $body ) );
		$this->assertSame( [ 1, 'google', 'code-1', 'cGF5bG9hZA.bWFj' ], [ $body['v'], $body['provider'], $body['code'], $body['relay_state'] ] );
		$this->assertSame( $query['code_challenge'], Pkce::challenge( (string) $body['code_verifier'] ), 'The verifier of the challenge sent away.' );
		$secrets = (array) $this->vault->open( 'eeee5555' );
		$this->assertSame( 'rt-1', $secrets['refreshToken'] );
		$this->assertArrayNotHasKey( 'clientSecret', $secrets );
		$this->assertSame( ( new OAuthState() )->clientMark( OAuthMode::relayKey( 'google' ) ), $this->lwDestinations->find( 'eeee5555' )->connection['client'] );
		$this->assertSame( [], $this->exchanges, 'The direct endpoint is never used.' );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_relayed_and_direct_answers_never_cross(): void {
		$api = $this->lwApi();
		$own = self::query( $api->start( 'aaaa1111', 7 )['url'] );
		$lw  = self::query( $api->start( 'eeee5555', 7 )['url'] );

		$ownBack = $api->finish( [ 'lwm_relay' => '1', 'code' => 'c', 'state' => $own['state'], 'relay_state' => 'a.b' ], 7 );
		$lwBack  = $api->finish( [ 'code' => 'c', 'state' => $lw['state'] ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=failed&lwm-destination=aaaa1111', $ownBack );
		$this->assertStringContainsString( 'lwm-oauth=failed&lwm-destination=eeee5555', $lwBack );
		$this->assertSame( [], $this->exchanges );
		$this->assertSame( [], $this->relayHttp->requests );
		$this->assertStringContainsString( 'lwm-oauth=expired', $api->finish( [ 'code' => 'c', 'state' => $lw['state'] ], 7 ), 'The state was used up.' );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_a_relayed_callback_without_a_usable_relay_state_exchanges_nothing(): void {
		$api = $this->lwApi();
		foreach ( [ null, '', 'nodot', 'a.b.c', str_repeat( 'a', RelayTokenEndpoint::MAX_STATE ) . '.b', [ 'a.b' ] ] as $relayState ) {
			$query = self::query( $api->start( 'eeee5555', 7 )['url'] );
			$sent  = [ 'lwm_relay' => '1', 'code' => 'c', 'state' => $query['state'] ] + ( null === $relayState ? [] : [ 'relay_state' => $relayState ] );

			$this->assertStringContainsString( 'lwm-oauth=failed', $api->finish( $sent, 7 ) );
		}
		$this->assertSame( [], $this->relayHttp->requests );
		$this->assertArrayNotHasKey( 'refreshToken', (array) $this->vault->open( 'eeee5555' ) );
	}

	public function test_a_relayed_denial_is_denied_and_a_relayed_provider_error_failed(): void {
		$api    = $this->lwApi();
		$denied = self::query( $api->start( 'eeee5555', 7 )['url'] );
		$broken = self::query( $api->start( 'eeee5555', 7 )['url'] );

		$this->assertStringContainsString( 'lwm-oauth=denied', $api->finish( [ 'lwm_relay' => '1', 'error' => 'access_denied', 'state' => $denied['state'] ], 7 ) );
		$this->assertStringContainsString( 'lwm-oauth=failed', $api->finish( [ 'lwm_relay' => '1', 'error' => 'provider_error', 'state' => $broken['state'] ], 7 ) );
		$this->assertSame( [], $this->relayHttp->requests );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_a_mode_switch_between_connect_and_callback_is_refused(): void {
		$api   = $this->lwApi();
		$query = self::query( $api->start( 'eeee5555', 7 )['url'] );
		$this->lwDestinations->profiles()->replace( new Profile( 'eeee5555', 'fakeoauth', 'LW Drive', [ OAuthMode::OWN_APP => true, 'clientId' => 'client-1' ] ), true );

		$back = $api->finish( [ 'lwm_relay' => '1', 'code' => 'c', 'state' => $query['state'], 'relay_state' => 'a.b' ], 7 );

		$this->assertStringContainsString( 'lwm-oauth=refused', $back );
		$this->assertSame( [], $this->relayHttp->requests );
	}

	public function test_a_misconfigured_relay_during_the_connect_keeps_the_old_connection_and_says_so(): void {
		$api   = $this->lwApi( [ RecordedHttp::answer( 503, '{"error":"relay_misconfigured","error_description":"LEAK-9"}' ) ] );
		$query = self::query( $api->start( 'eeee5555', 7 )['url'] );

		$back = $api->finish( [ 'lwm_relay' => '1', 'code' => 'c', 'state' => $query['state'], 'relay_state' => 'a.b' ], 7 );

		$profile = $this->lwDestinations->find( 'eeee5555' );
		$this->assertStringContainsString( 'lwm-oauth=failed', $back );
		$this->assertStringContainsString( 'LW sign-in service cannot complete the connection', $profile->message );
		$this->assertStringNotContainsString( 'LEAK-9', $profile->message );
		$this->assertSame( [], $profile->connection );
		$this->assertArrayNotHasKey( 'refreshToken', (array) $this->vault->open( 'eeee5555' ) );
	}

	public function test_an_lw_profile_deleted_while_its_row_is_made_leaves_no_row(): void {
		$api                   = $this->lwApi();
		$this->db->beforeWrite = function (): void { // Another request deletes the destination just before the row is written.
			$this->lwDestinations->forget( 'eeee5555' );
		};

		$e = self::refusal( fn() => $api->start( 'eeee5555', 7 ) );

		$this->assertSame( [ 'lw_migration_destination_missing', 404 ], [ $e->errorCode, $e->status ] );
		$this->assertNull( $this->lwDestinations->find( 'eeee5555' ) );
		$this->assertSame( [], $this->vault->fields( 'eeee5555' ), 'No row outlives the profile.' );
		$this->assertSame( [], FakeTransients::$store, 'No state is issued.' );
	}
}
