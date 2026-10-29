<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthApp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\Provider;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenEndpoint;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenKeeper;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\TokenSet;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\SecretBox;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plan Task 4 (E6, E7) with preflight P-C1 (token writes only to a profile
 * that still has its secrets; a revoked refresh token is tried once more
 * with the one stored meanwhile) and P-I8 (the vault reads through $wpdb).
 */
final class TokenKeeperTest extends MonkeyTestCase {

	private const ID = 'abcd1234';

	private int $now = 1_800_000_000;

	private FakeWpdb $db;

	private SecretVault $vault;

	/** @var array<int, string> Refresh tokens the endpoint was asked with. */
	private array $refreshed = [];

	/** @var array<int, TokenSet|PermanentFailure> Answers in order (the last one repeats). */
	private array $answers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db      = FakeWpdb::install();
		$this->vault   = new SecretVault( new SecretBox( str_repeat( 'k', 32 ) ), true );
		$this->answers = [ new TokenSet( 'new-access', $this->now + 3600 ) ];
	}

	private function endpoint(): TokenEndpoint {
		$test = $this;
		return new class( $test ) implements TokenEndpoint {
			public function __construct( private TokenKeeperTest $test ) {
			}
			public function exchange( OAuthApp $app, string $code, string $verifier, string $redirectUri ): TokenSet {
				throw new \LogicException( 'Not used.' );
			}
			public function refresh( OAuthApp $app, string $refreshToken ): TokenSet {
				return $this->test->answerFor( $refreshToken );
			}
		};
	}

	public function answerFor( string $refreshToken ): TokenSet {
		$this->refreshed[] = $refreshToken;
		$answer            = count( $this->answers ) > 1 ? array_shift( $this->answers ) : $this->answers[0];
		if ( $answer instanceof PermanentFailure ) {
			throw $answer;
		}
		return $answer;
	}

	/**
	 * @param array<string, string> $secrets Opened secrets.
	 */
	private function keeper( array $secrets ): TokenKeeper {
		$this->vault->put( self::ID, $secrets + [ TokenKeeper::GRANT => 'g1' ] );
		return new TokenKeeper( self::ID, new OAuthApp( Provider::google(), 'cid', 'sec' ), (array) $this->vault->open( self::ID ), $this->endpoint(), $this->vault, fn(): int => $this->now );
	}

	private function storedRow(): string {
		return (string) $this->db->value( Options::DESTINATION_SECRETS );
	}

	/**
	 * Issue #12, through the LW app: the refreshed access token is asked at
	 * the provider ($verify) before anything is kept.
	 *
	 * @param array<string, string> $secrets Opened secrets.
	 * @param \Closure              $verify  fn( string $access ): string.
	 */
	private function lwKeeper( array $secrets, \Closure $verify ): TokenKeeper {
		$this->vault->put( self::ID, $secrets + [ TokenKeeper::GRANT => 'g1' ] );
		return new TokenKeeper( self::ID, new OAuthApp( Provider::dropbox(), '', '' ), (array) $this->vault->open( self::ID ), $this->endpoint(), $this->vault, fn(): int => $this->now, $verify );
	}

	/**
	 * @return array<string, array{0: \Closure}>
	 */
	public static function unconfirmed(): array {
		return [
			'another account'        => [ static fn( string $access ): string => 'dropbox:dbid:OTHER' ],
			'the token refused (403)' => [
				static function ( string $access ): string {
					throw new PermanentFailure( 'Dropbox did not confirm which account this sign-in belongs to.', PermanentFailure::INVALID, 403 );
				},
			],
			'no account named'       => [ static fn( string $access ): string => '' ],
		];
	}

	/**
	 * @param \Closure $verify What the provider answers.
	 */
	#[DataProvider( 'unconfirmed' )]
	public function test_an_lw_app_refresh_not_confirmed_as_the_recorded_account_stores_nothing( \Closure $verify ): void {
		$this->answers = [ new TokenSet( 'other-access', $this->now + 3600, 'other-refresh' ) ];
		$keeper        = $this->lwKeeper( [ 'refreshToken' => 'r1', 'accessToken' => '1:old', 'identity' => 'dropbox:dbid:AA' ], $verify );
		$before        = $this->vault->open( self::ID );

		try {
			$keeper->renew();
			$this->fail( 'Not kept.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		$this->assertSame( $before, $this->vault->open( self::ID ), 'None of the new tokens is stored.' );
	}

	/**
	 * @return array<string, array{0: TransientFailure}>
	 */
	public static function outages(): array {
		return [
			'a 5xx answer'    => [ new TransientFailure( 'Dropbox did not confirm which account this sign-in belongs to.', TransientFailure::SERVER, 503, 30 ) ],
			'a 429 answer'    => [ new TransientFailure( 'Dropbox did not confirm which account this sign-in belongs to.', TransientFailure::BUSY, 429, 60 ) ],
			'a timeout'       => [ new TransientFailure( 'api.dropboxapi.com cannot be reached: it did not answer in time.', TransientFailure::NETWORK ) ],
		];
	}

	/**
	 * Controller refinement of I1: an outage at the provider during the
	 * check is temporary (tried again later), never a new connect.
	 *
	 * @param TransientFailure $outage What the check throws.
	 */
	#[DataProvider( 'outages' )]
	public function test_an_outage_during_the_lw_app_check_is_temporary_and_stores_nothing( TransientFailure $outage ): void {
		$this->answers = [ new TokenSet( 'other-access', $this->now + 3600, 'other-refresh' ) ];
		$keeper        = $this->lwKeeper(
			[ 'refreshToken' => 'r1', 'accessToken' => '1:old', 'identity' => 'dropbox:dbid:AA' ],
			static function ( string $access ) use ( $outage ): string {
				throw $outage;
			}
		);
		$before        = $this->vault->open( self::ID );

		try {
			$keeper->renew();
			$this->fail( 'Not kept.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( $outage, $e, 'Its reason and Retry-After pass through.' );
		}
		$this->assertSame( $before, $this->vault->open( self::ID ), 'None of the new tokens is stored.' );
	}

	public function test_an_lw_app_refresh_of_the_recorded_account_is_stored_after_asking_with_its_token(): void {
		$asked         = [];
		$this->answers = [ new TokenSet( 'a2', $this->now + 3600, 'r2' ) ];
		$keeper        = $this->lwKeeper(
			[ 'refreshToken' => 'r1', 'identity' => 'dropbox:dbid:AA' ],
			static function ( string $access ) use ( &$asked ): string {
				$asked[] = $access;
				return 'dropbox:dbid:AA';
			}
		);

		$this->assertSame( 'a2', $keeper->renew() );
		$this->assertSame( [ 'a2' ], $asked );
		$this->assertSame( [ 'r2', 'dropbox:dbid:AA' ], [ $this->vault->open( self::ID )['refreshToken'], $this->vault->open( self::ID )['identity'] ] );
	}

	/** Final review M8: no trust on first refresh; a connection without a recorded account connects again. */
	public function test_an_lw_app_refresh_without_a_recorded_account_asks_for_a_new_connect_and_stores_nothing(): void {
		foreach ( [ [], [ 'identity' => 'none' ] ] as $legacy ) {
			$this->answers = [ new TokenSet( 'a2', $this->now + 3600, 'r2' ) ];
			$asked         = 0;
			$keeper        = $this->lwKeeper(
				[ 'refreshToken' => 'r1' ] + $legacy,
				static function ( string $access ) use ( &$asked ): string {
					++$asked;
					return 'dropbox:dbid:AA';
				}
			);
			$before        = $this->vault->open( self::ID );

			try {
				$keeper->renew();
				$this->fail( 'Not kept.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::REAUTH, $e->reason );
				$this->assertStringContainsString( 'Choose Connect again', $e->getMessage() );
			}
			$this->assertSame( 0, $asked, 'Nothing to compare with: the provider is not asked.' );
			$this->assertSame( $before, $this->vault->open( self::ID ), 'None of the new tokens is stored.' );
		}
	}

	public function test_an_own_app_refresh_asks_nothing_more(): void {
		$this->answers = [ new TokenSet( 'a2', $this->now + 3600 ) ];

		$this->assertSame( 'a2', $this->keeper( [ 'refreshToken' => 'r1', 'identity' => 'google:x' ] )->renew(), 'No $verify: straight from the provider.' );
	}

	public function test_commit_records_the_account_and_none_when_unknown(): void {
		$this->vault->put( self::ID, [ 'identity' => 'dropbox:dbid:OLD' ] );

		TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a', 1, 'r' ), str_repeat( 'b', 32 ) );

		$this->assertSame( TokenKeeper::NO_IDENTITY, $this->vault->open( self::ID )['identity'], 'A reconnect never keeps the identity of the connection before it.' );
		TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a', 1, 'r' ), str_repeat( 'c', 32 ), 'google:123' );
		$this->assertSame( 'google:123', $this->vault->open( self::ID )['identity'] );
	}

	public function test_a_fresh_cached_access_token_is_used_without_a_request(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1', 'accessToken' => ( $this->now + 600 ) . ':cached' ] );

		$this->assertSame( 'cached', $keeper->token() );
		$this->assertSame( [], $this->refreshed );
	}

	public function test_a_token_near_expiry_is_refreshed_and_stored_sealed(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1', 'accessToken' => ( $this->now + TokenKeeper::MARGIN ) . ':old' ] );

		$this->assertSame( 'new-access', $keeper->token() );
		$this->assertSame( [ 'r1' ], $this->refreshed );
		$this->assertSame( ( $this->now + 3600 ) . ':new-access', $this->vault->open( self::ID )['accessToken'] );
		$this->assertStringNotContainsString( 'new-access', $this->storedRow(), 'Sealed, never plain.' );
		$this->assertSame( 'new-access', $keeper->token(), 'Cached in memory afterwards.' );
		$this->assertCount( 1, $this->refreshed );
	}

	public function test_a_malformed_cached_access_token_is_never_used(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1', 'accessToken' => 'no-expiry-given' ] );

		$this->assertSame( 'new-access', $keeper->token() );
		$this->assertSame( [ 'r1' ], $this->refreshed );
	}

	public function test_a_rotated_refresh_token_replaces_the_stored_one_and_none_keeps_it(): void {
		$this->answers = [ new TokenSet( 'a2', $this->now + 3600, 'r2' ) ];
		$this->assertSame( 'a2', $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'r1' ] )->renew() );
		$this->assertSame( 'r2', $this->vault->open( self::ID )['refreshToken'] );

		$this->answers = [ new TokenSet( 'a3', $this->now + 3600 ) ];
		$this->keeper( [ 'refreshToken' => 'r2' ] )->renew();
		$this->assertSame( [ 'clientSecret' => 'sec', 'refreshToken' => 'r2', 'grant' => 'g1', 'accessToken' => ( $this->now + 3600 ) . ':a3' ], $this->vault->open( self::ID ) );
	}

	public function test_invalid_grant_on_refresh_is_reauth(): void {
		$failure       = new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH );
		$this->answers = [ $failure ];

		try {
			$this->keeper( [ 'refreshToken' => 'r1' ] )->token();
			$this->fail( 'Reauth.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( $failure, $e );
		}
		$this->assertSame( [ 'r1' ], $this->refreshed, 'The same stored token is not tried twice.' );
	}

	/**
	 * Preflight P-C1: another process rotated the refresh token (or a reconnect stored a new one)
	 * while this one held the old: it is tried once with the stored one before REAUTH.
	 */
	public function test_a_refused_refresh_retries_once_with_a_token_stored_meanwhile(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1' ] );
		$this->vault->put( self::ID, [ 'refreshToken' => 'r2' ] );
		$this->answers = [ new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH ), new TokenSet( 'a3', $this->now + 3600, 'r3' ) ];

		$this->assertSame( 'a3', $keeper->token() );

		$this->assertSame( [ 'r1', 'r2' ], $this->refreshed );
		$this->assertSame( 'r3', $this->vault->open( self::ID )['refreshToken'] );
	}

	public function test_a_retry_with_the_stored_token_that_fails_too_is_reauth_after_two_requests(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1' ] );
		$this->vault->put( self::ID, [ 'refreshToken' => 'r2' ] );
		$this->answers = [ new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH ) ];

		try {
			$keeper->token();
			$this->fail( 'Reauth.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		$this->assertSame( [ 'r1', 'r2' ], $this->refreshed );
	}

	public function test_another_refusal_is_not_retried(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'r1' ] );
		$this->vault->put( self::ID, [ 'refreshToken' => 'r2' ] );
		$this->answers = [ new PermanentFailure( 'bad client', PermanentFailure::AUTH ) ];

		try {
			$keeper->token();
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
		}
		$this->assertSame( [ 'r1' ], $this->refreshed );
	}

	public function test_without_a_refresh_token_it_is_reauth_and_asks_nobody(): void {
		try {
			$this->keeper( [ 'clientSecret' => 'sec' ] )->token();
			$this->fail( 'Not connected.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
			$this->assertStringContainsString( 'Google Drive', $e->getMessage() );
		}
		$this->assertSame( [], $this->refreshed );
	}

	/**
	 * Preflight P-C1: a refresh finishing after the destination was deleted stores nothing.
	 */
	public function test_a_refresh_after_the_profile_was_deleted_brings_back_no_secret(): void {
		$keeper = $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'r1' ] );
		$this->vault->forget( self::ID );

		try {
			$keeper->token();
			$this->fail( 'Gone.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
			$this->assertStringNotContainsString( 'new-access', $e->getMessage() );
		}
		$this->assertSame( [], $this->vault->fields( self::ID ) );
		$this->assertStringNotContainsString( self::ID, $this->storedRow() );
	}

	public function test_a_vault_that_cannot_be_written_is_invalid_and_names_no_token(): void {
		$keeper               = $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'r1' ] );
		$this->db->failWrites = true;

		try {
			$keeper->token();
			$this->fail( 'Not stored.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'new-access', $e->getMessage() );
			$this->assertStringNotContainsString( 'r1', $e->getMessage() );
		}
	}

	/**
	 * Review round 1 C1: a keeper opened before a reconnect (another account) renews with its
	 * old refresh token, which the provider still accepts: nothing it got is stored, the new
	 * connection's tokens stay, and the stale access token is not used any more.
	 */
	public function test_a_refresh_from_before_a_reconnect_never_overwrites_the_new_connection(): void {
		$keeper = $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'rA1', 'accessToken' => ( $this->now + 60 ) . ':aA1' ] );
		$this->assertTrue( TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'aB', $this->now + 3600, 'rB' ), str_repeat( 'b', 32 ) ) );
		$after         = (array) $this->vault->open( self::ID );
		$this->answers = [ new TokenSet( 'aA2', $this->now + 3600, 'rA2' ) ];

		try {
			$keeper->token();
			$this->fail( 'The connection changed.' );
		} catch ( TransientFailure $e ) {
			$this->assertStringNotContainsString( 'aA2', $e->getMessage() );
		}
		$this->assertSame( [ 'rA1' ], $this->refreshed );
		$this->assertSame( $after, $this->vault->open( self::ID ), 'The reconnected account B keeps its tokens.' );
		$this->assertSame( [ 'rB', ( $this->now + 3600 ) . ':aB' ], [ $after['refreshToken'], $after['accessToken'] ] );
		$this->expectException( TransientFailure::class );
		$keeper->token();
	}

	public function test_a_refresh_from_before_a_client_id_change_and_reconnect_is_not_stored(): void {
		$keeper = $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'rA1' ] );
		$this->vault->drop( self::ID, TokenKeeper::FIELDS );
		$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ), 'Client ID changed: the tokens and the grant are gone.' );

		try {
			$keeper->renew();
			$this->fail( 'The connection changed.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ), 'Nothing of the old app comes back.' );
		}

		$this->vault->put( self::ID, [ 'clientSecret' => 'sec2' ] );
		TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'aB', $this->now + 3600, 'rB' ), str_repeat( 'b', 32 ) );
		$this->expectException( TransientFailure::class );
		try {
			$keeper->renew();
		} finally {
			$this->assertSame( 'rB', $this->vault->open( self::ID )['refreshToken'] );
		}
	}

	/**
	 * Review round 1 I1: a refused refresh token is retried with the stored one only within the same connection.
	 */
	public function test_a_refused_refresh_is_not_retried_with_another_connections_token(): void {
		$keeper = $this->keeper( [ 'refreshToken' => 'rA1' ] );
		TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'aB', $this->now + 3600, 'rB' ), str_repeat( 'b', 32 ) );
		$this->answers = [ new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH ) ];

		$this->expectException( TransientFailure::class );
		try {
			$keeper->token();
		} finally {
			$this->assertSame( [ 'rA1' ], $this->refreshed, 'Account B\'s refresh token is never sent by this keeper.' );
			$this->assertSame( 'rB', $this->vault->open( self::ID )['refreshToken'] );
		}
	}

	/**
	 * Review round 2 (probe P3): a keeper opened on tokens without a grant, then a Client-ID
	 * change, then a refresh: the old app's tokens never come back.
	 */
	public function test_a_keeper_without_a_grant_never_stores_after_a_client_id_change(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec', 'refreshToken' => 'rA1', 'accessToken' => '1:aA1' ] );
		$keeper = new TokenKeeper( self::ID, new OAuthApp( Provider::google(), 'cid', 'sec' ), (array) $this->vault->open( self::ID ), $this->endpoint(), $this->vault, fn(): int => $this->now );
		$this->vault->drop( self::ID, TokenKeeper::FIELDS );

		$this->expectException( TransientFailure::class );
		try {
			$keeper->renew();
		} finally {
			$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ) );
		}
	}

	/**
	 * Review round 2 (probe P4): a keeper built in memory from an exchange answer (no grant)
	 * on a first connect keeps its tokens in memory only.
	 */
	public function test_a_keeper_without_a_grant_never_stores_on_a_first_connect(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec' ] );
		$keeper = new TokenKeeper( self::ID, new OAuthApp( Provider::google(), 'cid', 'sec' ), [ 'refreshToken' => 'rX', 'accessToken' => ( $this->now + 3600 ) . ':aX' ], $this->endpoint(), $this->vault, fn(): int => $this->now );

		$this->assertSame( 'aX', $keeper->token(), 'A fresh in-memory token is used.' );
		try {
			$keeper->renew();
			$this->fail( 'Nothing is stored without a grant.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ) );
		}
	}

	/**
	 * Review round 2: without a grant the invalid_grant retry never sends a token stored in the vault.
	 */
	public function test_a_keeper_without_a_grant_never_retries_with_a_stored_token(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec', 'refreshToken' => 'rStored' ] );
		$keeper        = new TokenKeeper( self::ID, new OAuthApp( Provider::google(), 'cid', 'sec' ), [ 'refreshToken' => 'rX' ], $this->endpoint(), $this->vault, fn(): int => $this->now );
		$this->answers = [ new PermanentFailure( 'withdrawn', PermanentFailure::REAUTH ) ];

		$this->expectException( TransientFailure::class );
		try {
			$keeper->renew();
		} finally {
			$this->assertSame( [ 'rX' ], $this->refreshed );
		}
	}

	/**
	 * Task 7 review I1: the callback makes the grant before both writes and
	 * commit() stores exactly that one, so the profile's connection id can
	 * be its mark.
	 */
	public function test_commit_stores_the_grant_the_callback_made(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec' ] );

		$this->assertTrue( TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a1', $this->now + 3600, 'r1' ), str_repeat( '1', 32 ) ) );
		$first = (array) $this->vault->open( self::ID );
		TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a2', $this->now + 3600, 'r2' ), str_repeat( '2', 32 ) );
		$second = (array) $this->vault->open( self::ID );

		$this->assertSame( [ 'sec', 'r1', ( $this->now + 3600 ) . ':a1', str_repeat( '1', 32 ) ], [ $first['clientSecret'], $first['refreshToken'], $first['accessToken'], $first['grant'] ] );
		$this->assertSame( str_repeat( '2', 32 ), $second['grant'] );
		$this->assertStringNotContainsString( $first['grant'], $this->storedRow(), 'Sealed like the tokens.' );
		$this->assertFalse( TokenKeeper::commit( $this->vault, 'ffff0000', new TokenSet( 'a', $this->now + 3600, 'r' ), str_repeat( '3', 32 ) ), 'A deleted profile gets nothing.' );
		$this->assertSame( [], $this->vault->fields( 'ffff0000' ) );
	}

	public function test_commit_refuses_a_malformed_grant(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec' ] );

		foreach ( [ '', 'g', str_repeat( 'G', 32 ) ] as $grant ) {
			try {
				TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a', $this->now + 3600, 'r' ), $grant );
				$this->fail( 'Refused: ' . $grant );
			} catch ( \LogicException $e ) {
				$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ) );
			}
		}
	}

	public function test_a_grant_is_fresh_and_its_connection_id_a_keyed_mark(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		$grant = TokenKeeper::newGrant();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', $grant );
		$this->assertNotSame( $grant, TokenKeeper::newGrant() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', TokenKeeper::connectionId( $grant ) );
		$this->assertSame( TokenKeeper::connectionId( $grant ), TokenKeeper::connectionId( $grant ) );
		$this->assertNotSame( $grant, TokenKeeper::connectionId( $grant ), 'The grant itself never goes into the profile.' );
		$this->assertNotSame( TokenKeeper::connectionId( str_repeat( '1', 32 ) ), TokenKeeper::connectionId( str_repeat( '2', 32 ) ) );
		$this->assertSame( '', TokenKeeper::connectionId( '' ), 'No grant, no connection.' );
	}

	public function test_commit_without_a_refresh_token_is_refused(): void {
		$this->vault->put( self::ID, [ 'clientSecret' => 'sec' ] );

		try {
			TokenKeeper::commit( $this->vault, self::ID, new TokenSet( 'a1', $this->now + 3600 ), str_repeat( '1', 32 ) );
			$this->fail( 'No offline access.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [ 'clientSecret' => 'sec' ], $this->vault->open( self::ID ) );
	}

	/**
	 * Two processes refresh at once and the provider rotates for both (Microsoft keeps the
	 * earlier refresh token valid): the vault ends with a token the provider issued, the
	 * client secret stays, and neither write is lost to a stale read.
	 */
	public function test_two_refreshes_at_once_keep_a_working_rotated_token_and_the_secret(): void {
		$first         = $this->keeper( [ 'clientSecret' => 'sec', 'refreshToken' => 'r1' ] );
		$second        = new TokenKeeper( self::ID, new OAuthApp( Provider::microsoft(), 'cid', 'sec' ), (array) $this->vault->open( self::ID ), $this->endpoint(), $this->vault, fn(): int => $this->now );
		$this->answers = [ new TokenSet( 'a-first', $this->now + 3600, 'r-first' ), new TokenSet( 'a-second', $this->now + 3600, 'r-second' ) ];
		$this->db->beforeWrite = static function () use ( $second ): void {
			$second->renew();
		};

		$first->renew();

		$this->assertSame( [ 'r1', 'r1' ], $this->refreshed );
		$stored = (array) $this->vault->open( self::ID );
		$this->assertSame( 'sec', $stored['clientSecret'] );
		$this->assertContains( $stored['refreshToken'], [ 'r-first', 'r-second' ] );
		$this->assertSame( ( $this->now + 3600 ) . ':a-first', $stored['accessToken'], 'Same grant: the write that lost the race read the row again and was kept (both tokens are provider-issued, the later write wins).' );
		$this->assertSame( 'g1', $stored['grant'] );
	}

	public function test_the_secrets_and_the_token_answer_are_marked_sensitive(): void {
		$checks = [
			[ '__construct', 'secrets' ],
			[ 'store', 'set' ],
		];
		foreach ( $checks as [ $method, $name ] ) {
			foreach ( ( new \ReflectionMethod( TokenKeeper::class, $method ) )->getParameters() as $parameter ) {
				if ( $name === $parameter->getName() ) {
					$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ), $method . ' $' . $name );
				}
			}
		}
		$this->assertSame( [ TokenKeeper::REFRESH, TokenKeeper::ACCESS, TokenKeeper::GRANT, TokenKeeper::IDENTITY ], TokenKeeper::FIELDS );
	}
}
