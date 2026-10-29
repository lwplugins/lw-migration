<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\DestinationView;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Field;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\DirectTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthMode;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthView;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RelayTokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeDestination;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class OAuthDestinationTypeTest extends MonkeyTestCase {

	private const CB = 'https://www.example.com/wp-json/lw-migration/v1/oauth/callback'; // Google refuses a .test host (no public suffix).

	/** @var array<int, string> URLs the type's transport was asked for. */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'rest_url' )->justReturn( self::CB );
		$this->sent = [];
	}

	protected function tearDown(): void {
		Relay::useForTests( null );
		parent::tearDown();
	}

	/**
	 * @param int|null $now The type's clock (null: time()).
	 */
	private function type( ?int $now = null ): FakeOAuthType {
		return new FakeOAuthType(
			new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true ),
			null,
			function ( string $url ): array {
				$this->sent[] = $url;
				throw new \LogicException( 'No request expected.' );
			},
			null === $now ? null : static fn(): int => $now
		);
	}

	/**
	 * The vault row of the connected() profile: its tokens under grant $grant.
	 */
	private static function stored( FakeOAuthType $type, string $grant = 'g' ): FakeOAuthType {
		$type->vault()->put( 'abcd1234', [ 'clientSecret' => 's', 'refreshToken' => 'r', 'grant' => $grant ] );

		return $type;
	}

	/**
	 * @return string "<reason> <message>" of the failure, or "made".
	 */
	private static function outcome( \Closure $call ): string {
		try {
			$call();
		} catch ( \LightweightPlugins\Migration\Storage\Destinations\CloudFailure $e ) {
			return $e->reason . ' ' . $e->getMessage();
		}
		return 'made';
	}

	private static function mark( string $clientId ): string {
		return ( new OAuthState() )->clientMark( $clientId );
	}

	/**
	 * A profile connected with Client ID "c" (or $clientId).
	 */
	private static function connected( string $clientId = 'c' ): Profile {
		$profile             = new Profile( 'abcd1234', 'fakeoauth', 'D', [ 'clientId' => 'c' ] );
		$profile->connection = [
			'account'     => 'admin@example.com',
			'connectedAt' => 1700000000,
			'redirectUri' => self::CB,
			'client'      => self::mark( $clientId ),
			'id'          => TokenKeeper::connectionId( 'g' ),
			'folder'      => 'wphu-org',
		];

		return $profile;
	}

	private static function reason( \Closure $call ): string {
		try {
			$call();
		} catch ( PermanentFailure $e ) {
			return $e->reason . ' ' . $e->getMessage();
		}
		return 'made';
	}

	public function test_fields_start_with_the_own_app_switch_and_show_the_app_fields_only_with_it(): void {
		$fields = $this->type()->fields();
		$own    = [ OAuthMode::OWN_APP => [ true ] ];

		$this->assertSame( [ 'ownApp', 'clientId', 'clientSecret', 'account' ], array_map( static fn( Field $f ): string => $f->key, $fields ) );
		$this->assertSame( [ Field::TOGGLE, Field::TEXT, Field::SECRET, Field::OAUTH ], array_map( static fn( Field $f ): string => $f->kind, $fields ) );
		$this->assertFalse( $fields[0]->default, 'The LW app is the default.' );
		$this->assertSame( [ $own, $own, [] ], [ $fields[1]->when, $fields[2]->when, $fields[3]->when ] );
		$this->assertTrue( $fields[2]->required, 'With the own app, token writes need the client secret row.' );
		$this->assertFalse( $fields[2]->visible( [ OAuthMode::OWN_APP => false ] ) );
		$this->assertFalse( $fields[3]->isSecret() );
	}

	public function test_settings_keep_a_trimmed_client_id_and_refuse_a_bad_one(): void {
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'abc.apps.googleusercontent.com' ], $this->type()->settings( [ 'clientId' => ' abc.apps.googleusercontent.com ', 'account' => 'x', 'junk' => 1, 'refreshToken' => 'r' ] ) );
		foreach ( [ '', 'has space', str_repeat( 'a', 257 ), "a\x7Fb" ] as $bad ) {
			try {
				$this->type()->settings( [ 'clientId' => $bad ] );
				$this->fail( 'Refused: ' . $bad );
			} catch ( FieldException $e ) {
				$this->assertSame( 'clientId', $e->field );
			}
		}
	}

	public function test_make_needs_the_secret_and_a_refresh_token(): void {
		$type    = $this->type();
		$profile = self::connected();

		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::reason( fn() => $type->make( $profile, [] ) ) );
		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::reason( fn() => $type->make( $profile, [ 'refreshToken' => 'r' ] ) ) );
		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::reason( fn() => $type->make( $profile, [ 'clientSecret' => 's' ] ) ) );
		$this->assertNull( $type->made );

		$this->assertInstanceOf( FakeDestination::class, $type->make( $profile, [ 'clientSecret' => 's', 'refreshToken' => 'r', 'grant' => 'g' ] ) );
		$this->assertNotNull( $type->made );
		$this->assertSame( [], $this->sent, 'Making a client sends nothing.' );
	}

	public function test_tokens_of_another_client_id_are_never_used(): void {
		$type    = $this->type();
		$secrets = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9999999999:a', 'grant' => 'g' ];
		$never   = new Profile( 'abcd1234', 'fakeoauth', 'D', [ 'clientId' => 'c' ] );

		$other   = self::reason( fn() => $type->make( self::connected( 'old' ), $secrets ) );
		$missing = self::reason( fn() => $type->make( $never, $secrets ) );

		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', $other );
		$this->assertStringContainsString( 'Client ID', $other );
		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', $missing );
		$this->assertNull( $type->made );
		$this->assertSame( [], $this->sent, 'make() sends nothing by itself; the refusal is what leaves made null above, so no client could ever send.' );
		foreach ( [ 'admin@example.com', '9999999999', ':a' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $other . $missing );
		}
	}

	/**
	 * Task 7 review I1 and round 2: the profile's connection and the vault's
	 * tokens must be one generation (connection.id = the mark of the stored
	 * grant). Within COMMIT_WINDOW seconds of the connect the destination
	 * waits (a connect between its two writes); later the token write must
	 * have failed, so it asks for a new connect (REAUTH: the run ends and
	 * mails once). Nothing is ever sent with the other generation's tokens.
	 */
	public function test_tokens_of_another_connection_generation_wait_then_need_a_new_connect(): void {
		$secrets = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9999999999:a', 'grant' => 'other' ];
		$at      = (int) self::connected()->connection['connectedAt'];
		$inside  = $this->type( $at + 299 );
		$after   = $this->type( $at + 300 );

		$busy    = self::outcome( fn() => $inside->make( self::connected(), $secrets ) );
		$noGrant = self::outcome( fn() => $inside->make( self::connected(), array_diff_key( $secrets, [ 'grant' => 0 ] ) ) );
		$late    = self::outcome( fn() => $after->make( self::connected(), $secrets ) );

		$this->assertStringStartsWith( TransientFailure::BUSY . ' ', $busy );
		$this->assertStringContainsString( 'changed meanwhile', $busy );
		$this->assertStringStartsWith( TransientFailure::BUSY . ' ', $noGrant );
		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', $late );
		$this->assertStringContainsString( 'Choose Connect again', $late );
		$this->assertNull( $inside->made );
		$this->assertNull( $after->made );
		$this->assertSame( [], $this->sent );
	}

	/**
	 * Round 2: a first connect read between its two writes (the vault holds
	 * only the client secret) waits; it is not "not connected yet" (REAUTH,
	 * which would mark the profile and mail).
	 */
	public function test_a_first_connect_between_its_two_writes_waits(): void {
		$type = $this->type( (int) self::connected()->connection['connectedAt'] + 5 );

		$this->assertStringStartsWith( TransientFailure::BUSY . ' ', self::outcome( fn() => $type->make( self::connected(), [ 'clientSecret' => 's' ] ) ) );
	}

	/**
	 * Task 8 m5: a connect time a few seconds ahead is clock skew between
	 * two servers (the commit window still waits); further ahead it needs a
	 * new connect.
	 */
	public function test_a_connect_time_slightly_ahead_still_waits(): void {
		$secrets = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9999999999:a', 'grant' => 'other' ];
		$type    = $this->type( (int) self::connected()->connection['connectedAt'] - 5 );

		$this->assertStringStartsWith( TransientFailure::BUSY . ' ', self::outcome( fn() => $type->make( self::connected(), $secrets ) ) );
		$this->assertNull( $type->made );
	}

	/**
	 * Task 7 minor: a connect time in the future (a clock set back, or a
	 * damaged profile) never opens an endless wait: it asks for a new
	 * connect like a connect older than COMMIT_WINDOW.
	 */
	public function test_a_connect_time_in_the_future_needs_a_new_connect(): void {
		$secrets = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9999999999:a', 'grant' => 'other' ];
		$type    = $this->type( (int) self::connected()->connection['connectedAt'] - 6 );

		$late = self::outcome( fn() => $type->make( self::connected(), $secrets ) );

		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', $late );
		$this->assertStringContainsString( 'Choose Connect again', $late );
		$this->assertNull( $type->made );
		$this->assertSame( [], $this->sent );
	}

	/**
	 * Round 2: a connection without an id was never committed by the callback: fail closed.
	 */
	public function test_a_connection_without_an_id_is_never_used(): void {
		$noId = self::connected();
		unset( $noId->connection['id'] );
		$type = $this->type();

		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::outcome( fn() => $type->make( $noId, [ 'clientSecret' => 's', 'refreshToken' => 'r', 'grant' => 'g' ] ) ) );
		$this->assertNull( $type->made );
	}

	/**
	 * Round 2: the view says connected only for the generation the vault holds.
	 */
	public function test_the_view_is_connected_only_for_the_stored_generation(): void {
		$fields = [ 'clientSecret', 'refreshToken', 'grant' ];

		$this->assertTrue( OAuthView::connection( self::stored( $this->type() ), self::connected(), $fields, self::CB )['connected'] );
		$this->assertFalse( OAuthView::connection( self::stored( $this->type(), 'other' ), self::connected(), $fields, self::CB )['connected'] );
	}

	public function test_the_schema_block_shows_the_address_steps_and_eligibility(): void {
		$ok  = OAuthView::schema( $this->type(), [], self::CB );
		$bad = OAuthView::schema( $this->type(), [], 'http://site.test/cb' );

		$this->assertSame( [ 'provider', 'redirectUri', 'eligible', 'reason', 'text', 'steps', 'consoleUrl', 'relay' ], array_keys( $ok ) );
		$this->assertSame( [ 'eligible' => true, 'reason' => '', 'text' => '' ], $ok['relay'] );
		$this->assertSame( [ false, 'https' ], [ $bad['relay']['eligible'], $bad['relay']['reason'] ] );
		$this->assertSame( 'google', $ok['provider'] );
		$this->assertSame( self::CB, $ok['redirectUri'] );
		$this->assertTrue( $ok['eligible'] );
		$this->assertSame( '', $ok['reason'] );
		$this->assertSame( [ 'Create an app.', 'Paste the address.' ], $ok['steps'] );
		$this->assertSame( 'https://console.example', $ok['consoleUrl'] );
		$this->assertFalse( $bad['eligible'] );
		$this->assertSame( 'https', $bad['reason'] );
		$this->assertNotSame( '', $bad['text'] );
	}

	public function test_a_changed_redirect_address_is_flagged_but_tokens_keep_working(): void {
		$profile                            = self::connected();
		$profile->connection['redirectUri'] = 'https://old-domain.test/wp-json/lw-migration/v1/oauth/callback';
		$type                               = self::stored( $this->type() );

		$view = OAuthView::connection( $type, $profile, [ 'clientSecret', 'refreshToken', 'grant' ], self::CB );

		$this->assertSame( [ 'connected', 'account', 'connectedAt', 'redirectChanged', 'eligible', 'reason', 'text' ], array_keys( $view ) );
		$this->assertTrue( $view['connected'] );
		$this->assertTrue( $view['redirectChanged'] );
		$this->assertSame( 'admin@example.com', $view['account'] );
		$this->assertSame( 1700000000, $view['connectedAt'] );
		$this->assertInstanceOf( FakeDestination::class, $type->make( $profile, [ 'clientSecret' => 's', 'refreshToken' => 'r', 'grant' => 'g' ] ), 'A refresh needs no redirect address.' );
		$this->assertFalse( OAuthView::connection( $type, $profile, [ 'clientSecret' ], self::CB )['connected'] );
		$this->assertFalse( OAuthView::connection( $type, self::connected(), [ 'clientSecret', 'refreshToken' ], self::CB )['redirectChanged'], 'The same address is not a change.' );
	}

	public function test_a_connection_of_another_client_id_is_not_connected(): void {
		$view = OAuthView::connection( $this->type(), self::connected( 'old' ), [ 'clientSecret', 'refreshToken', 'grant' ], self::CB );

		$this->assertFalse( $view['connected'] );
		$this->assertFalse( $view['redirectChanged'] );
	}

	public function test_the_registry_schema_and_the_view_carry_the_oauth_blocks_and_never_a_token(): void {
		$type    = self::stored( $this->type() );
		$schema  = ( new DestinationRegistry( [ new S3Type(), $type ] ) )->schema();
		$profile = self::connected();

		$view  = DestinationView::of( $profile, $type, [ 'clientSecret', 'refreshToken', 'accessToken', 'grant' ], 0 );
		$fresh = DestinationView::of( new Profile( 'abcd1235', 'fakeoauth', 'E', [ 'clientId' => 'c' ] ), $type, [ 'clientSecret' ], 0 );
		$s3    = DestinationView::of( new Profile( 'abcd1236', 's3', 'S', [] ), new S3Type(), [], 0 );

		$this->assertArrayNotHasKey( 'oauth', $schema[0], 'Other types have no OAuth block.' );
		$this->assertSame( self::CB, $schema[1]['oauth']['redirectUri'] );
		$this->assertSame( [ 'clientSecret' => [ 'set' => true ] ], $view['secrets'], 'Token fields are not form fields: never listed.' );
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'c' ], $view['settings'] );
		$this->assertTrue( $view['connection']['connected'] );
		$this->assertSame( 'Google Drive · wphu-org', $view['where'], 'The account label is shown only in connection (H16).' );
		$this->assertSame( 'admin@example.com', $view['connection']['account'] );
		$this->assertFalse( $fresh['connection']['connected'] );
		$this->assertSame( 'Not connected yet', $fresh['where'] );
		$this->assertNull( $s3['connection'] );
		foreach ( [ 'refreshToken', 'accessToken', 'grant', self::mark( 'c' ) ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, (string) json_encode( [ $view, $schema ] ) );
		}
	}

	/**
	 * An LW-app profile connected (mark of the relay key) with tokens under grant g.
	 */
	private static function relayed(): Profile {
		$profile             = new Profile( 'abcd1234', 'fakeoauth', 'D', [ OAuthMode::OWN_APP => false ] );
		$profile->connection = [
			'account'     => 'admin@example.com',
			'connectedAt' => 1700000000,
			'redirectUri' => self::CB,
			'client'      => self::mark( OAuthMode::relayKey( 'google' ) ),
			'id'          => TokenKeeper::connectionId( 'g' ),
		];

		return $profile;
	}

	public function test_settings_choose_the_lw_app_unless_the_own_app_is_switched_on(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$type = $this->type();

		$this->assertSame( [ 'ownApp' => false ], $type->settings( [] ) );
		$this->assertSame( [ 'ownApp' => false ], $type->settings( [ 'ownApp' => false, 'clientId' => 'ignored' ] ) );
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'a' ], $type->settings( [ 'ownApp' => true, 'clientId' => ' a ' ] ) );
		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'a' ], $type->settings( [ 'clientId' => 'a' ] ), 'A Client ID alone means the own app.' );
		foreach ( [ [ [ 'ownApp' => 'yes' ], 'ownApp' ], [ [ 'ownApp' => true ], 'clientId' ], [ [ 'ownApp' => 1, 'clientId' => 'a' ], 'ownApp' ] ] as [ $raw, $field ] ) {
			try {
				$type->settings( $raw );
				$this->fail( 'Refused: ' . wp_json_encode( $raw ) );
			} catch ( FieldException $e ) {
				$this->assertSame( $field, $e->field );
			}
		}
	}

	public function test_the_lw_app_binds_to_the_relay_and_the_provider_never_to_a_client_id(): void {
		$type = $this->type();

		$this->assertSame( "\0lw-relay:google", $type->clientKey( [ 'ownApp' => false ] ) );
		$this->assertSame( "\0lw-relay:google\0g", $type->clientKey( [ 'ownApp' => false, 'grant' => 'g' ] ) );
		$this->assertSame( 'lw-relay:google', $type->clientKey( [ 'ownApp' => true, 'clientId' => 'lw-relay:google' ] ) );
		$this->assertNotSame( $type->clientKey( [ 'ownApp' => false ] ), $type->clientKey( [ 'ownApp' => true, 'clientId' => 'lw-relay:google' ] ) );
		$this->assertSame( '', $type->clientKey( [ 'ownApp' => true ] ), 'An own app without a Client ID binds nothing.' );
	}

	public function test_a_profile_without_the_switch_reads_as_its_own_app(): void {
		$this->assertTrue( OAuthMode::ownApp( [ 'clientId' => 'c' ] ) );
		$this->assertFalse( OAuthMode::ownApp( [] ) );
		$this->assertFalse( OAuthMode::ownApp( [ 'clientId' => '' ] ) );
		$this->assertFalse( OAuthMode::ownApp( [ 'ownApp' => false, 'clientId' => 'c' ] ) );
		$this->assertSame( 'c', $this->type()->clientKey( [ 'clientId' => 'c' ] ), 'Unchanged for a profile saved before the switch.' );
		$this->assertInstanceOf( FakeDestination::class, $this->type()->make( self::connected(), [ 'clientSecret' => 's', 'refreshToken' => 'r', 'grant' => 'g' ] ) );
	}

	public function test_an_lw_app_profile_needs_no_client_secret_and_refreshes_through_the_relay(): void {
		$type = $this->type();

		$this->assertInstanceOf( FakeDestination::class, $type->make( self::relayed(), [ 'refreshToken' => 'r', 'grant' => 'g' ] ) );
		$this->assertInstanceOf( RelayTokenEndpoint::class, $type->endpointFor( [ 'ownApp' => false ] ) );
		$this->assertInstanceOf( DirectTokenEndpoint::class, $type->endpointFor( [ 'ownApp' => true, 'clientId' => 'c' ] ) );
		$this->assertSame( [ '', '' ], [ $type->app( [ 'ownApp' => false ], 'ignored' )->clientId, $type->app( [ 'ownApp' => false ], 'ignored' )->clientSecret ] );
		$this->assertSame( [ 'c', 's' ], [ $type->app( [ 'ownApp' => true, 'clientId' => 'c' ], 's' )->clientId, $type->app( [ 'ownApp' => true, 'clientId' => 'c' ], 's' )->clientSecret ] );
		$this->assertSame( [], $this->sent );
	}

	/**
	 * Review Focus 5: tokens of one mode are never used in the other.
	 */
	public function test_tokens_of_the_other_mode_are_never_used(): void {
		$type            = $this->type();
		$secrets         = [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9999999999:a', 'grant' => 'g' ];
		$toLw            = self::connected();
		$toOwn           = self::relayed();
		$toLw->settings  = [ OAuthMode::OWN_APP => false ];
		$toOwn->settings = [ OAuthMode::OWN_APP => true, 'clientId' => 'c' ];

		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::reason( fn() => $type->make( $toLw, $secrets ) ) );
		$this->assertStringStartsWith( PermanentFailure::REAUTH . ' ', self::reason( fn() => $type->make( $toOwn, $secrets ) ) );
		$this->assertNull( $type->made );
		$this->assertSame( [], $this->sent );
	}

	public function test_the_view_uses_the_rule_of_the_profiles_mode(): void {
		$type                               = self::stored( $this->type() );
		$profile                            = self::relayed();
		$profile->connection['redirectUri'] = 'https://old-domain.example/wp-json/lw-migration/v1/oauth/callback';

		$view  = OAuthView::connection( $type, $profile, [ 'refreshToken', 'grant' ], self::CB );
		$plain = OAuthView::connection( $type, $profile, [ 'refreshToken', 'grant' ], 'http://www.example.com/?rest_route=/lw-migration/v1/oauth/callback' );

		$this->assertTrue( $view['connected'] );
		$this->assertFalse( $view['redirectChanged'], 'Nothing is registered for the LW app: a moved site just connects again.' );
		$this->assertTrue( $view['eligible'] );
		$this->assertSame( [ false, 'https' ], [ $plain['eligible'], $plain['reason'] ] );
	}

	/**
	 * A profile saved before the switch existed loads as its own app in the
	 * admin form; an LW-app profile as the LW app.
	 */
	public function test_the_view_shows_the_mode_even_for_a_profile_saved_before_the_switch(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		$type = self::stored( $this->type() );

		$old = DestinationView::of( new Profile( 'abcd1235', 'fakeoauth', 'E', [ 'clientId' => 'c' ] ), $type, [ 'clientSecret' ], 0 );
		$lw  = DestinationView::of( self::relayed(), $type, [ 'refreshToken', 'grant' ], 0 );

		$this->assertSame( [ 'ownApp' => true, 'clientId' => 'c' ], $old['settings'] );
		$this->assertSame( [ 'ownApp' => false ], $lw['settings'] );
	}
}
