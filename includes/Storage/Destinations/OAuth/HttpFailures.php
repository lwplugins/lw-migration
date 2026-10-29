<?php
/**
 * The failure kind of an answer every provider shares.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\HttpErrors;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2/21.8 and preflight P-I1: what is passing, Retry-After and the
 * transport failures come from HttpErrors (so a certificate problem, a
 * request never sent, 501 and 505 stop the destination, as for S3 and
 * WebDAV); this class adds only the provider-neutral status map: 429 BUSY,
 * other passing statuses SERVER, 401 REAUTH (after OAuthHttp's one
 * refresh), 403 DENIED, 404 MISSING, 507 FULL, anything else INVALID. Each
 * provider's error map looks at the body first and falls back to this.
 * Messages name the provider and a fixed reason only — never the
 * provider's own text, a token or an address.
 */
final class HttpFailures {

	/** Deepest JSON read (provider answers are a few levels deep). */
	private const DEPTH = 32;

	/**
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message (only classified, never shown).
	 * @param string $label   Provider name.
	 */
	public static function transport( string $code, string $message, string $label ): CloudFailure {
		return HttpErrors::transport( $code, $message, $label );
	}

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $label      Provider name.
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param int    $now        Unix time.
	 */
	public static function generic( int $status, string $label, string $retryAfter, int $now ): CloudFailure {
		if ( HttpErrors::passing( $status ) ) {
			$after = self::retryAfter( $retryAfter, $now );
			/* translators: %s: provider name */
			return 429 === $status ? new TransientFailure( sprintf( __( '%s asked to slow down; trying again later.', 'lw-migration' ), $label ), TransientFailure::BUSY, $status, $after )
				/* translators: 1: provider name, 2: HTTP status */
				: new TransientFailure( sprintf( __( '%1$s answered with a server error (%2$d); trying again later.', 'lw-migration' ), $label, $status ), TransientFailure::SERVER, $status, $after );
		}
		switch ( $status ) {
			case 401:
				/* translators: %s: provider name */
				return new PermanentFailure( sprintf( __( '%s no longer accepts this connection. Connect the account again.', 'lw-migration' ), $label ), PermanentFailure::REAUTH, $status );
			case 403:
				/* translators: %s: provider name */
				return new PermanentFailure( sprintf( __( '%s refused the request (no permission).', 'lw-migration' ), $label ), PermanentFailure::DENIED, $status );
			case 404:
				/* translators: %s: provider name */
				return new PermanentFailure( sprintf( __( '%s did not find the file or folder.', 'lw-migration' ), $label ), PermanentFailure::MISSING, $status );
			case 507:
				/* translators: %s: provider name */
				return new PermanentFailure( sprintf( __( 'The %s storage is full.', 'lw-migration' ), $label ), PermanentFailure::FULL, $status );
			default:
				/* translators: 1: provider name, 2: HTTP status */
				return new PermanentFailure( sprintf( __( '%1$s refused the request (%2$d).', 'lw-migration' ), $label, $status ), PermanentFailure::INVALID, $status );
		}
	}

	/**
	 * @param string $body JSON answer.
	 * @return array<string, mixed> Decoded object, [] for anything else (a list, a scalar, too deep, not JSON).
	 */
	public static function json( string $body ): array {
		$body = ltrim( $body );
		$json = str_starts_with( $body, '{' ) ? json_decode( $body, true, self::DEPTH ) : null;

		return is_array( $json ) ? $json : [];
	}

	/**
	 * @param string $value Retry-After: seconds or an HTTP date.
	 * @param int    $now   Unix time.
	 */
	public static function retryAfter( string $value, int $now ): ?int {
		return HttpErrors::retryAfter( $value, $now );
	}
}
