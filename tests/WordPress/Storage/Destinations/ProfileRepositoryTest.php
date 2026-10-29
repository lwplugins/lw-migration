<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ProfileRepositoryTest extends MonkeyTestCase {

	/** Connection ids (32 hex digits, TokenKeeper::connectionId()). */
	private const C0 = '00000000000000000000000000000000';

	private const C1 = '11111111111111111111111111111111';

	private const C2 = '22222222222222222222222222222222';

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
	}

	private static function repo(): ProfileRepository {
		return new ProfileRepository( static fn(): int => 1000 );
	}

	/**
	 * @return mixed The stored row, decoded.
	 */
	private function stored() {
		return json_decode( (string) $this->db->value( Options::DESTINATIONS ), true );
	}

	public function test_saves_finds_updates_and_deletes_a_profile(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite', [ 'bucket' => 'b' ] ) );
		$repo->save( new Profile( 'abcd1234', 's3', 'Renamed', [ 'bucket' => 'c' ] ) );

		$this->assertCount( 1, $repo->all() );
		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ 'Renamed', [ 'bucket' => 'c' ], 1000, 1000 ], [ $found->name, $found->settings, $found->createdAt, $found->updatedAt ] );
		$this->assertTrue( $repo->delete( 'abcd1234' ) );
		$this->assertNull( $repo->find( 'abcd1234' ) );
		$this->assertFalse( $repo->delete( 'abcd1234' ) );
	}

	public function test_the_row_is_a_plain_list_never_an_object(): void {
		self::repo()->save( new Profile( 'abcd1234', 's3', 'Offsite' ) );

		$this->assertSame( 'off', $this->db->rows[ Options::DESTINATIONS ]['autoload'] );
		$this->assertStringNotContainsString( 'O:', (string) $this->db->value( Options::DESTINATIONS ) );
		$this->assertSame( array_values( $this->stored() ), $this->stored() );
		$this->assertIsArray( $this->stored()[0] );
	}

	public function test_a_twenty_first_profile_is_refused(): void {
		$repo = self::repo();
		for ( $i = 0; $i < ProfileRepository::MAX; $i++ ) {
			$repo->save( new Profile( sprintf( '%08x', $i ), 's3', 'P' . $i ) );
		}

		try {
			$repo->save( new Profile( 'ffffffff', 's3', 'One too many' ) );
			$this->fail( 'A 21st profile is refused.' );
		} catch ( \LengthException $e ) {
			$this->assertCount( ProfileRepository::MAX, $this->stored() );
		}
	}

	public function test_mark_records_the_state_and_the_last_upload(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite' ) );
		$repo->mark( 'abcd1234', Profile::OK, '', 900 );
		$repo->mark( 'abcd1234', Profile::ERROR, 'Access denied.' );

		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ Profile::ERROR, 'Access denied.', 900 ], [ $found->status, $found->message, $found->lastUploadAt ] );
	}

	public function test_damaged_rows_are_skipped_and_values_clamped(): void {
		$this->db = FakeWpdb::install(
			[
				Options::DESTINATIONS => (string) json_encode(
					[
						'not a row',
						[ 'id' => '../x' ],
						[
							'id'        => 'abcd1234',
							'type'      => 's3',
							'name'      => str_repeat( 'n', 300 ),
							'status'    => 'weird',
							'retention' => [ 'count' => -5 ],
						],
					]
				),
			]
		);

		$all = self::repo()->all();

		$this->assertCount( 1, $all );
		$this->assertSame( Profile::NAME_LENGTH, mb_strlen( $all[0]->name ) );
		$this->assertSame( Profile::NEW, $all[0]->status );
		$this->assertSame( [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], $all[0]->retention );
	}

	/**
	 * Review I1: a long job request whose options cache still holds the list from
	 * its start must neither bring back a profile an admin deleted meanwhile nor
	 * roll back the admin's edit when it records an upload.
	 */
	public function test_mark_reads_the_row_itself_never_a_stale_cached_list(): void {
		$stale = [
			( new Profile( 'abcd1234', 's3', 'Deleted meanwhile' ) )->toArray(),
			( new Profile( 'eeee1111', 's3', 'Offsite', [ 'bucket' => 'old' ] ) )->toArray(),
		];
		$this->db = FakeWpdb::install( [ Options::DESTINATIONS => (string) json_encode( [ ( new Profile( 'eeee1111', 's3', 'Offsite', [ 'bucket' => 'new' ] ) )->toArray() ] ) ] );
		// What a request that cached the option early sees through get_option(); update_option() writes the row, as WordPress does.
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => Options::DESTINATIONS === $name ? $stale : $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ): bool {
				$this->db->rows[ $name ] = [
					'value'    => serialize( $value ),
					'autoload' => 'off',
				];
				return true;
			}
		);

		self::repo()->mark( 'eeee1111', Profile::OK, '', 900 );
		self::repo()->mark( 'abcd1234', Profile::ERROR, 'Gone.' );

		$raw  = (string) $this->db->value( Options::DESTINATIONS );
		$rows = json_decode( $raw, true ) ?? unserialize( $raw, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reads what a cache-based write would have left.
		$this->assertSame( [ 'eeee1111' ], array_column( $rows, 'id' ), 'A deleted profile never comes back.' );
		$this->assertSame( [ [ 'bucket' => 'new' ], Profile::OK, 900 ], [ $rows[0]['settings'], $rows[0]['status'], $rows[0]['lastUploadAt'] ], 'The admin\'s edit stays.' );
	}

	public function test_mark_keeps_an_edit_made_between_its_read_and_its_write(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite', [ 'bucket' => 'old' ] ) );
		$this->db->beforeWrite = static function (): void {
			( new ProfileRepository( static fn(): int => 2000 ) )->save( new Profile( 'abcd1234', 's3', 'Renamed', [ 'bucket' => 'new' ] ) );
		};

		$repo->mark( 'abcd1234', Profile::OK, '', 900 );

		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ 'Renamed', [ 'bucket' => 'new' ], Profile::OK, 900, 2000 ], [ $found->name, $found->settings, $found->status, $found->lastUploadAt, $found->updatedAt ] );
	}

	public function test_mark_of_a_gone_profile_writes_nothing(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'eeee1111', 's3', 'Offsite' ) );
		$before = $this->db->value( Options::DESTINATIONS );

		$repo->mark( 'abcd1234', Profile::OK, '', 900 );

		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );
	}

	public function test_a_save_that_cannot_be_written_throws(): void {
		$this->db->failWrites = true;

		$this->expectException( \RuntimeException::class );
		self::repo()->save( new Profile( 'abcd1234', 's3', 'Offsite' ) );
	}

	public function test_replace_never_brings_back_a_deleted_profile(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite' ) );
		$this->db->beforeWrite = static function (): void {
			( new ProfileRepository() )->delete( 'abcd1234' );
		};

		$this->assertFalse( $repo->replace( new Profile( 'abcd1234', 's3', 'Renamed' ), false ) );
		$this->assertSame( [], $this->stored() );
		$this->assertFalse( $repo->replace( new Profile( 'abcd1234', 's3', 'Renamed' ), true ), 'Gone stays gone.' );
		$this->assertSame( [], $this->stored() );
	}

	public function test_replace_keeps_the_fresh_state_unless_it_starts_over(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite', [ 'bucket' => 'old' ] ) );
		$this->db->beforeWrite = static function (): void {
			( new ProfileRepository() )->mark( 'abcd1234', Profile::ERROR, 'Upload failed.', 900 );
		};

		$edit = new Profile( 'abcd1234', 's3', 'Renamed', [ 'bucket' => 'old' ] );
		$this->assertTrue( $repo->replace( $edit, false ) );
		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ 'Renamed', Profile::ERROR, 'Upload failed.', 900, 1000 ], [ $found->name, $found->status, $found->message, $found->lastUploadAt, $found->createdAt ] );
		$this->assertSame( [ Profile::ERROR, 'Upload failed.', 900 ], [ $edit->status, $edit->message, $edit->lastUploadAt ], 'The caller sees what was stored.' );

		$this->assertTrue( $repo->replace( new Profile( 'abcd1234', 's3', 'Renamed', [ 'bucket' => 'new' ] ), true ) );
		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ [ 'bucket' => 'new' ], Profile::NEW, '', 900 ], [ $found->settings, $found->status, $found->message, $found->lastUploadAt ] );
	}

	public function test_the_connection_survives_a_round_trip_and_junk_is_dropped(): void {
		$profile             = new Profile( 'abcd1234', 'gdrive', 'Drive' );
		$profile->connection = [
			'account'     => 'admin@example.com',
			'connectedAt' => 1700000000,
			'folderId'    => '1AbC',
		];
		self::repo()->save( $profile );

		$this->assertSame( $profile->connection, self::repo()->find( 'abcd1234' )->connection );
		$this->assertSame( $profile->connection, $this->stored()[0]['connection'] );
		$this->assertSame( [ 'ok' => 'x' ], Profile::connection( [ 'ok' => 'x', 'bad key' => 'y', 'n' => [ 1 ], 'long' => str_repeat( 'z', 3000 ), 7 => 'x', 'b' => true ] ) );
		$this->assertSame( [], Profile::connection( 'not a list' ) );
	}

	public function test_a_connection_keeps_at_most_ten_entries_and_an_old_row_reads_none(): void {
		$many = [];
		foreach ( range( 'a', 'l' ) as $key ) {
			$many[ $key ] = $key;
		}
		$this->assertSame( array_slice( $many, 0, 10 ), Profile::connection( $many ) );

		$this->db = FakeWpdb::install( [ Options::DESTINATIONS => (string) json_encode( [ [ 'id' => 'abcd1234', 'type' => 's3', 'name' => 'Old' ] ] ) ] );
		$this->assertSame( [], self::repo()->find( 'abcd1234' )->connection );
	}

	/**
	 * Preflight P-C1: a connect writes only to a profile that still exists with the same Client ID.
	 */
	public function test_connect_sets_the_connection_and_starts_the_state_over(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ], [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], Profile::REAUTH, 'Connect again.', 900 ) );

		$this->assertTrue( $repo->connect( 'abcd1234', [ 'account' => 'a@example.com', 'client' => 'mark', 'id' => self::C1, 'bad key' => 'x' ], self::clientIs( 'cid' ) ) );

		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ [ 'account' => 'a@example.com', 'client' => 'mark', 'id' => self::C1 ], Profile::NEW, '', 900, [ 'clientId' => 'cid' ] ], [ $found->connection, $found->status, $found->message, $found->lastUploadAt, $found->settings ] );
	}

	public function test_connect_never_writes_to_a_deleted_profile_or_another_client_id(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'new-cid' ] ) );
		$before = $this->db->value( Options::DESTINATIONS );

		$this->assertFalse( $repo->connect( 'abcd1234', [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1 ], self::clientIs( 'old-cid' ) ), 'The Client ID changed during the connect.' );
		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );

		$this->db->beforeWrite = static function (): void {
			( new ProfileRepository() )->delete( 'abcd1234' );
		};
		$this->assertFalse( $repo->connect( 'abcd1234', [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1 ], self::clientIs( 'new-cid' ) ), 'Deleted during the connect.' );
		$this->assertSame( [], $this->stored(), 'A deleted profile never comes back.' );
	}

	public function test_connect_binds_only_through_the_settings_check(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] ) );
		$before = $this->db->value( Options::DESTINATIONS );

		$this->assertFalse( $repo->connect( 'abcd1234', [ 'client' => 'mark', 'id' => self::C1 ], static fn(): int => 1 ), 'Only an exact true binds.' );
		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );

		$this->expectException( \TypeError::class ); // Task 10 re-review: a plain Client ID (which ignores the grant settings) is no longer accepted.
		$repo->connect( 'abcd1234', [ 'client' => 'mark', 'id' => self::C1 ], 'cid' );
	}

	public function test_replace_keeps_the_stored_connection_unless_the_client_id_changed(): void {
		$repo    = self::repo();
		$profile = new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] );
		$repo->save( $profile );
		$repo->connect( 'abcd1234', [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1 ], self::clientIs( 'cid' ) );

		$edit             = new Profile( 'abcd1234', 'gdrive', 'Renamed', [ 'clientId' => 'cid' ] );
		$edit->connection = [ 'account' => 'stale copy of the form' ];
		$this->assertTrue( $repo->replace( $edit, false ) );
		$this->assertSame( [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1 ], $repo->find( 'abcd1234' )->connection, 'An edit never writes its copy over a connect.' );
		$this->assertSame( [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1 ], $edit->connection, 'The caller sees what was stored.' );

		$this->assertTrue( $repo->replace( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'other' ] ), true, true ) );
		$this->assertSame( [], $repo->find( 'abcd1234' )->connection );
	}

	/**
	 * Task 7 review m3: bound to the connection (its id), not only to the app.
	 */
	public function test_remember_sets_one_connection_key_while_the_connection_holds(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] ) );
		$repo->connect( 'abcd1234', [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1, 'folderId' => 'old' ], self::clientIs( 'cid' ) );

		$this->assertTrue( $repo->remember( 'abcd1234', 'folderId', 'new', self::C1 ) );
		$this->assertSame( [ 'account' => 'a', 'client' => 'mark', 'id' => self::C1, 'folderId' => 'new' ], $repo->find( 'abcd1234' )->connection );

		$before = $this->db->value( Options::DESTINATIONS );
		$this->assertFalse( $repo->remember( 'abcd1234', 'folderId', 'x', self::C0 ), 'An earlier connection of the same app.' );
		$this->assertFalse( $repo->remember( 'abcd1234', 'folderId', 'x', 'mark' ), 'The client mark is not the connection.' );
		$this->assertFalse( $repo->remember( 'abcd1234', 'folderId', 'x', '' ) );
		$this->assertFalse( $repo->remember( 'ffff0000', 'folderId', 'x', self::C1 ), 'A gone profile stays gone.' );
		$this->assertFalse( $repo->remember( 'abcd1234', 'folderId', str_repeat( 'x', 3000 ), self::C1 ), 'A value over the limit is not written.' );
		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );

		foreach ( [ 'bad key', 'id', 'client' ] as $key ) {
			try {
				$repo->remember( 'abcd1234', $key, 'x', self::C1 );
				$this->fail( 'Refused: ' . $key );
			} catch ( \LogicException $e ) {
				$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );
			}
		}
	}

	/**
	 * Task 7 review I1: a run binds on the connection id, so a folder id
	 * remembered meanwhile does not hide a revoked connection, and another
	 * connection with the same entries is never marked.
	 */
	public function test_revoked_binds_on_the_connection_id(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ], [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], Profile::OK ) );
		$repo->connect( 'abcd1234', [ 'account' => 'a', 'connectedAt' => 900, 'client' => 'mark', 'id' => self::C1 ], self::clientIs( 'cid' ) );
		$used = $repo->find( 'abcd1234' )->connection;
		$repo->remember( 'abcd1234', 'folderId', 'f1', self::C1 );

		$this->assertTrue( $repo->revoked( 'abcd1234', 'm', $used, true ) );

		$repo->connect( 'abcd1234', [ 'account' => 'a', 'connectedAt' => 900, 'client' => 'mark', 'id' => self::C2 ], self::clientIs( 'cid' ) );
		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $used, true ) );
		$this->assertSame( Profile::NEW, $repo->find( 'abcd1234' )->status, 'The same entries, another connection.' );
	}

	/**
	 * Review round 1 m1: a connection without the Client-ID mark could never be used.
	 */
	public function test_connect_requires_the_client_mark_and_the_connection_id(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ] ) );
		$before = $this->db->value( Options::DESTINATIONS );
		$cases  = [
			[ 'account' => 'a', 'id' => self::C1 ],
			[ 'account' => 'a', 'client' => '', 'id' => self::C1 ],
			[ 'client' => 5, 'id' => self::C1 ],
			[ 'account' => 'a', 'client' => 'mark' ], // Task 7 review round 2: the id is required too.
			[ 'client' => 'mark', 'id' => '' ],
			[ 'client' => 'mark', 'id' => 'conn1' ],
			[ 'client' => 'mark', 'id' => strtoupper( self::C1 . 'a' ) ],
			[ 'client' => 'mark', 'id' => str_repeat( 'g', 32 ) ],
		];

		foreach ( $cases as $connection ) {
			try {
				$repo->connect( 'abcd1234', $connection, self::clientIs( 'cid' ) );
				$this->fail( 'A connection needs its client mark and its id.' );
			} catch ( \LogicException $e ) {
				$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );
			}
		}
	}

	/**
	 * A connected OAuth profile (connection with a client mark) in state ok.
	 */
	private static function connectedRepo(): ProfileRepository {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid' ], [ 'count' => 0, 'bytes' => 0, 'days' => 0 ], Profile::OK ) );
		$repo->connect( 'abcd1234', [ 'account' => 'a', 'connectedAt' => 900, 'client' => 'mark', 'id' => self::C1 ], self::clientIs( 'cid' ) );

		return $repo;
	}

	/**
	 * Preflight P-M6: decided on the row, so two runs failing at once mail once.
	 */
	public function test_revoked_marks_reauth_and_answers_due_once_per_connection(): void {
		$repo = self::connectedRepo();
		$used = $repo->find( 'abcd1234' )->connection;

		$this->assertTrue( $repo->revoked( 'abcd1234', 'Connect again.', $used, true ) );
		$this->assertFalse( $repo->revoked( 'abcd1234', 'Connect again.', $used, true ), 'The same connection was noticed already.' );

		$found = $repo->find( 'abcd1234' );
		$this->assertSame( [ Profile::REAUTH, 'Connect again.' ], [ $found->status, $found->message ] );
		$this->assertSame( 'a', $found->connection['account'] );
	}

	/**
	 * Per revoked grant, not per state change: an edit that starts the state
	 * over keeps the connection (and its notice); a reconnect writes a new one.
	 */
	public function test_an_edit_does_not_renew_the_notice_a_reconnect_does(): void {
		$repo = self::connectedRepo();
		$repo->revoked( 'abcd1234', 'm', $repo->find( 'abcd1234' )->connection, true );
		$repo->replace( new Profile( 'abcd1234', 'gdrive', 'Drive', [ 'clientId' => 'cid', 'folder' => 'x' ] ), true );
		$this->assertSame( Profile::NEW, $repo->find( 'abcd1234' )->status );

		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $repo->find( 'abcd1234' )->connection, true ), 'The same grant: no second mail.' );

		$repo->connect( 'abcd1234', [ 'account' => 'a', 'connectedAt' => 950, 'client' => 'mark', 'id' => self::C2 ], self::clientIs( 'cid' ) );
		$this->assertTrue( $repo->revoked( 'abcd1234', 'm', $repo->find( 'abcd1234' )->connection, true ), 'A new connection is a new grant.' );
	}

	public function test_a_concurrent_notice_between_read_and_write_wins(): void {
		$repo                  = self::connectedRepo();
		$used                  = $repo->find( 'abcd1234' )->connection;
		$this->db->beforeWrite = static function () use ( $used ): void {
			( new ProfileRepository( static fn(): int => 1000 ) )->revoked( 'abcd1234', 'm', $used, true );
		};

		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $used, true ), 'The other run mailed.' );
	}

	/**
	 * Task 4 m4: a run that used the old connection never marks a reconnect `reauth`.
	 */
	public function test_revoked_leaves_a_reconnected_or_deleted_profile_alone(): void {
		$repo = self::connectedRepo();
		$used = $repo->find( 'abcd1234' )->connection;
		$repo->connect( 'abcd1234', [ 'account' => 'b', 'connectedAt' => 950, 'client' => 'mark', 'id' => self::C2 ], self::clientIs( 'cid' ) );
		$before = $this->db->value( Options::DESTINATIONS );

		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $used, true ) );
		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ) );
		$this->assertSame( Profile::NEW, $repo->find( 'abcd1234' )->status );

		$this->assertFalse( $repo->revoked( 'ffff0000', 'm', [], true ) );
		$this->assertSame( $before, $this->db->value( Options::DESTINATIONS ), 'A gone profile stays gone.' );
	}

	public function test_without_a_notice_nothing_is_recorded_so_a_later_cron_run_still_mails(): void {
		$repo = self::connectedRepo();
		$used = $repo->find( 'abcd1234' )->connection;

		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $used, false ) );
		$this->assertSame( Profile::REAUTH, $repo->find( 'abcd1234' )->status );
		$this->assertTrue( $repo->revoked( 'abcd1234', 'm', $repo->find( 'abcd1234' )->connection, true ) );
	}

	public function test_a_profile_without_a_connection_is_due_when_it_turns_reauth(): void {
		$repo = self::repo();
		$repo->save( new Profile( 'abcd1234', 's3', 'Offsite', [ 'bucket' => 'b' ] ) );

		$this->assertTrue( $repo->revoked( 'abcd1234', 'm', [], true ) );
		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', [], true ) );
		$this->assertSame( [], $repo->find( 'abcd1234' )->connection, 'Nothing is written into a connection it does not have.' );

		$repo->mark( 'abcd1234', Profile::OK );
		$this->assertTrue( $repo->revoked( 'abcd1234', 'm', [], true ), 'Its credentials were entered again and failed again.' );
	}

	public function test_revoked_answers_false_when_the_row_cannot_be_written(): void {
		$repo                 = self::connectedRepo();
		$this->db->failWrites = true;

		$this->assertFalse( $repo->revoked( 'abcd1234', 'm', $repo->find( 'abcd1234' )->connection, true ) );
	}

	/**
	 * The connect binding of a profile whose Client ID is $cid.
	 *
	 * @param string $cid Client ID.
	 */
	private static function clientIs( string $cid ): \Closure {
		return static fn( array $settings ): bool => $cid === ( $settings['clientId'] ?? null );
	}
}
