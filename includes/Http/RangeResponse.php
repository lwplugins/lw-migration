<?php
/**
 * Checks on the answers of a range download.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Reading and judging one HTTP answer: Content-Range, Content-Length, status,
 * the received size and transport errors. Everything except header(), length()
 * and isRefusal() (which read WordPress data) is pure.
 */
final class RangeResponse {

	/** Answers worth asking again after a pause. */
	public const BUSY_STATUSES = [ 429, 500, 502, 503, 504 ];

	/**
	 * Whether a WP_Error is the safe API refusing the address (WP_Http::request()
	 * in WordPress 7.1.2): a blocked host (`http_request_not_executed`) or a failed
	 * address check, also for a redirect target (`http_request_failed` with core's
	 * "A valid URL was not provided."). Asking again cannot help.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message (in the site language).
	 */
	public static function isRefusal( string $code, string $message ): bool {
		return 'http_request_not_executed' === $code || self::isAddressRefusal( $code, $message );
	}

	/**
	 * Whether the safe API's address check failed (a local, internal or
	 * malformed address, also as a redirect target).
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message (in the site language).
	 */
	public static function isAddressRefusal( string $code, string $message ): bool {
		// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message, compared in the site language.
		$invalid = [ 'A valid URL was not provided.', __( 'A valid URL was not provided.' ) ];

		return 'http_request_failed' === $code && in_array( $message, $invalid, true );
	}

	/**
	 * One header of a WordPress response; the last one when it was sent twice.
	 *
	 * @param array<string, mixed> $response Response.
	 * @param string               $name     Header name.
	 */
	public static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$value = (string) end( $value );
		}

		return trim( (string) $value );
	}

	/**
	 * Content-Length, when the server sent a plain number.
	 *
	 * @param array<string, mixed> $response Response.
	 */
	public static function length( array $response ): ?int {
		$value = self::header( $response, 'content-length' );

		return 1 === preg_match( '/^\d+$/', $value ) ? (int) $value : null;
	}

	/**
	 * File size of a `bytes 0-<end>/<total>` answer (null for `*`).
	 *
	 * @param string $contentRange Content-Range header.
	 * @throws DownloadException When the part does not start at the beginning of the file.
	 */
	public static function totalFromStart( string $contentRange ): ?int {
		if ( 1 !== preg_match( '#^bytes 0-\d+/(\d+|\*)$#i', $contentRange, $m ) ) {
			throw new DownloadException( 'The server returned a part that does not start at the beginning of the file.' );
		}

		return '*' === $m[1] ? null : (int) $m[1];
	}

	/**
	 * Last byte of a `bytes <from>-<end>/<total>` answer when it lies inside the
	 * request and, with a known $total, belongs to a file of that size.
	 *
	 * @param string   $contentRange Content-Range header.
	 * @param int      $from         First requested byte.
	 * @param int      $to           Last requested byte.
	 * @param int|null $total        Expected file size.
	 */
	public static function rangeEnd( string $contentRange, int $from, int $to, ?int $total ): ?int {
		if ( 1 !== preg_match( '#^bytes (\d+)-(\d+)/(\d+|\*)$#i', $contentRange, $m ) ) {
			return null;
		}
		$end = (int) $m[2];
		if ( (int) $m[1] !== $from || $end < $from || $end > $to ) {
			return null;
		}
		if ( '*' !== $m[3] && ( $end >= (int) $m[3] || ( null !== $total && (int) $m[3] !== $total ) ) ) {
			return null;
		}

		return $end;
	}

	/**
	 * More bytes than expected is a broken server; fewer is a cut transfer.
	 *
	 * @param int $bytes    Bytes received.
	 * @param int $expected Bytes expected.
	 * @throws DownloadException When they differ (passing when bytes are missing).
	 */
	public static function checkSize( int $bytes, int $expected ): void {
		if ( $bytes > $expected ) {
			throw new DownloadException( 'The server sent more data than it announced.' );
		}
		if ( $bytes < $expected ) {
			throw DownloadException::passing( 'The transfer was cut short.' );
		}
	}

	/**
	 * Refuses a status outside $accepted.
	 *
	 * @param int             $status   HTTP status.
	 * @param array<int, int> $accepted Expected statuses.
	 * @throws DownloadException For any other status (passing for a busy server).
	 */
	public static function assertStatus( int $status, array $accepted ): void {
		if ( ! in_array( $status, $accepted, true ) ) {
			$error = self::statusError( $status );
			throw $error;
		}
	}

	/**
	 * The failure for an unexpected status; a busy server is a passing failure.
	 *
	 * @param int $status HTTP status.
	 */
	public static function statusError( int $status ): DownloadException {
		$message = sprintf( 'The server answered with HTTP %d.', $status );

		return in_array( $status, self::BUSY_STATUSES, true ) ? DownloadException::passing( $message ) : new DownloadException( $message );
	}
}
