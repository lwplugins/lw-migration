<?php
/**
 * What a WebDAV failure means.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\HttpErrors;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2/21.8 for WebDAV: what HttpErrors calls passing (408, 429, any
 * 5xx but 501/505/507) plus 423 (locked) and 425 is worth another try (with
 * Retry-After; 423, 429 and 503 mean busy); 401 is the password, 403 the
 * folder's permission, 404/409 a missing folder or file, 507 a full storage,
 * a redirect a wrong address (redirects are never followed). The server's
 * answer text is never shown (it may echo paths or user names); the network
 * wording is HttpErrors::transport()'s, the same for every HTTP destination.
 */
final class WebDavErrors {

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param string $where      Address in display form.
	 * @param int    $now        Unix time.
	 */
	public static function classify( int $status, string $retryAfter, string $where, int $now ): CloudFailure {
		$what = 'HTTP ' . $status;
		if ( HttpErrors::passing( $status ) || 423 === $status || 425 === $status ) {
			$busy = in_array( $status, [ 423, 429, 503 ], true );
			/* translators: 1: storage address, 2: error code */
			return new TransientFailure( sprintf( __( '%1$s had a passing problem (%2$s). Trying again.', 'lw-migration' ), $where, $what ), $busy ? TransientFailure::BUSY : TransientFailure::SERVER, $status, HttpErrors::retryAfter( $retryAfter, $now ) );
		}
		switch ( true ) {
			case 401 === $status:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( '%1$s refused the user name or the password (%2$s). With two-factor login, use an app password.', 'lw-migration' ), $where, $what ), PermanentFailure::AUTH, $status );
			case 403 === $status:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( 'The user has no permission for this folder at %1$s (%2$s).', 'lw-migration' ), $where, $what ), PermanentFailure::DENIED, $status );
			case 404 === $status || 409 === $status:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( 'The folder or the file does not exist at %1$s (%2$s). Check the address and the folder.', 'lw-migration' ), $where, $what ), PermanentFailure::MISSING, $status );
			case 507 === $status:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( 'The storage at %1$s is full or over its quota (%2$s).', 'lw-migration' ), $where, $what ), PermanentFailure::FULL, $status );
			case 413 === $status:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( '%1$s refused a part as too large (%2$s).', 'lw-migration' ), $where, $what ), PermanentFailure::TOO_LARGE, $status );
			case $status >= 300 && $status < 400:
				/* translators: 1: storage address, 2: error code */
				return new PermanentFailure( sprintf( __( '%1$s sent the request elsewhere (%2$s): check the address, including https and the trailing slash.', 'lw-migration' ), $where, $what ), PermanentFailure::INVALID, $status );
		}

		/* translators: 1: storage address, 2: error code */
		return new PermanentFailure( sprintf( __( '%1$s refused the request (%2$s).', 'lw-migration' ), $where, $what ), PermanentFailure::INVALID, $status );
	}

	/**
	 * Preflight P-I7: a certificate problem or a request never sent stops the
	 * destination (PermanentFailure INVALID), anything else is passing.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message (only classified).
	 * @param string $where   Address in display form.
	 * @param bool   $reached Whether the request got as far as the transport (false: never sent).
	 */
	public static function transport( string $code, string $message, string $where, bool $reached = true ): CloudFailure {
		return HttpErrors::transport( $code, $message, $where, $reached );
	}
}
