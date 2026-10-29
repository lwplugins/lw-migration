<?php
/**
 * The one-time id of a connect link printed on the command line.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #12 (ruling R12). `wp lw-migration destination connect` has no
 * login session, so it never issues an OAuth state: it prints the plugin's
 * page with a ticket (32 random bytes in hex), and the page, opened by the
 * same administrator, uses the ticket up and issues the real state in that
 * browser's session (ConnectLinks::follow()). The authorize address never
 * leaves the browser, so a leaked link (a CI or hosting-panel log, a
 * terminal recording) is no state another account can be connected with:
 * it works once, within TTL seconds, only for the --user it was issued to.
 * Like OAuthState only an HMAC of the ticket names the transient (key
 * derived from the auth salts for this use only), its record of the user,
 * the profile and the expiry is bound by a second HMAC, and redeem() deletes
 * a record it found before any other check, going on only when the delete
 * answered true (of two racing uses exactly one proceeds). The ticket is
 * never logged or put in a message.
 */
final class ConnectTicket {

	public const TTL = 600;

	private const PREFIX = 'lw_migration_connect_';

	private const KEY_CONTEXT = 'lw-migration/connect-ticket/v1';

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
	 * @param int    $userId    The administrator the link is for (the command line's --user).
	 * @param string $profileId Destination profile.
	 * @return string The ticket.
	 * @throws \InvalidArgumentException For a profile id that is not Profile::ID_PATTERN (a caller's bug; fixed words).
	 * @throws \RuntimeException Without a user, or when the record cannot be stored (translated).
	 */
	public function issue( int $userId, string $profileId ): string {
		if ( 1 !== preg_match( Profile::ID_PATTERN, $profileId ) ) {
			throw new \InvalidArgumentException( 'A connect ticket needs a profile id.' );
		}
		if ( $userId <= 0 ) {
			throw new \RuntimeException( __( 'Connecting needs an administrator logged in to this site.', 'lw-migration' ) );
		}
		$key           = $this->key();
		$ticket        = bin2hex( random_bytes( 32 ) );
		$record        = [
			'user'    => $userId,
			'profile' => $profileId,
			'expires' => ( $this->clock )() + self::TTL,
		];
		$record['mac'] = self::mac( $key, $ticket, $record );
		if ( ! set_transient( self::transient( $key, $ticket ), $record, self::TTL ) ) {
			throw new \RuntimeException( __( 'The connection could not be started: this site could not store a temporary value. Try again.', 'lw-migration' ) );
		}

		return $ticket;
	}

	/**
	 * Uses a ticket up.
	 *
	 * @param string $ticket The ticket from the link.
	 * @param int    $userId The logged-in administrator who opened it.
	 * @return string|null The profile id; null when it is malformed, unknown, used, expired, changed or another user's.
	 */
	public function redeem( #[\SensitiveParameter] string $ticket, int $userId ): ?string {
		if ( $userId <= 0 || 1 !== preg_match( self::PATTERN, $ticket ) ) {
			return null;
		}
		$key    = $this->key();
		$name   = self::transient( $key, $ticket );
		$record = get_transient( $name );
		if ( false === $record || true !== delete_transient( $name ) ) {
			return null; // Unknown, or another request used it first.
		}
		if ( ! is_array( $record ) || ! is_int( $record['user'] ?? null ) || ! is_int( $record['expires'] ?? null ) || ! is_string( $record['profile'] ?? null ) || ! is_string( $record['mac'] ?? null ) ) {
			return null;
		}
		if ( ! hash_equals( self::mac( $key, $ticket, $record ), $record['mac'] ) || $record['expires'] < ( $this->clock )() || $record['user'] !== $userId ) {
			return null;
		}

		return $record['profile'];
	}

	private static function transient( string $key, #[\SensitiveParameter] string $ticket ): string {
		return self::PREFIX . hash_hmac( 'sha256', 'lookup|' . $ticket, $key );
	}

	/**
	 * @param string               $key    Derived key.
	 * @param string               $ticket Ticket.
	 * @param array<string, mixed> $record Record fields (mac excluded).
	 */
	private static function mac( string $key, #[\SensitiveParameter] string $ticket, array $record ): string {
		$data = '';
		foreach ( [ 'ticket', $ticket, $record['user'], $record['profile'], $record['expires'] ] as $field ) {
			$field = (string) $field;
			$data .= strlen( $field ) . ':' . $field;
		}

		return hash_hmac( 'sha256', $data, $key );
	}

	private function key(): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true );
	}
}
