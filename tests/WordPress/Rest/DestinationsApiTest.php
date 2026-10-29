<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\GrantRevoker;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthState;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OneDrive\OneDriveType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Type;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpType;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeKeyedType;
use LightweightPlugins\Migration\Tests\Support\FakeOAuthType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DestinationsApiTest extends MonkeyTestCase {

	private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	private RecordedHttp $http;

	private FakeWpdb $db;

	/** @var array<int, Schedule> */
	private array $schedules = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->db = FakeWpdb::install();
		RecordedHttp::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->http = new RecordedHttp();
		FakeKeyedType::reset();
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	private function api(): DestinationsApi {
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ new S3Type( $this->http->send() ) ] ) );

		return new DestinationsApi( $destinations, fn(): array => $this->schedules );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function body(): array {
		return [
			'type'     => 's3',
			'name'     => 'Offsite',
			'settings' => [
				'preset'    => 'custom',
				'endpoint'  => 'http://127.0.0.1:9000',
				'bucket'    => 'bkt',
				'accessKey' => 'minioadmin',
			],
			'secrets'  => [ 'secretKey' => self::SECRET ],
		];
	}

	private static function code( \Closure $call ): string {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e->status . ' ' . $e->errorCode . ' ' . ( $e->data['field'] ?? '' );
		}
		return 'accepted';
	}

	/**
	 * Review Focus 3.
	 */
	public function test_no_answer_carries_a_secret(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$answers = [ $created, $api->all(), $api->update( $created['id'], [ 'name' => 'Renamed', 'secrets' => [ 'secretKey' => '' ] ] ) ];

		foreach ( $answers as $answer ) {
			$json = (string) json_encode( $answer );
			$this->assertStringNotContainsString( self::SECRET, $json );
			$this->assertStringNotContainsString( 'lwm1:', $json, 'Not even the sealed value.' );
		}
		$this->assertSame( [ 'secretKey' => [ 'set' => true ] ], $created['secrets'] );
		$this->assertArrayNotHasKey( 'secretKey', $created['settings'] );
		$this->assertTrue( $created['insecure'] );
		$this->assertSame( 'bkt · Other S3-compatible storage', $created['where'] );
	}

	public function test_an_empty_secret_keeps_the_stored_one_and_a_new_one_replaces_it(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$api->update( $created['id'], [ 'secrets' => [ 'secretKey' => '' ] ] );
		$this->assertSame( [ 'secretKey' => self::SECRET ], ( new SecretVault() )->open( $created['id'] ) );

		$api->update( $created['id'], [ 'secrets' => [ 'secretKey' => 'NEW-SECRET-0000' ] ] );
		$this->assertSame( [ 'secretKey' => 'NEW-SECRET-0000' ], ( new SecretVault() )->open( $created['id'] ) );
	}

	public function test_a_moved_endpoint_needs_the_secret_again_and_a_refused_edit_changes_nothing(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$moved   = [ 'settings' => [ 'preset' => 'custom', 'endpoint' => 'https://s3.attacker.example', 'bucket' => 'bkt', 'accessKey' => 'minioadmin' ] ];

		$this->assertSame( 'secretKey', self::fieldOf( static fn() => $api->update( $created['id'], $moved ) ) );
		$this->assertSame( [ 'secretKey' => self::SECRET ], ( new SecretVault() )->open( $created['id'] ), 'A refused edit keeps the stored secret.' );
		$this->assertSame( 'http://127.0.0.1:9000', $api->find( $created['id'] )->settings['endpoint'] );

		$api->update( $created['id'], $moved + [ 'secrets' => [ 'secretKey' => 'NEW-SECRET-0000' ] ] );
		$this->assertSame( [ 'secretKey' => 'NEW-SECRET-0000' ], ( new SecretVault() )->open( $created['id'] ) );
	}

	private function sftpApi(): DestinationsApi {
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ new SftpType() ] ) );

		return new DestinationsApi( $destinations, fn(): array => $this->schedules );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function sftpBody( string $host ): array {
		return [
			'type'     => 'sftp',
			'name'     => 'Box',
			'settings' => [ 'host' => $host, 'user' => 'u', 'auth' => 'key' ],
			'secrets'  => [ 'privateKey' => "-----BEGIN KEY-----\nabc\n-----END KEY-----", 'passphrase' => 'old-pass' ],
		];
	}

	public function test_a_moved_server_leaves_no_old_secret_in_the_vault(): void {
		$api     = $this->sftpApi();
		$created = $api->create( self::sftpBody( 'sftp.example.com' ) );
		( new SecretVault() )->put( $created['id'], [ 'password' => 'old-password' ] );

		$api->update( $created['id'], [ 'settings' => [ 'host' => 'other.example.com', 'user' => 'u', 'auth' => 'key' ], 'secrets' => [ 'privateKey' => "-----BEGIN KEY-----\nxyz\n-----END KEY-----" ] ] );

		$this->assertSame( [ 'privateKey' ], ( new SecretVault() )->fields( $created['id'] ), 'The old password and passphrase are gone; the key just sent is stored.' );
		$this->assertSame( 'other.example.com', $api->find( $created['id'] )->settings['host'] );
	}

	public function test_a_move_whose_old_secrets_cannot_be_dropped_is_refused_and_leaves_the_profile_unmoved(): void {
		$api     = $this->sftpApi();
		$created = $api->create( self::sftpBody( 'sftp.example.com' ) );
		$this->db->failOn = Options::DESTINATION_SECRETS;

		$answer = self::code( fn() => $api->update( $created['id'], [ 'settings' => [ 'host' => 'other.example.com', 'user' => 'u', 'auth' => 'key' ], 'secrets' => [ 'privateKey' => "-----BEGIN KEY-----\nxyz\n-----END KEY-----" ] ] ) );

		$this->assertSame( '409 lw_migration_destination_storage ', $answer );
		$this->assertSame( 'sftp.example.com', $api->find( $created['id'] )->settings['host'], 'The profile is not moved while the old secrets stay.' );
	}

	private static function fieldOf( \Closure $call ): string {
		try {
			$call();
		} catch ( RestException $e ) {
			return (string) ( $e->data['field'] ?? '' );
		}
		return 'accepted';
	}

	public function test_a_new_destination_needs_its_secret_and_valid_settings(): void {
		$api = $this->api();

		$this->assertSame( '400 lw_migration_invalid secretKey', self::code( fn() => $api->create( array_merge( self::body(), [ 'secrets' => [] ] ) ) ) );
		$this->assertSame( '400 lw_migration_invalid bucket', self::code( fn() => $api->create( array_replace_recursive( self::body(), [ 'settings' => [ 'bucket' => 'a/b' ] ] ) ) ) );
		$this->assertSame( '400 lw_migration_invalid type', self::code( fn() => $api->create( array_merge( self::body(), [ 'type' => 'gdrive' ] ) ) ) );
		$this->assertSame( '400 lw_migration_invalid name', self::code( fn() => $api->create( array_merge( self::body(), [ 'name' => " \t" ] ) ) ) );
		$this->assertSame( '400 lw_migration_invalid endpoint', self::code( fn() => $api->create( array_replace_recursive( self::body(), [ 'settings' => [ 'endpoint' => 'http://169.254.169.254' ] ] ) ) ) );
		$this->assertNull( $this->db->value( Options::DESTINATIONS ), 'Nothing stored on a refusal.' );
		$this->assertNull( $this->db->value( Options::DESTINATION_SECRETS ), 'No secret stored on a refusal.' );
	}

	public function test_a_refusal_never_repeats_the_submitted_secret(): void {
		$api  = $this->api();
		$body = array_replace_recursive( self::body(), [ 'secrets' => [ 'secretKey' => self::SECRET . "\0" ] ] );

		try {
			$api->create( $body );
			$this->fail( 'Refused.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'secretKey', $e->data['field'] );
			$this->assertStringNotContainsString( self::SECRET, $e->getMessage() . json_encode( $e->data ) );
		}
	}

	public function test_a_twenty_first_destination_is_refused_and_leaves_no_secret_behind(): void {
		$api = $this->api();
		for ( $i = 0; $i < ProfileRepository::MAX; $i++ ) {
			$api->create( self::body() );
		}

		$this->assertSame( '409 lw_migration_destinations_full ', self::code( fn() => $api->create( self::body() ) ) );
		$this->assertCount( ProfileRepository::MAX, (array) json_decode( (string) $this->db->value( Options::DESTINATION_SECRETS ), true ) );
	}

	public function test_a_server_without_sodium_is_refused_before_anything_is_stored(): void {
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault( null, false ), new DestinationRegistry( [ new S3Type( $this->http->send() ) ] ) );
		$api          = new DestinationsApi( $destinations, fn(): array => $this->schedules );

		$this->assertSame( '409 lw_migration_destination_storage ', self::code( fn() => $api->create( self::body() ) ) );
		$this->assertNull( $this->db->value( Options::DESTINATIONS ) );
	}

	public function test_a_failed_connection_test_is_422_with_its_reason_and_marks_the_profile(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$this->http->push( RecordedHttp::answer( 403, '<Error><Code>AccessDenied</Code></Error>' ) );

		try {
			$api->test( $created['id'] );
			$this->fail( 'Refused.' );
		} catch ( RestException $e ) {
			$this->assertSame( [ 422, 'lw_migration_destination_failed', PermanentFailure::DENIED, false ], [ $e->status, $e->errorCode, $e->data['reason'], $e->data['transient'] ] );
			$this->assertStringNotContainsString( self::SECRET, $e->getMessage() );
		}
		$this->assertSame( Profile::ERROR, $api->find( $created['id'] )->status );
	}

	/**
	 * M7c final review m2: a refused test marks `reauth` only while the
	 * profile still holds the connection the test used; a reconnect made
	 * during the test keeps its fresh state.
	 */
	public function test_a_credentials_refusal_marks_reauth_only_for_the_connection_the_test_used(): void {
		$d       = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$api     = new DestinationsApi( $d, static fn(): array => [] );
		$connect = static function ( int $at ) use ( $d ): void {
			$d->profiles()->connect( 'aaaa1111', [ 'connectedAt' => $at, 'client' => 'mark', 'id' => sprintf( '%032x', $at ) ], static fn(): bool => true );
		};
		$connect( 900 );
		FakeCloud::$remotes['aaaa1111']->failNext( 'test', new PermanentFailure( 'Connect again.', PermanentFailure::REAUTH ) );
		FakeCloud::$onOpen = static fn() => $connect( 950 ); // Reconnected while the test runs.

		self::code( fn() => $api->test( 'aaaa1111' ) );
		$this->assertSame( [ Profile::NEW, 950 ], [ $api->find( 'aaaa1111' )->status, $api->find( 'aaaa1111' )->connection['connectedAt'] ] );

		FakeCloud::$onOpen = null;
		FakeCloud::$remotes['aaaa1111']->failNext( 'test', new PermanentFailure( 'Connect again.', PermanentFailure::REAUTH ) );
		self::code( fn() => $api->test( 'aaaa1111' ) );
		$this->assertSame( Profile::REAUTH, $api->find( 'aaaa1111' )->status );
	}

	public function test_new_settings_or_secrets_reset_the_state_and_a_rename_keeps_it(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		( new ProfileRepository() )->mark( $created['id'], Profile::ERROR, 'Old failure.' );

		$this->assertSame( [ Profile::ERROR, 'Old failure.' ], array_values( array_intersect_key( $api->update( $created['id'], [ 'name' => 'Renamed' ] ), [ 'status' => 1, 'message' => 1 ] ) ) );
		$this->assertSame( Profile::NEW, $api->update( $created['id'], [ 'settings' => array_merge( self::body()['settings'], [ 'bucket' => 'other' ] ) ] )['status'] );
	}

	public function test_delete_removes_profile_secrets_and_copies_and_counts_the_schedules_that_named_it(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		CloudCopies::mark( 'a.wpress', $created['id'], CloudCopies::UP, 1 );
		$this->schedules = [ Schedule::fromArray( [ 'id' => '0a1b2c3d', 'destinations' => [ [ 'id' => $created['id'] ] ] ] ) ];

		$this->assertSame( 1, $api->view( $api->find( $created['id'] ) )['usedBy'] );
		$this->assertSame( [ 'deleted' => true, 'usedBy' => 1 ], $api->delete( $created['id'] ) );

		$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->find( $created['id'] ) ) );
		$this->assertSame( [], ( new SecretVault() )->fields( $created['id'] ) );
		$this->assertSame( [], CloudCopies::all() );
	}

	public function test_a_delete_whose_copies_cannot_be_forgotten_says_so(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		CloudCopies::mark( 'a.wpress', $created['id'], CloudCopies::UP, 1 );
		$this->db->failOn = Options::CLOUD_COPIES;

		$answer = $api->delete( $created['id'] );

		$this->assertTrue( $answer['deleted'] );
		$this->assertCount( 1, $answer['warnings'] );
		$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->find( $created['id'] ) ) );
	}

	public function test_a_malformed_or_unknown_id_is_404_and_writes_nothing(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$before  = [ $this->db->value( Options::DESTINATIONS ), $this->db->value( Options::DESTINATION_SECRETS ) ];

		foreach ( [ strtoupper( $created['id'] ), '../../x', '', '0a1b2c3d', $created['id'] . '0' ] as $id ) {
			$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->update( $id, [ 'name' => 'X' ] ) ), $id );
			$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->delete( $id ) ), $id );
			$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->test( $id ) ), $id );
		}
		$this->assertSame( $before, [ $this->db->value( Options::DESTINATIONS ), $this->db->value( Options::DESTINATION_SECRETS ) ] );
	}

	public function test_the_list_carries_the_type_schema(): void {
		$all = $this->api()->all();

		$this->assertSame( [], $all['items'] );
		$this->assertSame( 's3', $all['types'][0]['type'] );
	}

	public function test_an_update_that_races_a_delete_is_404_and_stores_nothing(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$this->db->beforeWrite = static function () use ( $created ): void {
			( new Destinations( new ProfileRepository(), new SecretVault(), new DestinationRegistry( [] ) ) )->forget( $created['id'] );
		};

		$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->update( $created['id'], [ 'name' => 'Renamed', 'secrets' => [ 'secretKey' => 'NEW-SECRET-0000' ] ] ) ) );
		$this->assertSame( [], json_decode( (string) $this->db->value( Options::DESTINATIONS ), true ) );
		$this->assertSame( [], ( new SecretVault() )->fields( $created['id'] ), 'No secret left under the gone id.' );
	}

	public function test_a_rename_keeps_a_state_a_job_recorded_meanwhile(): void {
		$api     = $this->api();
		$created = $api->create( self::body() );
		$this->db->beforeWrite = static function () use ( $created ): void {
			( new ProfileRepository() )->mark( $created['id'], Profile::OK, '', 900 );
		};

		$view = $api->update( $created['id'], [ 'name' => 'Renamed' ] );

		$this->assertSame( [ 'Renamed', Profile::OK, 900 ], [ $view['name'], $view['status'], $view['lastUploadAt'] ] );
		$this->assertSame( Profile::OK, $api->find( $created['id'] )->status );
	}

	public function test_the_view_shows_only_the_types_non_secret_settings(): void {
		$api = $this->api();
		( new ProfileRepository() )->save( new Profile( 'abcd1234', 's3', 'Planted', [ 'bucket' => 'bkt', 'secretKey' => 'PLANTED-SECRET', 'junk' => 'x' ] ) );
		( new ProfileRepository() )->save( new Profile( 'abcd5678', 'gone', 'Unknown', [ 'password' => 'PLANTED-SECRET' ] ) );

		$this->assertSame( [ 'bucket' => 'bkt' ], $api->view( $api->find( 'abcd1234' ) )['settings'] );
		$this->assertSame( [], $api->view( $api->find( 'abcd5678' ) )['settings'] );
		$this->assertStringNotContainsString( 'PLANTED-SECRET', (string) json_encode( $api->all() ) );
	}

	private const SHOWN = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';

	private static function unknownKey(): PermanentFailure {
		return new PermanentFailure(
			'sftp://example.com:22 shows the host key ' . self::SHOWN . '.',
			PermanentFailure::HOST_KEY_UNKNOWN,
			0,
			null,
			[
				'hostKey'     => self::SHOWN,
				'hostKeyType' => 'ssh-ed25519',
			]
		);
	}

	/**
	 * A stored "keyed" profile aaaa1111 (password login) and the API over it.
	 *
	 * @param array<string, mixed> $settings Its settings.
	 */
	private static function keyed( array $settings = [ 'host' => 'example.com' ] ): DestinationsApi {
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), FakeKeyedType::registry() );
		$destinations->profiles()->save( new Profile( 'aaaa1111', FakeKeyedType::KEY, 'Box', $settings ) );
		$destinations->vault()->put( 'aaaa1111', [ 'password' => 'the-password' ] );

		return new DestinationsApi( $destinations, static fn(): array => [] );
	}

	private static function stored(): ?Profile {
		return ( new ProfileRepository() )->find( 'aaaa1111' );
	}

	public function test_a_failed_test_answers_the_failures_facts(): void {
		$api = new DestinationsApi( FakeCloud::install( [ 'aaaa1111' => 'Box' ] ), static fn(): array => [] );
		FakeCloud::$remotes['aaaa1111']->failNext( 'test', self::unknownKey() );

		try {
			$api->test( 'aaaa1111' );
			$this->fail( 'The host key is not confirmed.' );
		} catch ( RestException $e ) {
			$this->assertSame( 422, $e->status );
			$this->assertSame( 'host_key_unknown', $e->data['reason'] );
			$this->assertSame( self::SHOWN, $e->data['hostKey'] );
			$this->assertSame( 'ssh-ed25519', $e->data['hostKeyType'] );
			$this->assertFalse( $e->data['transient'] );
		}
		$this->assertSame( Profile::ERROR, ( new ProfileRepository() )->find( 'aaaa1111' )->status );
	}

	public function test_trust_stores_the_fingerprint_the_server_shows_and_tests_again(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', self::unknownKey() );

		$view = $api->trust( 'aaaa1111', [ 'hostKey' => self::SHOWN ] );

		$this->assertSame( self::SHOWN, $view['settings']['hostKey'] );
		$this->assertSame( 'ok', $view['status'] );
		$this->assertSame( [ self::SHOWN, 'ssh-ed25519' ], [ self::stored()->settings['hostKey'], self::stored()->settings['hostKeyType'] ], 'P-M8: the key type is kept with it.' );
	}

	/**
	 * Review Focus 3: a fingerprint the server does not show now is never stored.
	 */
	public function test_trust_refuses_another_fingerprint_and_stores_nothing(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', self::unknownKey() );

		try {
			$api->trust( 'aaaa1111', [ 'hostKey' => 'SHA256:' . str_repeat( 'B', 43 ) ] );
			$this->fail( 'Another key.' );
		} catch ( RestException $e ) {
			$this->assertSame( 'lw_migration_host_key_differs', $e->errorCode );
			$this->assertSame( 409, $e->status );
			$this->assertSame( [ 'hostKey' => self::SHOWN, 'hostKeyType' => 'ssh-ed25519' ], $e->data, 'The key shown now and its type, so the page can show it as a changed key.' );
		}
		$this->assertArrayNotHasKey( 'hostKey', self::stored()->settings );
	}

	public function test_trust_refuses_a_malformed_fingerprint_and_a_type_without_a_host_key(): void {
		$this->assertSame( '400 lw_migration_invalid hostKey', self::code( fn() => self::keyed()->trust( 'aaaa1111', [ 'hostKey' => 'md5:ab' ] ) ) );
		$this->assertSame( '400 lw_migration_invalid hostKey', self::code( fn() => self::keyed()->trust( 'aaaa1111', [ 'hostKey' => [ self::SHOWN ] ] ) ) );
		$this->assertSame( [], FakeKeyedType::remote( 'aaaa1111' )->calls, 'Refused before any connection.' );

		$api = new DestinationsApi( FakeCloud::install( [ 'bbbb2222' => 'Fake' ] ), static fn(): array => [] );
		$this->assertSame( '400 lw_migration_invalid hostKey', self::code( fn() => $api->trust( 'bbbb2222', [ 'hostKey' => self::SHOWN ] ) ) );
		$this->assertSame( [], FakeCloud::$remotes['bbbb2222']->calls );
		$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->trust( 'cccc3333', [ 'hostKey' => self::SHOWN ] ) ) );
	}

	public function test_trust_answers_another_failure_as_the_test_does(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', new PermanentFailure( 'Login refused.', PermanentFailure::AUTH, 0 ) );

		$this->assertSame( '422 lw_migration_destination_failed ', self::code( fn() => $api->trust( 'aaaa1111', [ 'hostKey' => self::SHOWN ] ) ) );
		$this->assertArrayNotHasKey( 'hostKey', self::stored()->settings );
		$this->assertSame( Profile::ERROR, self::stored()->status );
	}

	/**
	 * P-I3: the profile is read again after the connection; a profile deleted meanwhile stays deleted.
	 */
	public function test_trust_never_brings_back_a_profile_deleted_during_the_check(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', self::unknownKey() );
		FakeKeyedType::$onOpen = static function (): void {
			FakeKeyedType::$onOpen = null;
			( new ProfileRepository() )->delete( 'aaaa1111' );
		};

		$this->assertSame( '404 lw_migration_destination_missing ', self::code( fn() => $api->trust( 'aaaa1111', [ 'hostKey' => self::SHOWN ] ) ) );
		$this->assertSame( [], ( new ProfileRepository() )->all() );
	}

	public function test_trust_keeps_a_rename_made_during_the_check(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', self::unknownKey() );
		FakeKeyedType::$onOpen = static function (): void {
			FakeKeyedType::$onOpen = null;
			$profile       = ( new ProfileRepository() )->find( 'aaaa1111' );
			$profile->name = 'Renamed';
			( new ProfileRepository() )->save( $profile );
		};

		$view = $api->trust( 'aaaa1111', [ 'hostKey' => self::SHOWN ] );

		$this->assertSame( [ 'Renamed', self::SHOWN ], [ $view['name'], $view['settings']['hostKey'] ] );
	}

	/**
	 * P-M14: another server is another key.
	 */
	public function test_a_new_host_forgets_the_confirmed_key_unless_a_new_one_is_sent(): void {
		$api = self::keyed(
			[
				'host'        => 'example.com',
				'hostKey'     => self::SHOWN,
				'hostKeyType' => 'ssh-ed25519',
			]
		);

		$view = $api->update( 'aaaa1111', [ 'settings' => [ 'host' => 'other.example.com', 'hostKey' => self::SHOWN ] ] );
		$this->assertArrayNotHasKey( 'hostKey', $view['settings'] );
		$this->assertArrayNotHasKey( 'hostKey', self::stored()->settings );

		$other = 'SHA256:' . str_repeat( 'C', 43 );
		$view  = $api->update( 'aaaa1111', [ 'settings' => [ 'host' => 'third.example.com', 'hostKey' => $other ] ] );
		$this->assertSame( $other, $view['settings']['hostKey'] );
		$view = $api->update( 'aaaa1111', [ 'settings' => [ 'host' => 'third.example.com', 'hostKey' => $other ] ] );
		$this->assertSame( $other, $view['settings']['hostKey'], 'The same host keeps its key.' );
	}

	/**
	 * P-M8: the form never shows the key type, so an edit that keeps the host,
	 * the port and the key keeps the type stored with the key.
	 */
	public function test_an_edit_that_keeps_the_address_and_the_key_keeps_the_key_type(): void {
		$api = self::keyed(
			[
				'host'        => 'example.com',
				'hostKey'     => self::SHOWN,
				'hostKeyType' => 'ecdsa-sha2-nistp256',
			]
		);

		$api->update( 'aaaa1111', [ 'settings' => [ 'host' => 'example.com', 'hostKey' => self::SHOWN ] ] );
		$this->assertSame( 'ecdsa-sha2-nistp256', self::stored()->settings['hostKeyType'] ?? null );

		$api->update( 'aaaa1111', [ 'settings' => [ 'host' => 'example.com', 'hostKey' => 'SHA256:' . str_repeat( 'C', 43 ) ] ] );
		$this->assertArrayNotHasKey( 'hostKeyType', self::stored()->settings, 'Another key, another type.' );
	}

	/**
	 * Review m3: a key checked against one address is never stored for another.
	 */
	public function test_trust_stores_nothing_when_the_address_changed_during_the_check(): void {
		$api = self::keyed();
		FakeKeyedType::remote( 'aaaa1111' )->failNext( 'test', self::unknownKey() );
		FakeKeyedType::$onOpen = static function (): void {
			FakeKeyedType::$onOpen = null;
			$profile                   = ( new ProfileRepository() )->find( 'aaaa1111' );
			$profile->settings['host'] = 'other.example.com';
			( new ProfileRepository() )->save( $profile );
		};

		$this->assertSame( '409 lw_migration_destination_changed ', self::code( fn() => $api->trust( 'aaaa1111', [ 'hostKey' => self::SHOWN ] ) ) );
		$this->assertSame( [ 'host' => 'other.example.com' ], self::stored()->settings );
	}

	/**
	 * @return array{0: DestinationsApi, 1: Destinations}
	 */
	private function oauthApi(): array {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'rest_url' )->justReturn( 'https://site.test/wp-json/lw-migration/v1/oauth/callback' );
		AddressGuard::useResolver( static fn( string $host ): array => [ '203.0.113.10' ] );
		$send         = ( new CurlHooks() )->install()->wrap( $this->http->send() ); // Revokes (#6) are recorded, never sent.
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ new FakeOAuthType( null, null, $send ), new OneDriveType( null, null, $send ) ] ) );

		return [ new DestinationsApi( $destinations, fn(): array => $this->schedules ), $destinations ];
	}

	/**
	 * A connected OAuth profile (Client ID "old") with its three token fields.
	 *
	 * @param Destinations $destinations Store.
	 * @param DestinationsApi $api       API.
	 */
	private static function connectedOAuth( Destinations $destinations, DestinationsApi $api ): string {
		$id = $api->create( [ 'type' => 'fakeoauth', 'name' => 'Drive', 'settings' => [ 'clientId' => 'old' ], 'secrets' => [ 'clientSecret' => 's' ] ] )['id'];
		$destinations->profiles()->connect( $id, [ 'account' => 'a@example.com', 'client' => ( new OAuthState() )->clientMark( 'old' ), 'id' => TokenKeeper::connectionId( 'g' ) ], static fn( array $settings ): bool => 'old' === ( $settings['clientId'] ?? null ) );
		$destinations->vault()->put( $id, [ 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ] );

		return $id;
	}

	public function test_updating_the_client_id_drops_the_stored_tokens(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = self::connectedOAuth( $destinations, $api );
		$this->assertTrue( $api->view( $api->find( $id ) )['connection']['connected'], 'Connected before the edit.' );
		$this->http->push( RecordedHttp::answer( 200 ) );

		$view = $api->update(
			$id,
			[
				'settings' => [ 'clientId' => 'new' ],
				'secrets'  => [ 'clientSecret' => 'new-secret' ],
			]
		);

		$this->assertSame( [ 'clientSecret' => 'new-secret' ], $destinations->vault()->open( $id ) );
		$this->assertSame( [], $destinations->find( $id )->connection );
		$this->assertFalse( $view['connection']['connected'] );
		$this->assertSame( '', $view['connection']['account'] );
		$this->assertSame( Profile::NEW, $view['status'] );
		$this->assertSame( GrantRevoker::GOOGLE, $this->http->requests[0][0], 'The old grant is withdrawn at the provider (#6).' );
	}

	public function test_a_new_client_secret_keeps_the_connection_and_the_tokens(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = self::connectedOAuth( $destinations, $api );

		$view = $api->update( $id, [ 'secrets' => [ 'clientSecret' => 'rotated' ] ] );

		$this->assertSame( [ 'clientSecret' => 'rotated', 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ], $destinations->vault()->open( $id ) );
		$this->assertTrue( $view['connection']['connected'] );
		$this->assertSame( 'a@example.com', $view['connection']['account'] );
	}

	public function test_a_token_field_is_refused_and_never_stored(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = self::connectedOAuth( $destinations, $api );

		$this->assertSame( '400 lw_migration_invalid refreshToken', self::code( static fn() => $api->update( $id, [ 'secrets' => [ 'refreshToken' => 'evil' ] ] ) ) );
		$this->assertSame( 'r', $destinations->vault()->open( $id )['refreshToken'] );
	}

	public function test_a_new_client_id_needs_a_new_client_secret(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = self::connectedOAuth( $destinations, $api );

		$this->assertSame( '400 lw_migration_invalid clientSecret', self::code( static fn() => $api->update( $id, [ 'settings' => [ 'clientId' => 'new' ] ] ) ) );
		$this->assertSame( [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ], $destinations->vault()->open( $id ) );
		$this->assertSame( 'old', $destinations->find( $id )->settings['clientId'] );
		$this->assertNotSame( [], $destinations->find( $id )->connection );
	}

	public function test_a_client_id_change_that_cannot_be_stored_keeps_the_old_connection_and_its_tokens(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = self::connectedOAuth( $destinations, $api );
		$this->db->failOn       = Options::DESTINATIONS;

		$answer = self::code(
			static fn() => $api->update(
				$id,
				[
					'settings' => [ 'clientId' => 'new' ],
					'secrets'  => [ 'clientSecret' => 'new-secret' ],
				]
			)
		);

		$this->assertSame( '409 lw_migration_destination_storage ', $answer );
		$this->assertSame( [ 'clientSecret', 'refreshToken', 'accessToken', 'grant' ], $destinations->vault()->fields( $id ), 'The tokens are dropped only after the profile was stored.' );
		$this->assertSame( 'old', $destinations->find( $id )->settings['clientId'] );
		$this->assertNotSame( [], $destinations->find( $id )->connection );
		$this->assertSame( [], $this->http->requests, 'A refused edit never withdraws the grant it keeps (#6).' );
	}

	/**
	 * Task 10 ruling: a OneDrive tenant or permission change forgets the
	 * connection and its tokens like a Client-ID change (they came from
	 * another authority or consent), but keeps the client secret; the same
	 * values (another spelling of the tenant) keep both.
	 *
	 * @return array<string, array{0: array<string, string>, 1: bool}>
	 */
	public static function oneDriveEdits(): array {
		return [
			'another tenant'                => [ [ 'tenant' => 'organizations' ], false ],
			'another permission'            => [ [ 'access' => 'files' ], false ],
			'the same tenant spelled again' => [ [ 'tenant' => ' COMMON ' ], true ],
		];
	}

	/**
	 * @param array<string, string> $edit  Changed settings.
	 * @param bool                  $keeps Whether the connection survives.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'oneDriveEdits' )]
	public function test_a_onedrive_tenant_or_permission_change_forgets_the_tokens_but_keeps_the_secret( array $edit, bool $keeps ): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = $api->create( [ 'type' => 'onedrive', 'name' => 'OneDrive', 'settings' => [ 'clientId' => 'c' ], 'secrets' => [ 'clientSecret' => 's' ] ] )['id'];
		$destinations->profiles()->connect( $id, [ 'account' => 'a@example.com', 'client' => ( new OAuthState() )->clientMark( ( new OneDriveType() )->clientKey( [ 'clientId' => 'c' ] ) ), 'id' => TokenKeeper::connectionId( 'g' ) ], static fn( array $settings ): bool => 'c' === ( $settings['clientId'] ?? null ) );
		$destinations->vault()->put( $id, [ 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ] );

		$view = $api->update( $id, [ 'settings' => [ 'clientId' => 'c' ] + $edit ] );

		$this->assertSame( $keeps, $view['connection']['connected'] );
		$this->assertSame( $keeps ? [ 'clientSecret' => 's', 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ] : [ 'clientSecret' => 's' ], $destinations->vault()->open( $id ) );
		$this->assertSame( $keeps, [] !== $destinations->find( $id )->connection );
	}

	public function test_a_onedrive_tenant_change_that_cannot_be_stored_keeps_the_old_connection_and_its_tokens(): void {
		[ $api, $destinations ] = $this->oauthApi();
		$id                     = $api->create( [ 'type' => 'onedrive', 'name' => 'OneDrive', 'settings' => [ 'clientId' => 'c' ], 'secrets' => [ 'clientSecret' => 's' ] ] )['id'];
		$destinations->profiles()->connect( $id, [ 'account' => 'a@example.com', 'client' => ( new OAuthState() )->clientMark( ( new OneDriveType() )->clientKey( [ 'clientId' => 'c' ] ) ), 'id' => TokenKeeper::connectionId( 'g' ) ], static fn( array $settings ): bool => 'c' === ( $settings['clientId'] ?? null ) );
		$destinations->vault()->put( $id, [ 'refreshToken' => 'r', 'accessToken' => '9:a', 'grant' => 'g' ] );
		$this->db->failOn = Options::DESTINATIONS;

		$answer = self::code( static fn() => $api->update( $id, [ 'settings' => [ 'clientId' => 'c', 'tenant' => 'organizations' ] ] ) );

		$this->assertSame( '409 lw_migration_destination_storage ', $answer );
		$this->assertSame( [ 'clientSecret', 'refreshToken', 'accessToken', 'grant' ], $destinations->vault()->fields( $id ), 'The tokens are dropped only after the profile was stored.' );
		$this->assertSame( 'common', $destinations->find( $id )->settings['tenant'] );
	}
}
