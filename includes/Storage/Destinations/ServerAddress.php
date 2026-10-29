<?php
/**
 * The host and port rules of a server an admin types in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 (SSRF), checked when settings are saved (preflight P-C2, H5),
 * shared by every type that takes a server: a lower-case name of letters,
 * digits, dots and hyphens (at most 253 characters) or an IPv6 address (with
 * or without brackets); a last label that is all digits or 0x-hex must be a
 * canonical dotted quad; an address, or a name that resolves now, must be
 * allowed by AddressGuard. A name that does not resolve now is accepted: it
 * is checked again on every connection. Refusals name their field and never
 * repeat the value. connectTo() is the check before every connection of a
 * type that does not speak HTTP (FTP, SFTP; H1–H2): the client is handed
 * the checked address, never the name.
 */
final class ServerAddress {

	private const NAME = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\z/';

	private const NAME_LENGTH = 253;

	/**
	 * @param mixed       $raw     Host as typed (an IPv6 address may be in brackets).
	 * @param string      $field   Settings key named by a refusal.
	 * @param string|null $message Refusal text (default: a generic one).
	 * @return string Lower-case host; an IPv6 address without brackets.
	 * @throws FieldException On a host the rule refuses.
	 */
	public static function host( $raw, string $field, ?string $message = null ): string {
		$host = self::shaped( $raw );
		if ( null === $host || ! self::addressable( $host ) ) {
			throw new FieldException( $field, $message ?? __( 'Enter the server\'s host name or IP address.', 'lw-migration' ) );
		}

		return $host;
	}

	/**
	 * Final review m3: what a stored setting that no longer holds means
	 * before a connection. A host that still has its shape but now resolves
	 * to a refused range gets connectTo()'s text, not the form's prompt.
	 *
	 * @param FieldException $e     The refusal.
	 * @param mixed          $raw   The stored host.
	 * @param string         $where scheme://host:port for messages.
	 */
	public static function storedFailure( FieldException $e, $raw, string $where ): PermanentFailure {
		$host = self::shaped( $raw );
		if ( null !== $host && ! self::addressable( $host ) ) {
			return self::refused( $where );
		}

		return new PermanentFailure( $e->getMessage(), PermanentFailure::INVALID );
	}

	/**
	 * @param mixed       $raw     Port as typed: a number, its digits as text, or empty for the default.
	 * @param int         $default Port when none is given.
	 * @param string      $field   Settings key named by a refusal.
	 * @param string|null $message Refusal text (default: a generic one).
	 * @throws FieldException When it is not a whole number from 1 to 65535.
	 */
	public static function port( $raw, int $default, string $field, ?string $message = null ): int {
		$text = is_string( $raw ) ? trim( $raw ) : null;
		if ( null === $raw || '' === $text ) {
			$port = $default;
		} elseif ( is_int( $raw ) ) {
			$port = $raw;
		} else {
			$port = null !== $text && 1 === preg_match( '/^\d{1,5}\z/', $text ) ? (int) $text : 0;
		}
		if ( $port < 1 || $port > 65535 ) {
			throw new FieldException( $field, $message ?? __( 'A port is a number from 1 to 65535.', 'lw-migration' ) );
		}

		return $port;
	}

	/**
	 * Resolves the host and checks every address (an address written out is
	 * checked as it is); the connection then goes to the address answered,
	 * so a second lookup cannot lead it elsewhere.
	 *
	 * @param string            $host  Host as stored (an IPv6 address without brackets).
	 * @param string            $where scheme://host:port for messages.
	 * @param AddressGuard|null $guard Address check (default: real DNS, or the tests' resolver).
	 * @return string The first address, to connect to.
	 * @throws PermanentFailure INVALID when any address is in a refused range.
	 * @throws TransientFailure NETWORK when the name does not resolve now.
	 */
	public static function connectTo( string $host, string $where, ?AddressGuard $guard ): string {
		$ips = ( $guard ?? new AddressGuard() )->addresses( $host );
		if ( [] === $ips ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( 'The address %s cannot be looked up right now.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}
		if ( ! AddressGuard::allAllowed( $ips ) ) {
			throw self::refused( $where );
		}

		return $ips[0];
	}

	/**
	 * @param string $where scheme://host:port for messages.
	 */
	private static function refused( string $where ): PermanentFailure {
		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( 'The address %s points to a network address that is not allowed for storage.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
	}

	/**
	 * A host's shape, without a lookup.
	 *
	 * @param mixed $raw Host as typed or stored (an IPv6 address may be in brackets).
	 * @return string|null Lower-case host (an IPv6 address without brackets), null when it is not one.
	 */
	private static function shaped( $raw ): ?string {
		$text      = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		$bracketed = str_starts_with( $text, '[' ) && str_ends_with( $text, ']' );
		$host      = $bracketed ? substr( $text, 1, -1 ) : $text;
		$v6        = str_contains( $host, ':' ) && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		$named     = ! $bracketed && strlen( $host ) <= self::NAME_LENGTH && 1 === preg_match( self::NAME, $host );

		return $v6 || $named ? $host : null;
	}

	/**
	 * SSRF: a last label that is all digits or 0x-hex is an address in a
	 * disguised form (decimal, hex, octal) and must be a canonical dotted
	 * quad; an address or a name that resolves must not be in a refused range.
	 * A name that does not resolve now is left to the request (it is checked
	 * again, and pinned, on every request).
	 *
	 * @param string $host Lower-case host, brackets kept or not for IPv6.
	 */
	public static function addressable( string $host ): bool {
		$bare = trim( $host, '[]' );
		if ( ! str_contains( $bare, ':' ) && 1 === preg_match( '/(^|\.)(\d+|0x[0-9a-f]*)\z/', $bare ) && false === filter_var( $bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		$ips = ( new AddressGuard() )->addresses( $bare );

		return [] === $ips || AddressGuard::allAllowed( $ips );
	}
}
