<?php
/**
 * The access token of one connected destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E6/E7 with preflight P-C1. The opened secrets come from
 * Destinations::open() (never read again here, except once below); the
 * cached access token ("<expires>:<token>") is used until MARGIN seconds
 * before it expires, then the refresh token buys a new one through the
 * TokenEndpoint. What comes back is sealed into the vault at once — the
 * new access token, and a new refresh token when the provider rotated it
 * (an answer without one keeps the stored one) — but only into the
 * connection this keeper was opened for. Every connection has a grant: a
 * random id the connect callback makes (newGrant()), sealed with its tokens
 * by commit() — its mark is the profile's connection id — and dropped with
 * them when the Client ID changes. The keeper remembers the
 * grant it opened with, and a token write lands only while the stored grant
 * is still that one (SecretVault::putIfMatches(), decided on the row as it
 * is when written): a refresh that ends after a reconnect (another account
 * or app) or a Client-ID change stores nothing and answers TransientFailure
 * — the caller tries again with the new connection — and this keeper never
 * uses its old access token again; after the destination was deleted it
 * answers REAUTH. A keeper without a grant (tokens from before grants, or
 * an exchange answer held in memory before commit()) never writes and never
 * retries with a stored token: it answers TransientFailure instead. Within
 * one grant two processes refreshing at once both write (the later write
 * wins; both tokens came from the provider). When
 * the provider refuses the refresh token (REAUTH: revoked, or replaced by
 * another process's rotation), the vault is read once more and, only while
 * the grant is still this keeper's, a different stored refresh token is
 * tried once before REAUTH goes back to the caller (which marks the
 * profile). Through the LW app (a $verify given; issue #12) a refresh answer
 * is kept only after its access token was asked at the provider
 * (AccountCheck): the account must be the one recorded at connect, and when
 * it is not, or the provider does not confirm one, nothing is stored
 * (REAUTH). A connection without a recorded account (made before the
 * check) records the confirmed one with that write; while the provider
 * does not confirm one it stores nothing either (the failure passes
 * through). Own-app tokens come straight from the provider and are not
 * asked again. Nothing here is ever written to the job, a log or a message.
 */
final class TokenKeeper {

	public const REFRESH = 'refreshToken';

	public const ACCESS = 'accessToken';

	/** The connection generation (sealed, never shown). */
	public const GRANT = 'grant';

	/** The account the connection was made with (AccountCheck; sealed, never shown). */
	public const IDENTITY = 'identity';

	/** Stored when no account was recorded, so a reconnect never keeps the one before. */
	public const NO_IDENTITY = 'none';

	/** Vault fields no form or command may set, dropped together when the Client ID changes. */
	public const FIELDS = [ self::REFRESH, self::ACCESS, self::GRANT, self::IDENTITY ];

	/** A vault field that gives a profile without a client secret (the LW app) its secrets row; never shown, never a form field, kept with the profile. */
	public const ANCHOR = 'relayAnchor';

	public const MARGIN = 120;

	private const CONNECTION_CONTEXT = 'lw-migration/oauth-connection/v1';

	/**
	 * The refresh token ('' = not connected).
	 *
	 * @var string
	 */
	private string $refresh;

	/**
	 * The cached access token ('' = none).
	 *
	 * @var string
	 */
	private string $access = '';

	/**
	 * When the cached access token expires (Unix time).
	 *
	 * @var int
	 */
	private int $expires = 0;

	/**
	 * The grant of the connection this keeper was opened for ('' = none stored).
	 *
	 * @var string
	 */
	private string $grant;

	/**
	 * The account recorded at connect, checked on a refresh through the LW app.
	 *
	 * @var RefreshedAccount
	 */
	private RefreshedAccount $account;

	/**
	 * Whether this keeper's connection was replaced (it answers TransientFailure from then on).
	 *
	 * @var bool
	 */
	private bool $stale = false;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * @param string                $profileId Profile id.
	 * @param OAuthApp              $app       App.
	 * @param array<string, string> $secrets   Opened secrets of the profile.
	 * @param TokenEndpoint         $endpoint  Token endpoint.
	 * @param SecretVault           $vault     Where new tokens are sealed.
	 * @param \Closure|null         $now       Clock (default time()).
	 * @param \Closure|null         $verify    fn( string $accessToken ): string — the account at the provider (AccountCheck), through the LW app; null: not asked.
	 */
	public function __construct( private string $profileId, private OAuthApp $app, #[\SensitiveParameter] array $secrets, private TokenEndpoint $endpoint, private SecretVault $vault, ?\Closure $now = null, ?\Closure $verify = null ) {
		$this->now     = $now ?? static fn(): int => time();
		$this->refresh = (string) ( $secrets[ self::REFRESH ] ?? '' );
		$this->grant   = (string) ( $secrets[ self::GRANT ] ?? '' );
		$this->account = new RefreshedAccount( (string) ( $secrets[ self::IDENTITY ] ?? '' ), $verify, $app->provider->label );
		$cached        = (string) ( $secrets[ self::ACCESS ] ?? '' );
		if ( 1 === preg_match( '/^(\d{1,12}):(.+)\z/s', $cached, $m ) ) {
			$this->expires = (int) $m[1];
			$this->access  = $m[2];
		}
	}

	public function hasRefresh(): bool {
		return '' !== $this->refresh;
	}

	public function app(): OAuthApp {
		return $this->app;
	}

	/**
	 * A fresh grant for a new connection, made by the connect callback
	 * before it writes anything.
	 */
	public static function newGrant(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Gives a profile a secrets row before its first LW-app connect:
	 * commit() writes only into a row that exists, which a deleted profile
	 * no longer has (ruling R5 of plan M7d).
	 *
	 * @param SecretVault $vault     Vault.
	 * @param string      $profileId Profile id.
	 * @throws \RuntimeException When the row cannot be written (translated).
	 */
	public static function anchor( SecretVault $vault, string $profileId ): void {
		if ( [] === $vault->fields( $profileId ) ) {
			$vault->put( $profileId, [ self::ANCHOR => '1' ] );
		}
	}

	/**
	 * The connection id of a grant (Profile::$connection['id']): a keyed
	 * mark, so the grant itself never leaves the vault. A profile whose
	 * connection id is not the mark of the stored grant has the connection
	 * of one connect and the tokens of another (Task 7 review I1) and is
	 * never used (OAuthDestinationType::http()).
	 *
	 * @param string $grant The grant ('' = none).
	 * @return string 32 hex digits ('' for no grant).
	 */
	public static function connectionId( #[\SensitiveParameter] string $grant ): string {
		if ( '' === $grant ) {
			return '';
		}
		$key = hash_hmac( 'sha256', self::CONNECTION_CONTEXT, wp_salt( 'auth' ), true );

		return substr( hash_hmac( 'sha256', 'grant|' . $grant, $key ), 0, 32 );
	}

	/**
	 * Stores the tokens of a new connection under the grant the callback made
	 * for it (its connection id was committed with the profile first), so
	 * every keeper opened for an earlier connection stops writing. Only into
	 * a profile that still has its secrets.
	 *
	 * @param SecretVault $vault     Vault.
	 * @param string      $profileId Profile id.
	 * @param TokenSet    $set       The exchange answer.
	 * @param string      $grant     The new grant (newGrant()).
	 * @param string      $identity  The account it was made with (AccountCheck; '' stores NO_IDENTITY, so an earlier connection's never stays).
	 * @return bool False when the profile has no secrets any more (deleted meanwhile; nothing written).
	 * @throws \LogicException On a malformed grant (a programming error; nothing written).
	 * @throws PermanentFailure INVALID when the answer has no refresh token or the vault cannot seal or write.
	 */
	public static function commit( SecretVault $vault, string $profileId, #[\SensitiveParameter] TokenSet $set, #[\SensitiveParameter] string $grant, string $identity = '' ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}\z/', $grant ) ) {
			throw new \LogicException( 'A connection is committed under a grant from newGrant().' );
		}
		if ( '' === $set->refreshToken ) {
			throw new PermanentFailure( __( 'The provider sent no refresh token, so backups could not continue later. Connect again.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		try {
			return $vault->putExisting(
				$profileId,
				[
					self::REFRESH  => $set->refreshToken,
					self::ACCESS   => $set->expiresAt . ':' . $set->accessToken,
					self::GRANT    => $grant,
					self::IDENTITY => '' === $identity ? self::NO_IDENTITY : $identity,
				]
			);
		} catch ( \RuntimeException $e ) {
			throw new PermanentFailure( $e->getMessage(), PermanentFailure::INVALID );
		}
	}

	/**
	 * @throws CloudFailure When no usable token can be had (REAUTH when the grant is gone or never existed).
	 */
	public function token(): string {
		if ( ! $this->stale && '' !== $this->access && $this->expires - self::MARGIN > (int) ( $this->now )() ) {
			return $this->access;
		}

		return $this->renew();
	}

	/**
	 * A new access token now (after a 401, or when the cached one is old).
	 *
	 * @throws PermanentFailure REAUTH when not connected or the grant is gone; TransientFailure when the connection changed (any CloudFailure of the endpoint passes through).
	 */
	public function renew(): string {
		if ( $this->stale ) {
			throw $this->changed();
		}
		if ( ! $this->hasRefresh() ) {
			throw $this->notConnected();
		}
		try {
			$set = $this->endpoint->refresh( $this->app, $this->refresh );
		} catch ( PermanentFailure $e ) {
			$set = $this->retry( $e );
		}
		$this->store( $set );

		return $this->access;
	}

	/**
	 * Keeps and seals a refresh answer, only into this keeper's connection.
	 *
	 * @param TokenSet $set Answer.
	 * @throws PermanentFailure REAUTH when the provider names another account than the recorded one or confirms none, or none was recorded (nothing stored), or the profile's secrets are gone (deleted meanwhile), INVALID when the vault cannot seal or write.
	 * @throws TransientFailure When the connection changed meanwhile (nothing stored; the old access token is not used again).
	 */
	public function store( #[\SensitiveParameter] TokenSet $set ): void {
		if ( '' === $this->grant ) {
			throw $this->changed();
		}
		$values = [ self::ACCESS => $set->expiresAt . ':' . $set->accessToken ];
		if ( '' !== $set->refreshToken ) {
			$values[ self::REFRESH ] = $set->refreshToken;
		}
		$this->account->confirm( $set->accessToken );
		try {
			$kept = $this->vault->putIfMatches( $this->profileId, self::GRANT, $this->grant, $values );
		} catch ( \RuntimeException $e ) {
			throw new PermanentFailure( $e->getMessage(), PermanentFailure::INVALID );
		}
		if ( ! $kept ) {
			throw [] === $this->vault->fields( $this->profileId ) ? $this->notConnected() : $this->changed();
		}
		$this->access  = $set->accessToken;
		$this->expires = $set->expiresAt;
		if ( '' !== $set->refreshToken ) {
			$this->refresh = $set->refreshToken;
		}
	}

	/**
	 * After the provider refused the refresh token: one more try with a
	 * different refresh token stored meanwhile, only within this connection.
	 *
	 * @param PermanentFailure $refused The refusal.
	 * @throws PermanentFailure The refusal, when there is nothing else to try.
	 * @throws TransientFailure When the connection changed meanwhile (no request).
	 */
	private function retry( PermanentFailure $refused ): TokenSet {
		$stored = PermanentFailure::REAUTH === $refused->reason ? $this->vault->open( $this->profileId ) : null;
		if ( null === $stored ) {
			throw $refused;
		}
		if ( '' === $this->grant || ! hash_equals( (string) ( $stored[ self::GRANT ] ?? '' ), $this->grant ) ) {
			throw $this->changed();
		}
		$other = (string) ( $stored[ self::REFRESH ] ?? '' );
		if ( '' === $other || $other === $this->refresh ) {
			throw $refused;
		}
		$this->refresh = $other;

		return $this->endpoint->refresh( $this->app, $this->refresh );
	}

	/**
	 * The connection this keeper was opened for was replaced: its tokens are
	 * not used any more, and the caller tries again with the new one.
	 */
	private function changed(): TransientFailure {
		$this->stale   = true;
		$this->access  = '';
		$this->expires = 0;

		/* translators: %s: provider name */
		return new TransientFailure( sprintf( __( 'The connection to %s changed meanwhile; trying again.', 'lw-migration' ), $this->app->provider->label ), TransientFailure::BUSY );
	}

	private function notConnected(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( 'This destination is not connected to %s yet. Choose Connect.', 'lw-migration' ), $this->app->provider->label ), PermanentFailure::REAUTH );
	}
}
