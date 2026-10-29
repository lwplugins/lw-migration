<?php
/**
 * What an HTTP storage failure means, for every HTTP destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Http\RangeResponse;
use LightweightPlugins\Migration\Link\TransportFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2/21.8, shared by S3Errors and WebDavErrors so every HTTP
 * destination words a network problem the same way: a request never sent
 * or a certificate problem stops the destination, any other transport
 * failure is worth another try; 408, 429 and 5xx (but 501, 505, 507) are
 * passing; Retry-After is honoured up to MAX_WAIT seconds. The message is
 * ours, never a transport message (it may name a local file).
 */
final class HttpErrors {

	private const MAX_WAIT = 300;

	/** The cURL error numbers of a certificate problem (58, 60, 77, 83, 90), not 35: a handshake that often fails in passing. */
	private const CERTIFICATE = [ 58, 60, 77, 83, 90 ];

	/** The fsockopen transport's certificate name mismatch (Requests ssl.no_match). */
	private const NO_MATCH = 'SSL certificate did not match the requested domain name';

	/**
	 * A request WordPress did not send (a pre_http_request short-circuit, a
	 * blocked host, a refused address) or a certificate problem stops the
	 * destination: asking again cannot help. Anything else, a broken TLS
	 * handshake (cURL 35) included, is worth another try.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message (only classified).
	 * @param string $where   Address in display form.
	 * @param bool   $reached Whether the request got as far as the transport (false: never sent).
	 */
	public static function transport( string $code, string $message, string $where, bool $reached = true ): CloudFailure {
		if ( ! $reached || RangeResponse::isRefusal( $code, $message ) ) {
			/* translators: %s: storage address */
			return new PermanentFailure( sprintf( __( 'This site did not send the request to %s: WordPress or a plugin blocks requests to it.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}
		if ( self::certificateProblem( $message ) ) {
			/* translators: %s: storage address */
			return new PermanentFailure( sprintf( __( 'The secure connection to %s failed. Check its certificate and address.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}

		/* translators: 1: storage address, 2: what went wrong, e.g. "it did not answer in time." */
		return new TransientFailure( sprintf( __( '%1$s cannot be reached: %2$s', 'lw-migration' ), $where, TransportFailure::text( $code, $message ) ), TransientFailure::NETWORK );
	}

	/**
	 * @param string $value Retry-After: seconds or an HTTP date.
	 * @param int    $now   Unix time.
	 * @return int|null Seconds (0..MAX_WAIT), null when absent or unreadable.
	 */
	public static function retryAfter( string $value, int $now ): ?int {
		$value = trim( $value );
		if ( 1 === preg_match( '/^\d{1,9}\z/', $value ) ) {
			return min( self::MAX_WAIT, (int) $value );
		}
		$date = 1 === preg_match( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT\z/', $value );
		$time = $date ? strtotime( $value, $now ) : false;

		return false === $time ? null : max( 0, min( self::MAX_WAIT, $time - $now ) );
	}

	/**
	 * 408, 429 and 5xx are worth another try, except 501, 505 and 507 (storage full).
	 *
	 * @param int $status HTTP status.
	 */
	public static function passing( int $status ): bool {
		return 408 === $status || 429 === $status || ( $status >= 500 && ! in_array( $status, [ 501, 505, 507 ], true ) );
	}

	/**
	 * @param string $message WP_Error message (only classified, never shown).
	 */
	private static function certificateProblem( string $message ): bool {
		if ( self::NO_MATCH === $message ) {
			return true;
		}

		return 1 === preg_match( '/^cURL error ([0-9]{1,4}):/', $message, $m ) && in_array( (int) $m[1], self::CERTIFICATE, true );
	}
}
