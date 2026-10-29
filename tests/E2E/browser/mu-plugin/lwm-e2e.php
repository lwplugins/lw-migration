<?php
/**
 * Browser tests only (bin/test-ui.sh copies it into the throwaway sites'
 * mu-plugins): lets a test set the length of a browser tick through the
 * option `lwm_e2e_tick_seconds`, so a cancel can be pinned against a tick
 * that really holds the job for a long time; lets the two sites connect on
 * 127.0.0.1 and their UI ports (LWM_UI_PORT and the next one); keeps mails
 * in the option `lwm_e2e_mails` instead of sending them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_filter(
	'lw_migration_web_tick_seconds',
	static function ( $seconds ) {
		$set = (float) get_option( 'lwm_e2e_tick_seconds', 0 );
		return $set > 0 ? $set : $seconds;
	}
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$mails   = (array) get_option( 'lwm_e2e_mails', [] );
		$mails[] = [
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		];
		update_option( 'lwm_e2e_mails', $mails, false );
		return true;
	},
	10,
	2
);
add_filter( 'http_request_host_is_external', static fn( $external, $host ) => '127.0.0.1' === $host ? true : $external, 10, 2 );
add_filter( 'lw_migration_allow_ip_link_hosts', '__return_true' ); // The sites live on 127.0.0.1, so their pairing codes name it.
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) {
		$port = (int) getenv( 'LWM_UI_PORT' );
		$port = $port > 0 ? $port : 8894;
		return array_merge( (array) $ports, [ $port, $port + 1 ] );
	}
);
