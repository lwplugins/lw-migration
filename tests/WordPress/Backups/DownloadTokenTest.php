<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Backups;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\DownloadToken;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DownloadTokenTest extends MonkeyTestCase {

	/** @var array<string, array{0: mixed, 1: int}> */
	private array $store = [];

	private int $now = 1_800_000_000;

	private string $session = 'session-token-of-user-7';

	protected function setUp(): void {
		parent::setUp();
		$this->store   = [];
		$this->now     = 1_800_000_000;
		$this->session = 'session-token-of-user-7';
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		Functions\when( 'wp_get_session_token' )->alias( fn(): string => $this->session );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->store[ $key ] = [ $value, $ttl ];
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->store[ $key ][0] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				$had = isset( $this->store[ $key ] );
				unset( $this->store[ $key ] );
				return $had;
			}
		);
	}

	private function tokens(): DownloadToken {
		return new DownloadToken( fn(): int => $this->now );
	}

	public function test_a_token_names_its_backup_until_it_expires(): void {
		$token  = $this->tokens()->issue( 7, Backup::OWN, 'site-20261007-101010-001.wpress' );
		$backup = [
			'folder' => Backup::OWN,
			'name'   => 'site-20261007-101010-001.wpress',
		];

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}\z/', $token );
		$this->assertSame( $backup, $this->tokens()->validate( $token, 7 ) );
		$this->now += 3599;
		$this->assertSame( $backup, $this->tokens()->validate( $token, 7 ), 'Reusable within its lifetime (resume).' );
		$this->now += 2;
		$this->assertNull( $this->tokens()->validate( $token, 7 ), 'Expired.' );
		$this->assertSame( [], $this->store, 'An expired token is deleted.' );
	}

	public function test_it_lives_an_hour_and_is_stored_only_as_hashes(): void {
		$token = $this->tokens()->issue( 7, Backup::EXTERNAL, 'a.wpress' );

		$this->assertCount( 1, $this->store );
		$key    = (string) array_key_first( $this->store );
		$stored = (string) json_encode( $this->store[ $key ][0] );
		$this->assertSame( 3600, $this->store[ $key ][1] );
		$this->assertStringNotContainsString( $token, $key );
		$this->assertStringNotContainsString( $token, $stored );
		$this->assertStringNotContainsString( $this->session, $stored, 'The session token is stored only as a hash.' );
		$this->assertLessThanOrEqual( 172, strlen( $key ), 'Fits a transient name.' );
	}

	public function test_the_lifetime_filter_is_clamped(): void {
		foreach ( [ 5 => 60, 600 => 600, 999999 => 86400 ] as $filtered => $ttl ) {
			Filters\expectApplied( 'lw_migration_download_token_ttl' )->once()->with( 3600 )->andReturn( $filtered );
			$this->store = [];
			$token       = $this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
			$key         = (string) array_key_first( $this->store );
			$this->assertSame( $ttl, $this->store[ $key ][1] );
			$this->now += $ttl;
			$this->assertNotNull( $this->tokens()->validate( $token, 7 ), 'Valid through its last second.' );
			++$this->now;
			$this->assertNull( $this->tokens()->validate( $token, 7 ) );
		}
	}

	public function test_an_unusable_lifetime_filter_value_falls_back_to_an_hour(): void {
		foreach ( [ INF, -INF, NAN, 1e30, -1e30, '1e400', 'soon', null, [ 60 ] ] as $filtered ) {
			Filters\expectApplied( 'lw_migration_download_token_ttl' )->once()->andReturn( $filtered );
			$this->store = [];
			$this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
			$key = (string) array_key_first( $this->store );
			$this->assertSame( 3600, $this->store[ $key ][1], 'Default for ' . var_export( $filtered, true ) );
		}
		Filters\expectApplied( 'lw_migration_download_token_ttl' )->once()->andReturn( '120.7' );
		$this->store = [];
		$this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
		$this->assertSame( 120, $this->store[ (string) array_key_first( $this->store ) ][1], 'A numeric string is used.' );
	}

	public function test_another_user_or_session_cannot_use_it(): void {
		$token = $this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );

		$this->assertNull( $this->tokens()->validate( $token, 8 ), 'Another user.' );
		$this->session = 'another-session-of-user-7';
		$this->assertNull( $this->tokens()->validate( $token, 7 ), 'Another login session (or logged out and in again).' );
		$this->session = '';
		$this->assertNull( $this->tokens()->validate( $token, 7 ), 'No session cookie: a leaked link alone opens nothing.' );
		$this->session = 'session-token-of-user-7';
		$this->assertNotNull( $this->tokens()->validate( $token, 7 ), 'Refusals do not burn the owner\'s token.' );
	}

	public function test_a_token_opens_only_its_own_backup(): void {
		$own   = $this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
		$other = $this->tokens()->issue( 7, Backup::EXTERNAL, 'b.wpress' );

		$this->assertSame( 'a.wpress', $this->tokens()->validate( $own, 7 )['name'] ?? null );
		$this->assertSame( Backup::EXTERNAL, $this->tokens()->validate( $other, 7 )['folder'] ?? null );
		$this->assertNull( $this->tokens()->validate( str_repeat( 'a', 64 ), 7 ), 'An unknown token.' );
	}

	public function test_other_salts_or_a_changed_record_are_refused(): void {
		$token = $this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
		Functions\when( 'wp_salt' )->justReturn( 'another-site-salt' );
		$this->assertNull( $this->tokens()->validate( $token, 7 ), 'Other salts: another lookup key.' );

		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );
		$key = (string) array_key_first( $this->store );
		foreach ( [ 'name' => 'other.wpress', 'folder' => Backup::EXTERNAL, 'user' => 8, 'expires' => $this->now + 86400 ] as $field => $value ) {
			$changed                    = $this->store[ $key ][0];
			$changed[ $field ]          = $value;
			$this->store[ $key ][0]     = $changed;
			$this->assertNull( $this->tokens()->validate( $token, 'user' === $field ? 8 : 7 ), 'A changed ' . $field . ' does not verify.' );
		}
	}

	public function test_malformed_tokens_are_refused_without_a_lookup(): void {
		Functions\expect( 'get_transient' )->never();

		$this->assertNull( $this->tokens()->validate( '', 7 ) );
		$this->assertNull( $this->tokens()->validate( str_repeat( 'g', 64 ), 7 ) );
		$this->assertNull( $this->tokens()->validate( str_repeat( 'a', 64 ) . "\n", 7 ) );
		$this->assertNull( $this->tokens()->validate( str_repeat( 'a', 64 ), 0 ) );
	}

	public function test_only_valid_backups_users_and_sessions_get_a_token(): void {
		$cases = [
			[ 7, 'elsewhere', 'a.wpress', 'session-token-of-user-7' ],
			[ 7, Backup::OWN, '../a.wpress', 'session-token-of-user-7' ],
			[ 7, Backup::OWN, 'a.zip', 'session-token-of-user-7' ],
			[ 0, Backup::OWN, 'a.wpress', 'session-token-of-user-7' ],
			[ 7, Backup::OWN, 'a.wpress', '' ],
		];
		foreach ( $cases as [ $user, $folder, $name, $session ] ) {
			$this->session = $session;
			try {
				$this->tokens()->issue( $user, $folder, $name );
				$this->fail( 'Refused: ' . $folder . ' ' . $name . ' ' . $user );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringNotContainsString( '/', $e->getMessage() );
			}
		}
		$this->assertSame( [], $this->store );
	}

	public function test_a_failed_save_is_reported(): void {
		Functions\when( 'set_transient' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->tokens()->issue( 7, Backup::OWN, 'a.wpress' );
	}
}
