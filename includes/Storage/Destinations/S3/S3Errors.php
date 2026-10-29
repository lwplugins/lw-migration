<?php
/**
 * What an S3 failure means.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\HttpErrors;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2/21.8: 408, 429, 5xx and S3's SlowDown/InternalError/
 * ServiceUnavailable/RequestTimeout are worth another try (with the server's
 * Retry-After, at most 300 seconds), and so is a transport failure
 * except a request never sent or a certificate problem (HttpErrors);
 * everything else stops the destination. The message is ours: the S3 error code and the endpoint in
 * display form, never the server's message text (a SignatureDoesNotMatch
 * answer echoes the string to sign and the signature) and never a transport
 * message (it may name a local file).
 */
final class S3Errors {

	private const TRANSIENT = [ 'SlowDown', 'InternalError', 'ServiceUnavailable', 'RequestTimeout', 'OperationAborted' ];

	private const AUTH = [ 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AuthorizationHeaderMalformed', 'InvalidToken', 'ExpiredToken', 'InvalidSecurity' ];

	private const DENIED = [ 'AccessDenied', 'AllAccessDisabled', 'AccountProblem' ];

	/**
	 * @param int    $status     HTTP status (not 2xx).
	 * @param string $body       Answer body.
	 * @param string $retryAfter Retry-After header ('' = none).
	 * @param string $where      Endpoint in display form.
	 * @param int    $now        Unix time.
	 */
	public static function classify( int $status, string $body, string $retryAfter, string $where, int $now ): CloudFailure {
		$code = (string) ( S3Xml::error( $body )['code'] ?? '' );
		$what = '' === $code ? 'HTTP ' . $status : $code;
		if ( HttpErrors::passing( $status ) || in_array( $code, self::TRANSIENT, true ) ) {
			$busy = 429 === $status || 503 === $status || 'SlowDown' === $code;
			/* translators: 1: storage address, 2: error code */
			return new TransientFailure( sprintf( __( '%1$s had a passing problem (%2$s). Trying again.', 'lw-migration' ), $where, $what ), $busy ? TransientFailure::BUSY : TransientFailure::SERVER, $status, HttpErrors::retryAfter( $retryAfter, $now ) );
		}

		return new PermanentFailure( self::text( $code, $status, $where, $what ), self::reason( $code, $status ), $status );
	}

	/**
	 * The wording every HTTP destination shares (HttpErrors::transport()).
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message (only classified).
	 * @param string $where   Endpoint in display form.
	 * @param bool   $reached Whether the request got as far as the transport (false: never sent).
	 */
	public static function transport( string $code, string $message, string $where, bool $reached = true ): CloudFailure {
		return HttpErrors::transport( $code, $message, $where, $reached );
	}

	/**
	 * @param string $value Retry-After: seconds or an HTTP date.
	 * @param int    $now   Unix time.
	 * @return int|null Seconds (0..300), null when absent or unreadable.
	 */
	public static function retryAfter( string $value, int $now ): ?int {
		return HttpErrors::retryAfter( $value, $now );
	}

	private static function reason( string $code, int $status ): string {
		if ( 'RequestTimeTooSkewed' === $code ) {
			return PermanentFailure::CLOCK;
		}
		if ( in_array( $code, self::AUTH, true ) || 401 === $status ) {
			return PermanentFailure::AUTH;
		}
		if ( 'QuotaExceeded' === $code || 507 === $status ) {
			return PermanentFailure::FULL;
		}
		if ( in_array( $code, self::DENIED, true ) ) {
			return PermanentFailure::DENIED;
		}
		if ( 'NoSuchUpload' === $code ) {
			return PermanentFailure::SESSION_GONE;
		}
		if ( 'NoSuchBucket' === $code || 'NoSuchKey' === $code || 404 === $status ) {
			return PermanentFailure::MISSING;
		}
		if ( 'EntityTooLarge' === $code || 413 === $status ) {
			return PermanentFailure::TOO_LARGE;
		}

		return 403 === $status ? PermanentFailure::DENIED : PermanentFailure::INVALID;
	}

	private static function text( string $code, int $status, string $where, string $what ): string {
		switch ( self::reason( $code, $status ) ) {
			case PermanentFailure::CLOCK:
				/* translators: %s: storage address */
				return sprintf( __( 'The clock of this server differs too much from the one of %s. Fix the server time.', 'lw-migration' ), $where );
			case PermanentFailure::AUTH:
				/* translators: 1: storage address, 2: error code */
				return sprintf( __( '%1$s refused the access key or the secret key (%2$s). Check both, and the region.', 'lw-migration' ), $where, $what );
			case PermanentFailure::FULL:
				/* translators: 1: storage address, 2: error code */
				return sprintf( __( 'The storage at %1$s is full or over its quota (%2$s).', 'lw-migration' ), $where, $what );
			case PermanentFailure::DENIED:
				/* translators: 1: storage address, 2: error code */
				return sprintf( __( 'The access key has no permission for this bucket or folder at %1$s (%2$s).', 'lw-migration' ), $where, $what );
			case PermanentFailure::SESSION_GONE:
				/* translators: %s: storage address */
				return sprintf( __( '%s no longer knows this unfinished upload.', 'lw-migration' ), $where );
			case PermanentFailure::MISSING:
				/* translators: 1: storage address, 2: error code */
				return sprintf( __( 'The bucket or the file does not exist at %1$s (%2$s).', 'lw-migration' ), $where, $what );
			case PermanentFailure::TOO_LARGE:
				/* translators: 1: storage address, 2: error code */
				return sprintf( __( '%1$s refused a part as too large (%2$s).', 'lw-migration' ), $where, $what );
		}
		if ( $status >= 300 && $status < 400 ) {
			/* translators: 1: storage address, 2: error code */
			return sprintf( __( '%1$s sent the request elsewhere (%2$s): the bucket may be in another region, or path-style addressing may be needed.', 'lw-migration' ), $where, $what );
		}

		/* translators: 1: storage address, 2: error code */
		return sprintf( __( '%1$s refused the request (%2$s).', 'lw-migration' ), $where, $what );
	}
}
