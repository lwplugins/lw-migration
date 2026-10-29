<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\Dropbox\DropboxType;
use LightweightPlugins\Migration\Storage\Destinations\GoogleDrive\GoogleDriveType;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\GrantRevoker;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #6 (ruling R6): the old grant is withdrawn at the provider, best
 * effort, straight from the site, in both modes; what cannot be withdrawn
 * gives the notice with the account's app-permissions page.
 */
final class GrantRevokerTest extends MonkeyTestCase {

	private const NOW = 1_800_000_000;

	private CurlHooks $curl;

	private RecordedHttp $http;

	/** @var array<int, array{0: string, 1: string}> Refreshes asked of the own app's endpoint: Client ID, refresh token. */
	public array $refreshes = [];

	public ?PermanentFailure $refusal = null;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		$this->curl = ( new CurlHooks() )->install();
		$this->http = new RecordedHttp();
		AddressGuard::useResolver( static fn( string $host ): array => [ '203.0.113.10' ] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function revoker(): GrantRevoker {
		return new GrantRevoker( $this->curl->wrap( $this->http->send() ), static fn(): int => self::NOW );
	}

	private function endpoint(): TokenEndpoint {
		$test = $this;

		return new class( $test ) implements TokenEndpoint {
			public function __construct( private GrantRevokerTest $test ) {
			}
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				$this->test->refreshes[] = [ $app->clientId, $refreshToken ];
				if ( null !== $this->test->refusal ) {
					throw $this->test->refusal;
				}
				return new TokenSet( 'fresh-access', GrantRevokerTest::later() );
			}
		};
	}

	public static function later(): int {
		return self::NOW + 3600;
	}

	/**
	 * @param string $access Cached access token.
	 * @return array<string, string>
	 */
	private static function secrets( string $access ): array {
		return [
			'clientSecret' => 'app-secret',
			'refreshToken' => 'refresh-1',
			'accessToken'  => $access,
			'grant'        => str_repeat( 'a', 32 ),
		];
	}

	/**
	 * @return array<string, mixed> Settings of an own-app Google profile.
	 */
	private static function ownGoogle(): array {
		return [
			OAuthMode::OWN_APP => true,
			'clientId'         => 'google-client',
		];
	}

	/**
	 * C1 ruling: the LW app's Google grant is shared by every site using it
	 * with the same account, so it is never revoked from one site.
	 */
	public function test_an_lw_app_google_grant_is_never_revoked_and_names_the_permissions_page(): void {
		$notice = (string) $this->revoker()->revoke( new GoogleDriveType(), [ OAuthMode::OWN_APP => false ], self::secrets( self::later() . ':at' ) );

		$this->assertSame( [], $this->http->requests );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $notice );
		$this->assertStringContainsString( 'other sites', $notice );
		$this->assertStringNotContainsString( 'refresh-1', $notice );
	}

	public function test_google_gets_the_refresh_token_as_a_form_field_without_authorization(): void {
		$this->http->push( RecordedHttp::answer( 200 ) );

		$notice = $this->revoker()->revoke( new GoogleDriveType(), self::ownGoogle(), self::secrets( self::later() . ':at' ) );

		$this->assertNull( $notice );
		[ $url, $args ] = $this->http->requests[0];
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $url );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 'token=refresh-1', $args['body'] );
		$this->assertSame( [ 'Content-Type' => 'application/x-www-form-urlencoded' ], $args['headers'] );
		$this->assertSame( 10, $args['timeout'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
	}

	public function test_a_refused_google_revoke_gives_the_notice_with_the_permissions_page(): void {
		$this->http->push( RecordedHttp::answer( 400, '{"error":"invalid_token"}' ) );

		$notice = (string) $this->revoker()->revoke( new GoogleDriveType(), self::ownGoogle(), self::secrets( '' ) );

		$this->assertStringContainsString( 'Google Drive', $notice );
		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $notice );
		$this->assertStringNotContainsString( 'refresh-1', $notice );
	}

	public function test_a_transport_failure_gives_the_notice(): void {
		$this->http->push( new FakeWpError( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$notice = (string) $this->revoker()->revoke( new GoogleDriveType(), self::ownGoogle(), self::secrets( '' ) );

		$this->assertStringContainsString( 'https://myaccount.google.com/connections', $notice );
	}

	public function test_dropbox_gets_the_fresh_cached_access_token_as_bearer(): void {
		$this->http->push( RecordedHttp::answer( 200, 'null' ) );

		$notice = $this->revoker()->revoke( new DropboxType( null, $this->endpoint() ), [ OAuthMode::OWN_APP => true, 'clientId' => 'key-1' ], self::secrets( self::later() . ':cached-access' ) );

		$this->assertNull( $notice );
		$this->assertSame( [], $this->refreshes );
		[ $url, $args ] = $this->http->requests[0];
		$this->assertSame( 'https://api.dropboxapi.com/2/auth/token/revoke', $url );
		$this->assertSame( 'Bearer cached-access', $args['headers']['Authorization'] );
		$this->assertSame( 'null', $args['body'] );
		$this->assertSame( 10, $args['timeout'] );
	}

	public function test_dropbox_refreshes_an_expired_access_token_through_the_own_app_first(): void {
		$this->http->push( RecordedHttp::answer( 200, 'null' ) );

		$notice = $this->revoker()->revoke( new DropboxType( null, $this->endpoint() ), [ OAuthMode::OWN_APP => true, 'clientId' => 'key-1' ], self::secrets( ( self::NOW + 60 ) . ':old-access' ) );

		$this->assertNull( $notice );
		$this->assertSame( [ [ 'key-1', 'refresh-1' ] ], $this->refreshes );
		$this->assertSame( 'Bearer fresh-access', $this->http->requests[0][1]['headers']['Authorization'] );
	}

	/**
	 * Issue #12: a token the LW sign-in service refreshed is used to revoke
	 * only after Dropbox itself named the recorded account for it.
	 */
	public function test_dropbox_refreshes_through_the_lw_sign_in_service_in_lw_app_mode(): void {
		$this->http->push( RecordedHttp::answer( 200, '{"access_token":"relayed-access","expires_in":14400,"token_type":"bearer"}' ) );
		$this->http->push( RecordedHttp::answer( 200, '{"account_id":"dbid:AA","email":"a@example.com"}' ) );
		$this->http->push( RecordedHttp::answer( 200, 'null' ) );

		$notice = $this->revoker()->revoke( $this->lwDropbox(), [ OAuthMode::OWN_APP => false ], self::secrets( '' ) + [ 'identity' => 'dropbox:dbid:AA' ] );

		$this->assertNull( $notice );
		$this->assertSame( 'https://auth.lwplugins.com/v1/refresh', $this->http->requests[0][0] );
		$this->assertStringContainsString( '"refresh_token":"refresh-1"', $this->http->requests[0][1]['body'] );
		$this->assertSame( [ 'https://api.dropboxapi.com/2/users/get_current_account', 'Bearer relayed-access' ], [ $this->http->requests[1][0], $this->http->requests[1][1]['headers']['Authorization'] ] );
		$this->assertSame( 'Bearer relayed-access', $this->http->requests[2][1]['headers']['Authorization'] );
	}

	/**
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, string>}>
	 */
	public static function unconfirmedRelayedTokens(): array {
		$refresh = RecordedHttp::answer( 200, '{"access_token":"relayed-access","expires_in":14400,"token_type":"bearer"}' );

		return [
			'another account'       => [ [ $refresh, RecordedHttp::answer( 200, '{"account_id":"dbid:OTHER"}' ) ], [ 'identity' => 'dropbox:dbid:AA' ] ],
			'the account call fails' => [ [ $refresh, RecordedHttp::answer( 503, '{}' ) ], [ 'identity' => 'dropbox:dbid:AA' ] ],
			'nothing recorded'      => [ [ $refresh ], [] ],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $answers Answers in order.
	 * @param array<string, string>            $extra   Recorded account.
	 */
	#[DataProvider( 'unconfirmedRelayedTokens' )]
	public function test_a_relayed_dropbox_token_not_confirmed_as_the_recorded_account_gives_the_notice( array $answers, array $extra ): void {
		foreach ( $answers as $answer ) {
			$this->http->push( $answer );
		}

		$notice = (string) $this->revoker()->revoke( $this->lwDropbox(), [ OAuthMode::OWN_APP => false ], self::secrets( '' ) + $extra );

		$this->assertStringContainsString( 'https://www.dropbox.com/account/connected_apps', $notice );
		$this->assertNotContains( GrantRevoker::DROPBOX, array_column( $this->http->requests, 0 ), 'No revoke sent.' );
	}

	private function lwDropbox(): DropboxType {
		$send = $this->curl->wrap( $this->http->send() );

		return new DropboxType( null, $this->endpoint(), $send, static fn(): int => self::NOW, new RelayTokenEndpoint( $send, static fn(): int => self::NOW ) );
	}

	public function test_a_failed_dropbox_refresh_gives_the_notice_and_sends_no_revoke(): void {
		$this->refusal = new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH );

		$notice = (string) $this->revoker()->revoke( new DropboxType( null, $this->endpoint() ), [ OAuthMode::OWN_APP => true, 'clientId' => 'key-1' ], self::secrets( '' ) );

		$this->assertStringContainsString( 'https://www.dropbox.com/account/connected_apps', $notice );
		$this->assertSame( [], $this->http->requests );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function oneDrive(): array {
		$personal = 'https://account.live.com/consent/Manage';
		$work     = 'https://myapps.microsoft.com';

		return [
			'lw app (common)' => [ [ OAuthMode::OWN_APP => false ], [ $personal, $work ], [] ],
			'consumers'       => [ [ OAuthMode::OWN_APP => true, 'clientId' => 'c', 'tenant' => 'consumers' ], [ $personal ], [ $work ] ],
			'organizations'   => [ [ OAuthMode::OWN_APP => true, 'clientId' => 'c', 'tenant' => 'organizations' ], [ $work ], [ $personal ] ],
			'a tenant domain' => [ [ OAuthMode::OWN_APP => true, 'clientId' => 'c', 'tenant' => 'contoso.example' ], [ $work ], [ $personal ] ],
		];
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 * @param array<int, string>   $shown    Pages the notice names.
	 * @param array<int, string>   $hidden   Pages it does not.
	 */
	#[DataProvider( 'oneDrive' )]
	public function test_onedrive_has_no_revoke_and_always_names_the_permissions_page( array $settings, array $shown, array $hidden ): void {
		$notice = (string) $this->revoker()->revoke( new OneDriveType(), $settings, self::secrets( '' ) );

		foreach ( $shown as $page ) {
			$this->assertStringContainsString( $page, $notice );
		}
		foreach ( $hidden as $page ) {
			$this->assertStringNotContainsString( $page, $notice );
		}
		$this->assertSame( [], $this->http->requests );
	}

	public function test_a_profile_without_a_refresh_token_has_nothing_to_withdraw(): void {
		$this->assertNull( $this->revoker()->revoke( new OneDriveType(), [], [ 'clientSecret' => 's' ] ) );
		$this->assertNull( $this->revoker()->revoke( new GoogleDriveType(), [], [] ) );
		$this->assertSame( [], $this->http->requests );
	}
}
