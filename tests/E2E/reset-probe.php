<?php
/**
 * Plugin Name: LWM E2E reset probe (test only)
 *
 * Dev only. While wp-content/lwm-e2e-die-after-rename exists, the process exits (code 3) on the
 * first $wpdb query after a reset wrote its swap marker (that is: right after its RENAME), once;
 * markers of earlier resets (present when this process started) do not count. While
 * wp-content/lwm-e2e-offline exists, every request to *.wordpress.org fails.
 *
 * @package LightweightPlugins\Migration
 */

$lwm_e2e_markers = static fn(): array => (array) glob( WP_CONTENT_DIR . '/lw-migration-*/jobs/*/swap-started' );
$lwm_e2e_known   = is_file( WP_CONTENT_DIR . '/lwm-e2e-die-after-rename' ) ? $lwm_e2e_markers() : [];

add_filter(
	'query',
	static function ( $query ) use ( $lwm_e2e_markers, $lwm_e2e_known ) {
		// The reset's RENAME runs on the raw mysqli link (WpConnection), so this filter never sees it;
		// it sees the first $wpdb query after it: the lock refresh right after the swap. Between the
		// swap marker and the RENAME the step makes no $wpdb query.
		$flag = WP_CONTENT_DIR . '/lwm-e2e-die-after-rename';
		if ( is_file( $flag ) && [] !== array_diff( $lwm_e2e_markers(), $lwm_e2e_known ) ) {
			unlink( $flag );
			exit( 3 );
		}
		return $query;
	}
);
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( is_file( WP_CONTENT_DIR . '/lwm-e2e-offline' ) && 1 === preg_match( '#^https?://([a-z0-9.-]+\.)?wordpress\.org/#i', (string) $url ) ) {
			return new WP_Error( 'http_request_failed', 'offline (test)' );
		}
		return $pre;
	},
	10,
	3
);
