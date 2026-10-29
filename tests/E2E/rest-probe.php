<?php
/**
 * Plugin Name: LWM E2E REST probe (test only)
 *
 * Dev only: copied into site b's mu-plugins by RestE2ETest. Reports the peak memory
 * of each REST request in a header; while the pause file names a job's swap marker,
 * shortens the tick and pauses once right after the swap.
 *
 * @package LightweightPlugins\Migration
 */

add_filter(
	'rest_post_dispatch',
	static function ( $response ) {
		if ( $response instanceof WP_HTTP_Response ) {
			$response->header( 'X-LWM-Peak-Memory', (string) memory_get_peak_usage( true ) );
		}
		return $response;
	}
);
add_filter(
	'lw_migration_web_tick_seconds',
	static fn( $seconds ) => is_file( WP_CONTENT_DIR . '/lwm-e2e-pause-after-swap' ) ? 1.0 : $seconds
);
add_filter(
	'query',
	static function ( $query ) {
		static $paused = false;
		$pause = WP_CONTENT_DIR . '/lwm-e2e-pause-after-swap';
		if ( ! $paused && is_file( $pause ) ) {
			$marker = trim( (string) file_get_contents( $pause ) );
			if ( '' !== $marker && is_file( $marker ) ) {
				$paused = true;
				unlink( $pause );
				usleep( 1500000 );
			}
		}
		return $query;
	}
);
