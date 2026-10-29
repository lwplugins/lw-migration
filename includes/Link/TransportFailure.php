<?php
/**
 * The user's words for a request that got no answer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Http\RangeResponse;

defined( 'ABSPATH' ) || exit;

/**
 * A transport's own message may name local paths (a temporary answer file,
 * a certificate bundle), so it never reaches the user: its kind is told in
 * fixed words instead. The kinds come from the safe API's address check, a
 * request that was never sent (blocked, or no cURL to pin the address with:
 * Http\SafeTransport) and cURL's error numbers; everything else is "no
 * answer arrived".
 */
final class TransportFailure {

	/** Error numbers of cURL for a failed TLS handshake or certificate check. */
	private const TLS = [ 35, 51, 58, 60, 77, 83, 90, 91 ];

	/** The fsockopen transport's certificate name mismatch (Requests Transport\Fsockopen). */
	private const NO_MATCH = 'SSL certificate did not match the requested domain name';

	/**
	 * @param string $code    The WP_Error code.
	 * @param string $message The WP_Error message (only classified, never shown).
	 */
	public static function text( string $code, string $message ): string {
		if ( RangeResponse::isAddressRefusal( $code, $message ) ) {
			return __( 'this site does not send requests to this address (a local or internal address, or an unusual port).', 'lw-migration' );
		}
		if ( 'http_request_not_executed' === $code ) {
			return __( "this site did not send the request: WordPress or a plugin blocks it, the site's WordPress proxy setting prevents checking the address, or PHP lacks the cURL extension needed to check the address.", 'lw-migration' );
		}
		$number = self::curlNumber( $message );
		if ( 28 === $number ) {
			return __( 'it did not answer in time.', 'lw-migration' );
		}
		if ( 6 === $number ) {
			return __( 'its name cannot be looked up.', 'lw-migration' );
		}
		if ( 7 === $number ) {
			return __( 'the connection was refused.', 'lw-migration' );
		}
		if ( self::isTls( $message ) ) {
			return __( 'the secure connection failed. Check the certificate of that site.', 'lw-migration' );
		}

		return __( 'no answer arrived.', 'lw-migration' );
	}

	/**
	 * Whether the TLS handshake or the certificate check failed: one of cURL's
	 * TLS error numbers, or the fsockopen transport's certificate name check
	 * (Requests' ssl.no_match exception text, WordPress 7.1.2).
	 *
	 * @param string $message The WP_Error message (only classified, never shown).
	 */
	public static function isTls( string $message ): bool {
		return in_array( self::curlNumber( $message ), self::TLS, true ) || self::NO_MATCH === $message;
	}

	/**
	 * @param string $message "cURL error N: ..." from the Requests cURL transport.
	 * @return int The number, 0 for any other message.
	 */
	private static function curlNumber( string $message ): int {
		return 1 === preg_match( '/^cURL error ([0-9]{1,4}):/', $message, $m ) ? (int) $m[1] : 0;
	}
}
