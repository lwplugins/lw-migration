<?php
/**
 * Download tokens.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * The download link carries this token, never the REST nonce (which guards
 * every route and must not land in access logs or the browser history). A
 * token is random and names one backup for one user in one login session; it
 * can be used again until it expires (a download manager resumes by asking
 * for the same URL again), one hour by default (filter
 * `lw_migration_download_token_ttl`, 60 seconds to one day). A leaked link
 * alone opens nothing: the request also needs the same user's session
 * cookie, and logging out ends the session. Only an HMAC of the token (key
 * derived from the auth salts) is stored, as the transient name, together
 * with a hash of the session token; the record is bound to
 * user|session|folder|name|expiry by a second HMAC, so a record changed in
 * the database does not verify.
 *
 * The caller must authenticate the logged_in cookie itself: a REST GET
 * without the REST nonce runs as user 0 (see Rest\Routes), so the user id
 * and session passed here must come from validating that cookie, never
 * from the request parameters.
 */
final class DownloadToken {

	public const DEFAULT_TTL = 3600;

	public const MIN_TTL = 60;

	public const MAX_TTL = 86400;

	private const PREFIX = 'lw_migration_download_';

	/** Binds the derived key to this one use. */
	private const KEY_CONTEXT = 'lw-migration/download-token/v1';

	private const TOKEN_PATTERN = '/^[a-f0-9]{64}\z/';

	/**
	 * The current time: fn(): int.
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
	 * @param int    $userId User who may use the token (in the current login session).
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @param string $name   Backup file name.
	 * @return string The token (64 hex characters).
	 * @throws \InvalidArgumentException When the user, session, folder or name is not valid.
	 * @throws \RuntimeException When the token cannot be stored.
	 */
	public function issue( int $userId, string $folder, string $name ): string {
		return $this->grant( $userId, $folder, $name )['token'];
	}

	/**
	 * Issues a token and tells when it stops working (the expiry stored with it).
	 *
	 * @param int    $userId User who may use the token (in the current login session).
	 * @param string $folder Backup::OWN or Backup::EXTERNAL.
	 * @param string $name   Backup file name.
	 * @return array{token: string, expires: int} The token (64 hex characters) and its Unix expiry.
	 * @throws \InvalidArgumentException When the user, session, folder or name is not valid.
	 * @throws \RuntimeException When the token cannot be stored.
	 */
	public function grant( int $userId, string $folder, string $name ): array {
		$session = wp_get_session_token();
		if ( $userId <= 0 || '' === $session || ! in_array( $folder, [ Backup::OWN, Backup::EXTERNAL ], true ) || ! BackupName::isValid( $name ) ) {
			throw new \InvalidArgumentException( 'No download token for this backup.' );
		}
		$token         = bin2hex( random_bytes( 32 ) );
		$key           = $this->key();
		$ttl           = self::ttl();
		$record        = [
			'user'    => $userId,
			'session' => self::session( $key, $session ),
			'folder'  => $folder,
			'name'    => $name,
			'expires' => ( $this->clock )() + $ttl,
		];
		$record['mac'] = self::mac( $key, $token, $record );
		if ( ! set_transient( self::transient( $key, $token ), $record, $ttl ) ) {
			throw new \RuntimeException( 'The download token could not be stored.' );
		}

		return [
			'token'   => $token,
			'expires' => $record['expires'],
		];
	}

	/**
	 * Checks a token without using it up.
	 *
	 * @param string $token  Token from the link.
	 * @param int    $userId Logged-in user (the session comes from the login cookie).
	 * @return array{folder: string, name: string}|null The backup, or null when the token is not valid for this user and session now.
	 */
	public function validate( string $token, int $userId ): ?array {
		$session = wp_get_session_token();
		if ( $userId <= 0 || '' === $session || 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		$key       = $this->key();
		$transient = self::transient( $key, $token );
		$record    = get_transient( $transient );
		if ( ! self::isRecord( $record ) || ! hash_equals( self::mac( $key, $token, $record ), $record['mac'] ) ) {
			return null;
		}
		if ( $record['expires'] < ( $this->clock )() ) {
			delete_transient( $transient );
			return null;
		}
		if ( $record['user'] !== $userId || ! hash_equals( $record['session'], self::session( $key, $session ) ) ) {
			return null;
		}

		return [
			'folder' => $record['folder'],
			'name'   => $record['name'],
		];
	}

	/**
	 * Seconds a token lives: the filter, clamped to MIN_TTL..MAX_TTL; a value that is not a
	 * finite number within the integer range falls back to DEFAULT_TTL (no cast warning).
	 * Public so the issuer can tell the browser when a new link is needed.
	 */
	public static function ttl(): int {
		$ttl   = apply_filters( 'lw_migration_download_token_ttl', self::DEFAULT_TTL );
		$float = is_int( $ttl ) || is_float( $ttl ) || ( is_string( $ttl ) && is_numeric( $ttl ) ) ? (float) $ttl : NAN;
		if ( ! is_finite( $float ) || abs( $float ) >= (float) PHP_INT_MAX ) {
			return self::DEFAULT_TTL;
		}

		return (int) max( self::MIN_TTL, min( self::MAX_TTL, $float ) );
	}

	/**
	 * @param mixed $record Stored value.
	 * @phpstan-assert-if-true array{user: int, session: string, folder: string, name: string, expires: int, mac: string} $record
	 */
	private static function isRecord( $record ): bool {
		return is_array( $record )
			&& is_int( $record['user'] ?? null )
			&& is_string( $record['session'] ?? null )
			&& is_string( $record['folder'] ?? null )
			&& is_string( $record['name'] ?? null )
			&& is_int( $record['expires'] ?? null )
			&& is_string( $record['mac'] ?? null );
	}

	private static function transient( string $key, string $token ): string {
		return self::PREFIX . hash_hmac( 'sha256', 'lookup|' . $token, $key );
	}

	private static function session( string $key, string $session ): string {
		return hash_hmac( 'sha256', 'session|' . $session, $key );
	}

	/**
	 * @param string                                                                        $key    Derived key.
	 * @param string                                                                        $token  Token.
	 * @param array{user: int, session: string, folder: string, name: string, expires: int} $record Record fields.
	 */
	private static function mac( string $key, string $token, array $record ): string {
		$fields = [ 'record', $token, $record['user'], $record['session'], $record['folder'], $record['name'], $record['expires'] ];

		return hash_hmac( 'sha256', implode( '|', $fields ), $key );
	}

	private function key(): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true );
	}
}
