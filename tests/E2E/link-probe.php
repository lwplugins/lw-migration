<?php
/**
 * E2E only: lets the two throwaway sites reach each other on 127.0.0.1
 * (the test web server; a pairing code may name that IP address), announces the address the server answers under
 * when the option lwm_e2e_link_address is set (an address, or addresses by
 * home URL), and keeps mails in the
 * option lwm_e2e_mails instead of sending them.
 *
 * @package LightweightPlugins\Migration
 */

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
add_filter( 'http_allowed_safe_ports', static fn( $ports ) => array_merge( (array) $ports, [ 8093 ] ) );
add_filter( 'lw_migration_allow_ip_link_hosts', '__return_true' ); // The codes name 127.0.0.1:8093.
add_filter(
	'lw_migration_link_address',
	static function ( $address ) {
		$set = get_option( 'lwm_e2e_link_address' );
		if ( is_array( $set ) ) { // Address by home URL: the same value works on both sites, also after a push copied A's options to B.
			$set = $set[ home_url() ] ?? '';
		}
		return is_string( $set ) && '' !== $set ? $set : $address;
	}
);
