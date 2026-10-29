<?php
/**
 * Streams one backup as the answer of a download route.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Downloader;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the two download routes (the logged-in link of Routes and the
 * transfer link of ShareRoutes), after their own checks: drops every output
 * buffer, sends the headers and the bytes through Downloader (Range, If-Range,
 * no-store, nosniff, no-referrer, noindex) and ends the request; a HEAD
 * request (core serves it with the GET route) gets the headers only. The file
 * is opened before any header, so an unreadable one is thrown to the caller
 * with nothing sent, and the caller answers it with its own error.
 */
final class DownloadStream {

	/**
	 * @param Backup           $backup  The backup the route's check opened.
	 * @param \WP_REST_Request $request Request (Range and If-Range headers).
	 * @param \Closure|null    $onStart fn( int $status, int $first, int $last ): void, once the file is open, before the first header.
	 * @throws \Throwable When the file cannot be read or the output cannot be opened.
	 */
	public static function send( Backup $backup, \WP_REST_Request $request, ?\Closure $onStart = null ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$header = static function ( string $line, int $status ): void {
			if ( $status > 0 ) {
				status_header( $status );
			}
			header( $line );
		};
		( new Downloader( $header ) )->send( $backup, self::header( $request, 'range' ), self::header( $request, 'if_range' ), 'HEAD' === $request->get_method(), $onStart );
		exit;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Header name (core's normalized form).
	 */
	public static function header( \WP_REST_Request $request, string $name ): ?string {
		$value = $request->get_header( $name );

		return is_string( $value ) ? $value : null;
	}
}
