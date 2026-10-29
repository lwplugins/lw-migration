<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavType;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * A password saved for one server reaches only that server: an edit that
 * moves the destination stores the new password for the new server, and
 * neither a failed save nor a reader holding the old settings sends it to
 * the old one. Passwords stored before this binding keep working.
 */
final class DestinationsApiAuthorityTest extends MonkeyTestCase {

	private const OLD_PASSWORD = 'OLD-SERVER-PASSWORD';

	private const NEW_PASSWORD = 'NEW-SERVER-ONLY-PASSWORD';

	private FakeWpdb $db;

	private Destinations $destinations;

	private DestinationsApi $api;

	/** @var array<int, array{0: string, 1: string}> Host => Authorization header of every request sent. */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->db = FakeWpdb::install();
		RecordedHttp::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://wphu.org' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		AddressGuard::useResolver( static fn(): array => [ '93.184.216.34' ] );
		$send               = function ( string $url, array $args ): array {
			$this->sent[] = [ (string) parse_url( $url, PHP_URL_HOST ), (string) ( $args['headers']['Authorization'] ?? '' ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test helper.
			return RecordedHttp::answer( 401 );
		};
		$this->destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ new WebDavType( $send ) ] ) );
		$this->api          = new DestinationsApi( $this->destinations, static fn(): array => [] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * @param string      $host     Server.
	 * @param string|null $password Password to send (null: none).
	 * @return array<string, mixed>
	 */
	private static function body( string $host, ?string $password ): array {
		return [
			'type'     => 'webdav',
			'name'     => 'Offsite',
			'settings' => [
				'url'  => 'https://' . $host . '/dav/',
				'mode' => 'webdav',
				'user' => 'backup',
			],
		] + ( null === $password ? [] : [ 'secrets' => [ 'password' => $password ] ] );
	}

	private function created(): string {
		return (string) $this->api->create( self::body( 'old.example', self::OLD_PASSWORD ) )['id'];
	}

	private function moveToNewServer( string $id ): void {
		$body = self::body( 'new.example', self::NEW_PASSWORD );
		unset( $body['type'] );
		$this->api->update( $id, $body );
	}

	/**
	 * Opens the profile and runs its connection check, as a job would.
	 *
	 * @param Profile $profile The settings the reader holds.
	 */
	private function use( Profile $profile ): ?CloudFailure {
		try {
			$this->destinations->open( $profile )->test();
		} catch ( CloudFailure $e ) {
			return $e;
		}

		return null;
	}

	private function sentTo( string $host, string $password ): bool {
		return in_array( [ $host, 'Basic ' . base64_encode( 'backup:' . $password ) ], $this->sent, true );
	}

	public function test_a_failed_save_of_a_moved_destination_leaves_the_new_password_out_of_the_vault(): void {
		$id               = $this->created();
		$this->db->failOn = Options::DESTINATIONS;

		try {
			$this->moveToNewServer( $id );
			$this->fail( 'The profile could not be stored.' );
		} catch ( RestException $e ) {
			$this->assertSame( 409, $e->status );
		}

		$this->assertSame( 'https://old.example/dav/', $this->api->find( $id )->settings['url'] );
		$this->assertSame( [], $this->destinations->vault()->fields( $id ), 'The password stored for the new server is removed again; the old one was dropped by the move.' );
	}

	/**
	 * A move whose profile write is refused and whose cleanup fails too: the
	 * vault holds only the new server's password, the profile still names
	 * the old server.
	 */
	private function refusedMoveWithFailedCleanup( string $id ): void {
		$db                    = $this->db;
		$this->db->beforeWrite = static function () use ( $db ): void {
			$db->beforeWrite = static function () use ( $db ): void {
				$db->failWrites = true; // The profile write and everything after it fail.
			};
		};
		try {
			$this->moveToNewServer( $id );
			$this->fail( 'The profile could not be stored.' );
		} catch ( RestException $e ) {
			$this->assertSame( 409, $e->status );
		}
		$this->db->failWrites = false;
	}

	public function test_a_failed_save_whose_cleanup_fails_too_never_sends_the_new_password_to_the_old_server(): void {
		$id = $this->created();
		$this->refusedMoveWithFailedCleanup( $id );

		$failure = $this->use( $this->api->find( $id ) );

		$this->assertInstanceOf( TransientFailure::class, $failure );
		$this->assertSame( TransientFailure::STALE, $failure->reason, 'From the outside this is a save still under way; the next edit sets it right.' );
		$this->assertFalse( $this->sentTo( 'old.example', self::NEW_PASSWORD ) );
		$this->assertSame( [], $this->sent, 'Nothing is sent with a password stored for another server.' );
	}

	public function test_after_a_refused_move_with_a_failed_cleanup_a_save_without_the_password_asks_for_it(): void {
		$id = $this->created();
		$this->refusedMoveWithFailedCleanup( $id );
		$body = self::body( 'old.example', null );
		unset( $body['type'] );

		try {
			$this->api->update( $id, $body );
			$this->fail( 'The password stored for another server does not count as stored.' );
		} catch ( RestException $e ) {
			$this->assertSame( [ 400, 'password' ], [ $e->status, $e->data['field'] ?? null ] );
		}
	}

	public function test_after_a_refused_move_with_a_failed_cleanup_a_save_with_the_password_works_again(): void {
		$id = $this->created();
		$this->refusedMoveWithFailedCleanup( $id );
		$body = self::body( 'old.example', 'RETYPED-PASSWORD' );
		unset( $body['type'] );

		$this->api->update( $id, $body );
		$failure = $this->use( $this->api->find( $id ) );

		$this->assertInstanceOf( PermanentFailure::class, $failure, 'The recorded server refuses (401): the connection was made.' );
		$this->assertTrue( $this->sentTo( 'old.example', 'RETYPED-PASSWORD' ) );
		$this->assertFalse( $this->sentTo( 'old.example', self::NEW_PASSWORD ) );
	}

	public function test_a_reader_between_the_secret_and_the_profile_write_never_sends_the_new_password_to_the_old_server(): void {
		$id = $this->created();
		$this->destinations->profiles()->mark( $id, Profile::OK );
		$seen                  = [];
		$db                    = $this->db;
		$this->db->beforeWrite = function () use ( $db, $id, &$seen ): void {
			$db->beforeWrite = function () use ( $id, &$seen ): void {
				$failure = $this->use( $this->destinations->find( $id ) ); // The vault holds the new password, the profile still names the old server.
				$seen    = [ $failure instanceof TransientFailure ? $failure->reason : null, $this->destinations->find( $id )->status ];
			};
		};

		$this->moveToNewServer( $id );

		$this->assertSame( [ TransientFailure::STALE, Profile::OK ], $seen, 'The reader in the window is told to try again, and nothing is recorded on the profile.' );
		$this->assertFalse( $this->sentTo( 'old.example', self::NEW_PASSWORD ) );
		$this->assertSame( 'https://new.example/dav/', $this->api->find( $id )->settings['url'] );
		$this->assertSame( Profile::NEW, $this->api->find( $id )->status, 'The save that follows starts the state over.' );
	}

	public function test_a_reader_holding_the_settings_from_before_the_edit_never_sends_the_new_password_to_the_old_server(): void {
		$id    = $this->created();
		$stale = $this->api->find( $id );
		$this->moveToNewServer( $id );

		$failure = $this->use( $stale );

		$this->assertInstanceOf( TransientFailure::class, $failure, 'The next attempt reads the profile again.' );
		$this->assertSame( TransientFailure::STALE, $failure->reason );
		$this->assertFalse( $this->sentTo( 'old.example', self::NEW_PASSWORD ) );
		$this->assertSame( [], $this->sent );
		$this->assertSame( Profile::NEW, $this->api->find( $id )->status, 'A stale reader never marks the current profile.' );
	}

	public function test_a_stale_connection_test_answers_its_failure_but_records_nothing_on_the_saved_profile(): void {
		$id    = $this->created();
		$stale = $this->api->find( $id ); // Another tab saves the move while this one tests.
		$this->moveToNewServer( $id );
		$this->destinations->profiles()->mark( $id, Profile::OK );
		$failure = $this->use( $stale );
		$this->assertInstanceOf( TransientFailure::class, $failure );

		$answer = $this->api->failed( $stale, $failure );

		$this->assertSame( 422, $answer->status );
		$this->assertSame( [ Profile::OK, '' ], [ $this->api->find( $id )->status, $this->api->find( $id )->message ] );
	}

	public function test_a_reauth_mark_that_races_a_move_leaves_the_moved_profile_alone(): void {
		$id    = $this->created();
		$stale = $this->api->find( $id );
		$this->destinations->profiles()->mark( $id, Profile::OK );
		$this->db->rows[ Options::DESTINATION_SECRETS ]['value'] = (string) json_encode( [ $id => [ 'password' => 'lwm1:no-longer-opens' ] ] );
		$moved = clone $stale;
		$moved->settings['url'] = 'https://new.example/dav/';
		$this->db->beforeWrite  = static function () use ( $moved ): void {
			( new ProfileRepository() )->replace( $moved, false ); // Saved between the reader's look and its mark.
		};

		$failure = $this->use( $stale );

		$this->assertInstanceOf( PermanentFailure::class, $failure );
		$this->assertSame( PermanentFailure::REAUTH, $failure->reason );
		$this->assertSame( [ 'https://new.example/dav/', Profile::OK ], [ $this->api->find( $id )->settings['url'], $this->api->find( $id )->status ] );
	}

	public function test_secrets_that_no_longer_open_still_mark_the_unchanged_profile_reauth(): void {
		$id = $this->created();
		$this->db->rows[ Options::DESTINATION_SECRETS ]['value'] = (string) json_encode( [ $id => [ 'password' => 'lwm1:no-longer-opens' ] ] );

		$failure = $this->use( $this->api->find( $id ) );

		$this->assertInstanceOf( PermanentFailure::class, $failure );
		$this->assertSame( Profile::REAUTH, $this->api->find( $id )->status );
	}

	public function test_the_moved_destination_sends_its_new_password_to_the_new_server(): void {
		$id = $this->created();
		$this->moveToNewServer( $id );

		$this->use( $this->api->find( $id ) );

		$this->assertTrue( $this->sentTo( 'new.example', self::NEW_PASSWORD ) );
		$this->assertSame( [ 'new.example' ], array_unique( array_column( $this->sent, 0 ) ) );
	}

	public function test_an_edit_that_keeps_the_server_keeps_the_password(): void {
		$id = $this->created();
		$this->api->update( $id, [ 'settings' => [ 'url' => 'https://OLD.example/other/', 'mode' => 'webdav', 'user' => 'backup' ] ] );

		$this->use( $this->api->find( $id ) );

		$this->assertTrue( $this->sentTo( 'old.example', self::OLD_PASSWORD ) );
	}

	/**
	 * A password stored before the binding existed (no server named in it)
	 * keeps working for the profile it was saved with, also after an edit
	 * that keeps the server.
	 */
	public function test_a_password_stored_without_a_binding_still_opens_for_its_unchanged_profile(): void {
		$id = $this->created();
		$this->destinations->vault()->put( $id, [ 'password' => 'LEGACY-PASSWORD' ] );
		$this->api->update( $id, [ 'name' => 'Renamed' ] );

		$failure = $this->use( $this->api->find( $id ) );

		$this->assertNotNull( $failure, 'The recorded server refuses (401).' );
		$this->assertTrue( $this->sentTo( 'old.example', 'LEGACY-PASSWORD' ) );
		$this->assertNotSame( Profile::REAUTH, $this->api->find( $id )->status );
	}
}
