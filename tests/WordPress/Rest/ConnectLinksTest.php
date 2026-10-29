<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Admin\ConnectRedirect;
use LightweightPlugins\Migration\Rest\ConnectLinks;
use LightweightPlugins\Migration\Rest\OAuthApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ConnectTicket;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeTransients;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #12 (ruling R12): the command line prints a one-time link to the
 * plugin's page; the page, opened by that administrator, issues the
 * session-bound state and sends the browser to the provider.
 */
final class ConnectLinksTest extends MonkeyTestCase {

	private const CB = 'https://www.example.com/wp-json/lw-migration/v1/oauth/callback';

	private const PAGE = 'https://www.example.com/wp-admin/admin.php?page=lw-migration';

	private Destinations $destinations;

	private string $session = 'browser-session-7';

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		FakeWpdb::install();
		FakeTransients::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_get_session_token' )->alias( fn(): string => $this->session );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url = null ): string {
				return is_array( $key ) ? $value . '&' . http_build_query( $key ) : $url . '&' . $key . '=' . $value;
			}
		);
		$vault              = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$this->destinations = new Destinations( new ProfileRepository(), $vault, new DestinationRegistry( [ new FakeOAuthType( $vault ), new S3Type() ] ) );
		$this->destinations->profiles()->save( new Profile( 'aaaa1111', 'fakeoauth', 'Drive', [ 'clientId' => 'client-1' ] ) );
		$vault->put( 'aaaa1111', [ 'clientSecret' => 'secret-1' ] );
	}

	private function links(): ConnectLinks {
		$api = new OAuthApi( $this->destinations, new OAuthState( static fn(): int => 1_800_000_000 ), static fn(): string => self::CB, static fn(): string => self::PAGE, static fn(): int => 1_800_000_000 );

		return new ConnectLinks( $api, new ConnectTicket( static fn(): int => 1_800_000_000 ), static fn(): string => self::PAGE );
	}

	private static function ticket( string $link ): string {
		return (string) substr( $link, strlen( self::PAGE . '&lwm-connect=' ) );
	}

	public function test_link_is_the_plugin_page_with_a_ticket_and_issues_no_state(): void {
		$link = $this->links()->link( 'aaaa1111', 7 );

		$this->assertMatchesRegularExpression( '/^' . preg_quote( self::PAGE . '&lwm-connect=', '/' ) . '[a-f0-9]{64}$/', $link );
		$this->assertCount( 1, FakeTransients::$store );
		$this->assertStringStartsWith( 'lw_migration_connect_', (string) array_key_first( FakeTransients::$store ) );
	}

	public function test_link_runs_the_connect_checks_first(): void {
		foreach ( [ 'ffff0000', 'abc' ] as $id ) {
			try {
				$this->links()->link( $id, 7 );
				$this->fail( 'Refused: ' . $id );
			} catch ( RestException $e ) {
				$this->assertSame( 404, $e->status );
			}
		}
		$this->assertSame( [], FakeTransients::$store, 'No ticket for a destination that cannot connect.' );
	}

	public function test_following_the_link_issues_a_state_in_the_browser_session_and_goes_to_the_provider(): void {
		$ticket = self::ticket( $this->links()->link( 'aaaa1111', 7 ) );

		$url = $this->links()->follow( $ticket, 7 );

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$state = new OAuthState( static fn(): int => 1_800_000_000 );
		$this->session = 'another-session-of-7';
		$this->assertNull( $state->consume( (string) $query['state'], 7 ), 'The state is bound to the session that opened the link.' );
	}

	public function test_a_used_or_another_administrators_link_goes_back_as_expired(): void {
		$ticket = self::ticket( $this->links()->link( 'aaaa1111', 7 ) );
		$this->assertStringContainsString( 'lwm-oauth=expired', $this->links()->follow( $ticket, 8 ), 'Another administrator.' );
		$this->assertStringContainsString( 'lwm-oauth=expired', $this->links()->follow( $ticket, 7 ), 'Used up by the refused use.' );

		$ticket = self::ticket( $this->links()->link( 'aaaa1111', 7 ) );
		$this->links()->follow( $ticket, 7 );
		$this->assertStringContainsString( 'lwm-oauth=expired', $this->links()->follow( $ticket, 7 ), 'Works once.' );
	}

	public function test_a_link_whose_connect_cannot_start_goes_back_as_failed(): void {
		$ticket = self::ticket( $this->links()->link( 'aaaa1111', 7 ) );
		$this->session = '';

		$this->assertStringContainsString( 'lwm-oauth=failed&lwm-destination=aaaa1111', $this->links()->follow( $ticket, 7 ) );
	}

	public function test_the_admin_page_follows_only_its_own_link_for_a_capable_user(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		$can = true;
		Functions\when( 'current_user_can' )->alias( static function () use ( &$can ): bool {
			return $can;
		} );
		$ticket = self::ticket( $this->links()->link( 'aaaa1111', 7 ) );

		$this->assertNull( ConnectRedirect::target( [ 'page' => 'other', 'lwm-connect' => $ticket ], 7, $this->links() ), 'Another page.' );
		$this->assertNull( ConnectRedirect::target( [ 'page' => 'lw-migration' ], 7, $this->links() ), 'No ticket.' );
		$can = false;
		$this->assertNull( ConnectRedirect::target( [ 'page' => 'lw-migration', 'lwm-connect' => $ticket ], 7, $this->links() ), 'Without the capability.' );
		$can = true;
		$this->assertStringStartsWith( 'https://accounts.google.com/', (string) ConnectRedirect::target( [ 'page' => 'lw-migration', 'lwm-connect' => $ticket ], 7, $this->links() ) );
	}

	public function test_on_a_network_only_the_network_admin_page_follows_the_link(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertNull( ConnectRedirect::target( [ 'page' => 'lw-migration', 'lwm-connect' => str_repeat( 'a', 64 ) ], 7, $this->links() ) );
		$this->assertCount( 0, FakeTransients::$store );
	}
}
