<?php
/**
 * What a cURL error of an FTPS connection means.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #5. CurlFtpsConnection's failures of the connection itself, from
 * cURL's error code and the server's replies (cURL hands FTP replies to the
 * header callback, codes included): a server that cannot be reached, a
 * broken transport or a login turned away for now passes (NETWORK, P-M12);
 * a refused login or FTPS, refused passive mode or a cURL that cannot do it
 * stops the destination. An unverified certificate (60, or 90 for a pinned
 * key) is the connection's to explain, with the certificate it reads again;
 * a failure of one file (a missing one, a refused STOR, REST or quote
 * command) is answered by the connection as false, -1 or null.
 */
final class CurlFtpsFailure {

	public const ACCESS_DENIED = 9;

	public const NO_REST = 31;

	private const UNREACHABLE = 7;

	private const BROKEN = [ 8, 12, 18, 28, 35, 52, 55, 56 ];

	private const PASV_REFUSED = 13;

	private const NO_FTPS = 64;

	private const LOGIN_DENIED = 67;

	private const UNVERIFIED = [ 60, 90 ];

	private const NOT_BUILT_IN = [ 1, 4, 48 ];

	/**
	 * Whether the code is a certificate (or pinned key) the TLS handshake refused.
	 *
	 * @param int $errno cURL's error code.
	 */
	public static function unverified( int $errno ): bool {
		return in_array( $errno, self::UNVERIFIED, true );
	}

	/**
	 * @param int                $errno   cURL's error code (not 0, not unverified()).
	 * @param array<int, string> $replies The server's replies to the request.
	 * @param string             $where   ftps://host:port for messages.
	 * @return CloudFailure|null Null for a failure of one file, not of the connection.
	 */
	public static function connection( int $errno, array $replies, string $where ): ?CloudFailure {
		if ( self::UNREACHABLE === $errno ) {
			/* translators: %s: server address */
			return new TransientFailure( sprintf( __( '%s cannot be reached.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}
		if ( in_array( $errno, self::BROKEN, true ) ) {
			return self::brokenOff( $where );
		}
		if ( self::LOGIN_DENIED === $errno ) {
			return self::loginFailure( $replies, $where );
		}
		if ( self::NO_FTPS === $errno ) {
			/* translators: %s: server address */
			return new PermanentFailure( sprintf( __( '%s did not accept the encrypted (FTPS) login: the server may not offer FTPS, or the user name or the password is wrong.', 'lw-migration' ), $where ), PermanentFailure::AUTH );
		}
		if ( self::PASV_REFUSED === $errno ) {
			/* translators: %s: server address */
			return new PermanentFailure( sprintf( __( '%s did not accept passive mode. Turn passive mode off, or ask the host.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}

		return in_array( $errno, self::NOT_BUILT_IN, true ) ? new PermanentFailure( CurlFtpsConnection::unsupported(), PermanentFailure::INVALID ) : null;
	}

	/**
	 * What a refused login means: a server turning logins away for now
	 * passes; anything else is the user name or the password.
	 *
	 * @param array<int, string> $replies The server's replies.
	 * @param string             $where   ftps://host:port for messages.
	 */
	public static function loginFailure( array $replies, string $where ): CloudFailure {
		if ( 1 === preg_match( '/^421|too many|maximum number|try again|temporar/im', implode( "\n", $replies ) ) ) {
			/* translators: %s: server address */
			return new TransientFailure( sprintf( __( '%s turned the login away for now (too many connections). Trying again later.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
		}

		/* translators: %s: server address */
		return new PermanentFailure( sprintf( __( '%s refused the user name or the password.', 'lw-migration' ), $where ), PermanentFailure::AUTH );
	}

	public static function brokenOff( string $where ): TransientFailure {
		/* translators: %s: server address */
		return new TransientFailure( sprintf( __( 'The connection to %s broke off.', 'lw-migration' ), $where ), TransientFailure::NETWORK );
	}
}
