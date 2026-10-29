<?php
/**
 * Streams a request body from a file through WordPress' cURL transport.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D22 with preflight P-C1. wp_remote_request() takes a body only as a
 * string; the cURL handle is reachable in the `http_api_curl` hook just
 * before sending. The checked request path (GuardedHttp) owns that one-shot
 * hook; this class hands it the configure closure that gives cURL the open
 * file (CURLOPT_UPLOAD, CURLOPT_INFILE positioned at the part,
 * CURLOPT_INFILESIZE), so a part of any size is never held in memory. A
 * request the hook never saw (a transport other than cURL) went out with an
 * empty body and is refused there. The file is closed in every case.
 */
final class CurlUpload {

	/**
	 * @param FileSlice $slice   The body.
	 * @param \Closure  $request fn( \Closure $configure ): mixed — the guarded send.
	 * @return mixed What $request returned.
	 * @throws \LightweightPlugins\Migration\Storage\Cloud\CloudJobException When the backup cannot be read.
	 */
	public static function send( FileSlice $slice, \Closure $request ) {
		$in = $slice->open();
		try {
			return $request( self::configure( $in, $slice->length ) );
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- opened by FileSlice::open().
		}
	}

	/**
	 * @param resource $in     The file, positioned at the part.
	 * @param int      $length Bytes to send.
	 * @return \Closure fn( $handle ): void.
	 */
	public static function configure( $in, int $length ): \Closure {
		return static function ( $handle ) use ( $in, $length ): void {
			curl_setopt_array(
				$handle,
				[
					CURLOPT_UPLOAD     => true,
					CURLOPT_INFILE     => $in,
					CURLOPT_INFILESIZE => $length,
				]
			);
		};
	}
}
