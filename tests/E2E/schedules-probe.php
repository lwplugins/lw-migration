<?php
/**
 * Plugin Name: LWM E2E schedules probe (test only)
 *
 * End-to-end tests only (SchedulesE2ETest copies it into site a's mu-plugins):
 * mails are written to wp-content/lwm-e2e-mails.jsonl instead of being sent;
 * every loopback request the route accepted adds a line to
 * wp-content/lwm-e2e-loopbacks.log; the loopback address, the cron budget and
 * the tick length can be set through options. With the option
 * lwm_e2e_cron_pause = 1, a wp-cron.php request pauses once inside its first
 * tick, after the tick's deadline was set: that tick passes its deadline and
 * the cron budget at its first check, so the WP-Cron request never finishes a
 * backup itself and the rest must come through loopback slices, however fast
 * the machine is (each pause is noted in wp-content/lwm-e2e-paused.log).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) {
		$mail = [
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		];
		file_put_contents( WP_CONTENT_DIR . '/lwm-e2e-mails.jsonl', wp_json_encode( $mail ) . "\n", FILE_APPEND );
		return true;
	},
	10,
	2
);

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && 202 === $response->get_status() && '/lw-migration/v1/schedules/continue' === $request->get_route() ) {
			file_put_contents( WP_CONTENT_DIR . '/lwm-e2e-loopbacks.log', time() . "\n", FILE_APPEND );
		}
		return $response;
	},
	10,
	3
);

$lwm_e2e_option = static function ( string $name, $fallback ) {
	$set = get_option( $name, '' );
	return '' !== $set && false !== $set ? $set : $fallback;
};
add_filter( 'lw_migration_loopback_url', static fn( $url ) => (string) $lwm_e2e_option( 'lwm_e2e_loopback_url', $url ) );
add_filter( 'lw_migration_cron_seconds', static fn( $seconds ) => (float) $lwm_e2e_option( 'lwm_e2e_cron_seconds', $seconds ) );
add_filter( 'lw_migration_web_tick_seconds', static fn( $seconds ) => (float) $lwm_e2e_option( 'lwm_e2e_tick_seconds', $seconds ) );

add_filter(
	'query',
	static function ( $query ) {
		static $paused = false;
		// The job's first secret read (TransientSecretStore) comes right after Runner::tick() got its deadline.
		if ( ! $paused && wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && is_string( $query ) && str_contains( $query, '_transient_timeout_lw_migration_job_secret_' ) && '1' === (string) get_option( 'lwm_e2e_cron_pause', '' ) ) {
			$paused = true;
			file_put_contents( WP_CONTENT_DIR . '/lwm-e2e-paused.log', time() . "\n", FILE_APPEND );
			usleep( 1200000 );
		}
		return $query;
	}
);

// Core's spawn_cron() (on any request of this site while an event is due) takes the
// 60 s doing_cron lock, then requests http://site-a.test/wp-cron.php, which cannot
// arrive here; that lock would turn the test's own wp-cron.php request away.
add_filter(
	'cron_request',
	static function ( $request ) {
		delete_transient( 'doing_cron' );
		return $request;
	}
);
