<?php
/**
 * The state parameter of a connect: single use, bound to one admin.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5 and plan E3. The state is 32 random bytes in hex; only an HMAC
 * of it is stored, as the transient name (key derived from the auth salts
 * for this use only), with a record of the user, an HMAC of the login
 * session token, the profile, an HMAC of the Client ID, the redirect address
 * and the PKCE verifier, bound together by a second HMAC so a record changed
 * in the database does not verify. consume() deletes a record it found before
 * any other check (so a refused use, a changed or an expired record is used up
 * too) and goes on only when the delete answered true: of two racing
 * callbacks exactly one proceeds, and a replay finds nothing. With a
 * persistent object cache (Redis, Memcached) the transient lives only in the
 * cache: its delete is atomic, so the single-use gate holds; the cache may
 * evict the record early, which makes the connect fail as expired and the
 * admin starts it again (accepted). The state and the verifier are never
 * logged or put in a message. The caller authenticates the login cookie
 * itself (the callback carries no REST nonce), so the user id passed in
 * comes from that cookie, never from the request. Every state needs a login
 * session: the command line prints a one-time link to the plugin's page
 * instead (ConnectTicket, issue #12), which issues the state in the
 * browser's session, and a record without a session never verifies. The
 * verifier is stored plain for at
 * most TTL seconds: without the authorization code (which never touches the
 * database) it opens nothing. Each MAC field is length-prefixed, so a value
 * cannot be moved across a field boundary.
 */
final class OAuthState {

	public const TTL = 600;

	private const PREFIX = 'lw_migration_oauth_';

	private const KEY_CONTEXT = 'lw-migration/oauth-state/v1';

	private const PATTERN = '/^[a-f0-9]{64}\z/';

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * @param \Closure|null $clock fn(): int (default time()).
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * @param int    $userId      The admin who connects (logged in).
	 * @param string $profileId   Destination profile.
	 * @param string $clientId    Client ID the authorize URL uses.
	 * @param string $redirectUri Redirect address the authorize URL uses.
	 * @param string $verifier    PKCE verifier ('' when the provider gets none).
	 * @return string The state for the authorize URL.
	 * @throws \InvalidArgumentException A profile id that is not Profile::ID_PATTERN, or a verifier that is neither '' nor a PKCE verifier (a caller's bug; fixed words).
	 * @throws \RuntimeException Without a user, a login session, or when the record cannot be stored (translated).
	 */
	public function issue( int $userId, string $profileId, string $clientId, string $redirectUri, #[\SensitiveParameter] string $verifier ): string {
		if ( 1 !== preg_match( Profile::ID_PATTERN, $profileId ) || ( '' !== $verifier && ! Pkce::isVerifier( $verifier ) ) ) {
			throw new \InvalidArgumentException( 'A state needs a profile id and a PKCE verifier or none.' );
		}
		$session = wp_get_session_token();
		if ( $userId <= 0 || '' === $session ) {
			throw new \RuntimeException( __( 'Connecting needs an administrator logged in to this site.', 'lw-migration' ) );
		}
		$key           = $this->key();
		$state         = bin2hex( random_bytes( 32 ) );
		$record        = [
			'user'     => $userId,
			'session'  => self::mark( $key, 'session', $session ),
			'profile'  => $profileId,
			'client'   => self::mark( $key, 'client', $clientId ),
			'redirect' => $redirectUri,
			'verifier' => $verifier,
			'expires'  => ( $this->clock )() + self::TTL,
		];
		$record['mac'] = self::mac( $key, $state, $record );
		if ( ! set_transient( self::transient( $key, $state ), $record, self::TTL ) ) {
			throw new \RuntimeException( __( 'The connection could not be started: this site could not store a temporary value. Try again.', 'lw-migration' ) );
		}

		return $state;
	}

	/**
	 * Uses a state up.
	 *
	 * @param string $state  The state from the callback.
	 * @param int    $userId User of the login cookie.
	 * @return array{profile: string, client: string, redirectUri: string, verifier: string}|null Null when it is malformed, unknown, used, expired, or another user's or session's.
	 */
	public function consume( #[\SensitiveParameter] string $state, int $userId ): ?array {
		$session = wp_get_session_token();
		if ( $userId <= 0 || '' === $session || 1 !== preg_match( self::PATTERN, $state ) ) {
			return null;
		}
		$key    = $this->key();
		$name   = self::transient( $key, $state );
		$record = get_transient( $name );
		if ( false === $record || true !== delete_transient( $name ) ) {
			return null; // Unknown, or another request used it first.
		}
		if ( ! self::isRecord( $record ) || ! hash_equals( self::mac( $key, $state, $record ), $record['mac'] ) ) {
			return null;
		}
		if ( $record['expires'] < ( $this->clock )() || $record['user'] !== $userId || ! hash_equals( $record['session'], self::mark( $key, 'session', $session ) ) ) {
			return null;
		}

		return [
			'profile'     => $record['profile'],
			'client'      => $record['client'],
			'redirectUri' => $record['redirect'],
			'verifier'    => $record['verifier'],
		];
	}

	/**
	 * What a Client ID is compared and remembered as (Profile::$connection['client']).
	 *
	 * @param string $clientId Client ID.
	 */
	public function clientMark( string $clientId ): string {
		return self::mark( $this->key(), 'client', $clientId );
	}

	/**
	 * @param mixed $record Stored value.
	 * @phpstan-assert-if-true array{user: int, session: string, profile: string, client: string, redirect: string, verifier: string, expires: int, mac: string} $record
	 */
	private static function isRecord( $record ): bool {
		if ( ! is_array( $record ) || ! is_int( $record['user'] ?? null ) || ! is_int( $record['expires'] ?? null ) ) {
			return false;
		}
		foreach ( [ 'session', 'profile', 'client', 'redirect', 'verifier', 'mac' ] as $field ) {
			if ( ! is_string( $record[ $field ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function transient( string $key, #[\SensitiveParameter] string $state ): string {
		return self::PREFIX . hash_hmac( 'sha256', 'lookup|' . $state, $key );
	}

	private static function mark( string $key, string $what, #[\SensitiveParameter] string $value ): string {
		return hash_hmac( 'sha256', $what . '|' . $value, $key );
	}

	/**
	 * @param string               $key    Derived key.
	 * @param string               $state  State.
	 * @param array<string, mixed> $record Record fields (mac excluded).
	 */
	private static function mac( string $key, #[\SensitiveParameter] string $state, #[\SensitiveParameter] array $record ): string {
		$fields = [ 'record', $state, $record['user'], $record['session'], $record['profile'], $record['client'], $record['redirect'], $record['verifier'], $record['expires'] ];

		$data = '';
		foreach ( $fields as $field ) {
			$field = (string) $field;
			$data .= strlen( $field ) . ':' . $field;
		}

		return hash_hmac( 'sha256', $data, $key );
	}

	private function key(): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true );
	}
}
