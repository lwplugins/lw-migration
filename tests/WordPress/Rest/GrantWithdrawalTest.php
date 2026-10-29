<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\OAuthApi;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\GrantRevoker;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #6: a delete, a Client-ID / mode / grant edit and a reconnect that
 * replaces a grant withdraw the old grant at the provider first, best
 * effort; a grant another connection of this site still uses is left alone.
 */
final class GrantWithdrawalTest extends MonkeyTestCase {

	private const CB = 'https://www.example.com/wp-json/lw-migration/v1/oauth/callback';

	private const PAGE = 'https://www.example.com/wp-admin/admin.php?page=lw-migration';

	private RecordedHttp $http;

	private SecretVault $vault;

	private FakeOAuthType $type;

	private Destinations $destinations;

	private int $exchanges = 0;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		FakeWpdb::install();
		FakeTransients::install();
		RecordedHttp::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		Functions\when( 'wp_get_session_token' )->justReturn( 'session-7' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'rest_url' )->justReturn( self::CB );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '&' . http_build_query( $args ) );
		AddressGuard::useResolver( static fn( string $host ): array => [ '203.0.113.10' ] );
		$this->http         = new RecordedHttp();
		$send               = ( new CurlHooks() )->install()->wrap( $this->http->send() );
		$endpoint           = $this->endpoint();
		$this->vault        = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$this->type         = new FakeOAuthType( $this->vault, $endpoint, $send );
		$this->destinations = new Destinations( new ProfileRepository(), $this->vault, new DestinationRegistry( [ $this->type, new OneDriveType( $this->vault, $endpoint, $send ), new DropboxType( $this->vault, $endpoint, $send ) ] ) );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function endpoint(): TokenEndpoint {
		$test = $this;

		return new class( $test ) implements TokenEndpoint {
			public function __construct( private GrantWithdrawalTest $test ) {
			}
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				$n = $this->test->exchanged();
				return new TokenSet( 'access-' . $n, 2_000_000_000, 'refresh-' . $n );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				return new TokenSet( 'access-r', 2_000_000_000 );
			}
		};
	}

	public function exchanged(): int {
		return ++$this->exchanges;
	}

	private function api(): DestinationsApi {
		return new DestinationsApi( $this->destinations, static fn(): array => [] );
	}

	private function oauth(): OAuthApi {
		return new OAuthApi( $this->destinations, new OAuthState( static fn(): int => 1_800_000_000 ), static fn(): string => self::CB, static fn(): string => self::PAGE, static fn(): int => 1_800_000_000 );
	}

	/**
	 * A profile connected to $account through its own app, with its tokens.
	 *
	 * @param string $id       Profile id.
	 * @param string $type     Type key.
	 * @param string $account  Account label.
	 * @param string $clientId Client ID.
	 */
	private function connected( string $id, string $type = 'fakeoauth', string $account = 'a@example.com', string $clientId = 'client-1' ): void {
		$settings = [
			'ownApp'   => true,
			'clientId' => $clientId,
		];
		$key      = $this->destinations->registry()->get( $type )->clientKey( $settings );
		$this->destinations->profiles()->save(
			new Profile(
				$id,
				$type,
				'Cloud ' . $id,
				$settings,
				connection: [
					'account' => $account,
					'client'  => ( new OAuthState() )->clientMark( $key ),
					'id'      => TokenKeeper::connectionId( str_repeat( 'b', 32 ) ),
				]
			)
		);
		$this->vault->put(
			$id,
			[
				'clientSecret' => 'secret-1',
				'refreshToken' => 'refresh-' . $id,
				'accessToken'  => '2000000000:access-' . $id,
				'grant'        => str_repeat( 'b', 32 ),
			]
		);
	}

	public function test_a_delete_withdraws_the_grant_before_the_tokens_are_forgotten(): void {
		$this->connected( 'aaaa1111' );
		$this->http->push(
			function ( string $url, array $args ): array {
				$this->assertSame( 'refresh-aaaa1111', $this->vault->open( 'aaaa1111' )['refreshToken'], 'Still stored while the provider is asked.' );
				return RecordedHttp::answer( 200 );
			}
		);

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertArrayNotHasKey( 'warnings', $answer );
		$this->assertSame( [ GrantRevoker::GOOGLE, 'token=refresh-aaaa1111' ], [ $this->http->requests[0][0], $this->http->requests[0][1]['body'] ] );
		$this->assertNull( $this->destinations->find( 'aaaa1111' ) );
		$this->assertSame( [], $this->vault->fields( 'aaaa1111' ) );
	}

	public function test_a_failed_withdrawal_never_blocks_the_delete_and_says_where_to_remove_the_access(): void {
		$this->connected( 'aaaa1111' );
		$this->http->push( RecordedHttp::answer( 503 ) );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertNull( $this->destinations->find( 'aaaa1111' ) );
		$this->assertSame( [], $this->vault->fields( 'aaaa1111' ) );
		$this->assertCount( 1, $answer['warnings'] );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $answer['warnings'][0] );
	}

	public function test_a_grant_another_destination_uses_with_the_same_account_and_app_is_left_alone(): void {
		$this->connected( 'aaaa1111' );
		$this->connected( 'bbbb2222' );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertArrayNotHasKey( 'warnings', $answer );
		$this->assertSame( [], $this->http->requests, 'Google withdraws the whole grant of the account to the app.' );
	}

	public function test_another_account_or_another_app_does_not_hold_the_grant(): void {
		$this->connected( 'aaaa1111' );
		$this->connected( 'bbbb2222', 'fakeoauth', 'other@example.com' );
		$this->connected( 'cccc3333', 'fakeoauth', 'a@example.com', 'client-2' );
		$this->http->push( RecordedHttp::answer( 200 ) );

		$this->api()->delete( 'aaaa1111' );

		$this->assertCount( 1, $this->http->requests );
	}

	/**
	 * Final review T5: "Google Drive" is the label of a connection whose account
	 * the provider did not name, so two of them may be two accounts: nothing
	 * is withdrawn, and the notice says where to remove the access.
	 */
	public function test_a_connection_without_a_named_account_is_never_taken_for_the_same_account(): void {
		$this->connected( 'aaaa1111', 'fakeoauth', 'Google Drive' );
		$this->connected( 'bbbb2222', 'fakeoauth', 'Google Drive' );
		$this->connected( 'cccc3333', 'fakeoauth', 'a@example.com' );

		$first  = $this->api()->delete( 'aaaa1111' );
		$second = $this->api()->delete( 'cccc3333' );

		$this->assertSame( [], $this->http->requests, 'Either could be the account the other one uses.' );
		foreach ( [ $first, $second ] as $answer ) {
			$this->assertCount( 1, $answer['warnings'] ?? [] );
			$this->assertStringContainsString( 'https://myaccount.google.com/connections', $answer['warnings'][0] );
		}
	}

	public function test_a_connection_without_a_named_account_alone_on_its_app_is_withdrawn(): void {
		$this->connected( 'aaaa1111', 'fakeoauth', 'Google Drive' );
		$this->connected( 'bbbb2222', 'fakeoauth', 'Google Drive', 'client-2' );
		$this->http->push( RecordedHttp::answer( 200 ) );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertArrayNotHasKey( 'warnings', $answer );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_dropbox_withdraws_only_its_own_token_even_when_the_account_is_shared(): void {
		$this->connected( 'aaaa1111', 'dropbox' );
		$this->connected( 'bbbb2222', 'dropbox' );
		$this->http->push( RecordedHttp::answer( 200, 'null' ) );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertArrayNotHasKey( 'warnings', $answer );
		$this->assertSame( GrantRevoker::DROPBOX, $this->http->requests[0][0] );
		$this->assertSame( 'Bearer access-aaaa1111', $this->http->requests[0][1]['headers']['Authorization'] );
	}

	public function test_an_lw_app_google_delete_names_the_permissions_page_without_a_request(): void {
		$this->connected( 'aaaa1111' );
		$profile           = $this->destinations->find( 'aaaa1111' );
		$profile->settings = [ 'ownApp' => false ];
		$this->destinations->profiles()->replace( $profile, false );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertNull( $this->destinations->find( 'aaaa1111' ) );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $answer['warnings'][0] );
		$this->assertSame( [], $this->http->requests, 'The LW app grant is shared by every site that uses it with the account.' );
	}

	public function test_a_onedrive_delete_names_the_permissions_page_without_a_request(): void {
		$this->connected( 'aaaa1111', 'onedrive' );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertStringContainsString( 'https://account.live.com/consent/Manage', $answer['warnings'][0] );
		$this->assertStringContainsString( 'https://myapps.microsoft.com', $answer['warnings'][0] );
		$this->assertSame( [], $this->http->requests );
	}

	public function test_tokens_that_no_longer_open_are_never_sent_and_give_the_notice(): void {
		$this->connected( 'aaaa1111' );
		$other              = new SecretVault( new SecretBox( str_repeat( 'z', 32 ) ), true );
		$this->destinations = new Destinations( new ProfileRepository(), $other, $this->destinations->registry() );

		$answer = $this->api()->delete( 'aaaa1111' );

		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $answer['warnings'][0] );
		$this->assertSame( [], $this->http->requests );
	}

	public function test_an_edit_that_keeps_the_grant_withdraws_nothing(): void {
		$this->connected( 'aaaa1111' );

		$this->api()->update( 'aaaa1111', [ 'name' => 'Renamed' ] );

		$this->assertSame( [], $this->http->requests );
		$this->assertSame( 'refresh-aaaa1111', $this->vault->open( 'aaaa1111' )['refreshToken'] );
	}

	public function test_a_client_id_change_withdraws_the_old_grant_and_shows_a_failure_on_the_card(): void {
		$this->connected( 'aaaa1111' );
		$this->http->push( RecordedHttp::answer( 400 ) );

		$view = $this->api()->update(
			'aaaa1111',
			[
				'settings' => [
					'ownApp'   => true,
					'clientId' => 'client-2',
				],
				'secrets'  => [ 'clientSecret' => 'secret-2' ],
			]
		);

		$this->assertSame( 'token=refresh-aaaa1111', $this->http->requests[0][1]['body'] );
		$this->assertSame( Profile::NEW, $view['status'] );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $view['message'] );
		$this->assertSame( [ 'clientSecret' ], $this->vault->fields( 'aaaa1111' ) );
	}

	/**
	 * A connect of aaaa1111 through its own app, finished.
	 */
	private function reconnect(): string {
		parse_str( (string) parse_url( $this->oauth()->start( 'aaaa1111', 7 )['url'], PHP_URL_QUERY ), $query );

		return $this->oauth()->finish(
			[
				'code'  => 'c',
				'state' => $query['state'],
			],
			7
		);
	}

	public function test_a_reconnect_to_another_account_withdraws_the_replaced_grant(): void {
		$this->connected( 'aaaa1111', 'fakeoauth', 'old@example.com' );
		$this->http->push( RecordedHttp::answer( 200 ) );

		$back = $this->reconnect();

		$this->assertStringContainsString( 'lwm-oauth=connected', $back );
		$this->assertSame( 'token=refresh-aaaa1111', $this->http->requests[0][1]['body'] );
		$this->assertSame( 'refresh-1', $this->vault->open( 'aaaa1111' )['refreshToken'], 'The new grant stays.' );
		$this->assertSame( '', $this->destinations->find( 'aaaa1111' )->message );
	}

	public function test_a_reconnect_to_the_same_google_account_withdraws_nothing(): void {
		$this->connected( 'aaaa1111', 'fakeoauth', 'admin@example.com' );

		$this->assertStringContainsString( 'lwm-oauth=connected', $this->reconnect() );
		$this->assertSame( [], $this->http->requests, 'Google would withdraw the new grant too.' );
	}

	public function test_a_replaced_grant_that_cannot_be_withdrawn_is_shown_on_the_card(): void {
		$this->connected( 'aaaa1111', 'fakeoauth', 'old@example.com' );
		$this->http->push( RecordedHttp::answer( 500 ) );

		$this->assertStringContainsString( 'lwm-oauth=connected', $this->reconnect() );
		$profile = $this->destinations->find( 'aaaa1111' );
		$this->assertSame( Profile::NEW, $profile->status );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $profile->message );
		$this->assertSame( 'refresh-1', $this->vault->open( 'aaaa1111' )['refreshToken'] );
	}

	public function test_a_first_connect_has_nothing_to_withdraw(): void {
		$this->destinations->profiles()->save( new Profile( 'aaaa1111', 'fakeoauth', 'Drive', [ 'clientId' => 'client-1' ] ) );
		$this->vault->put( 'aaaa1111', [ 'clientSecret' => 'secret-1' ] );

		$this->assertStringContainsString( 'lwm-oauth=connected', $this->reconnect() );
		$this->assertSame( [], $this->http->requests );
	}
}
