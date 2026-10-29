<?php
/**
 * Plugin Name: LWM E2E relay probe (test only)
 *
 * Dev only: copied into site a's mu-plugins by RelayE2ETest, with
 * wp-content/lwm-e2e-relay.json ({"base", "ca", "httpHosts"}) beside it.
 * - Relay::useForTests() points the LW sign-in service at the fake relay and lets it
 *   take this site's http callback address (the relay's RELAY_ALLOW_HTTP_HOSTS).
 * - lwm-relay.invalid, api.dropboxapi.com and content.dropboxapi.com resolve to
 *   127.0.0.1 only through the plugin's address check (AddressGuard::useResolver), where
 *   the TLS front listens on 443: a request arrives only through the plugin's
 *   CURLOPT_RESOLVE pin.
 * - Requests to those three hosts verify TLS against the run's own CA only
 *   (sslcertificates); nothing turns verification off.
 * - Every mail is written to wp-content/lwm-e2e-relay-mails.jsonl instead of being sent,
 *   so a test can show that no reconnect mail went out.
 *
 * @package LightweightPlugins\Migration
 */

const LWM_E2E_RELAY_HOSTS = [ 'lwm-relay.invalid', 'api.dropboxapi.com', 'content.dropboxapi.com' ];

$lwm_relay_config = is_file( WP_CONTENT_DIR . '/lwm-e2e-relay.json' ) ? json_decode( (string) file_get_contents( WP_CONTENT_DIR . '/lwm-e2e-relay.json' ), true ) : null;

if ( is_array( $lwm_relay_config ) ) {
	add_action(
		'plugins_loaded',
		static function () use ( $lwm_relay_config ) {
			if ( ! class_exists( '\\LightweightPlugins\\Migration\\Storage\\Destinations\\OAuth\\Relay' ) ) {
				return;
			}
			\LightweightPlugins\Migration\Storage\Destinations\OAuth\Relay::useForTests( (string) $lwm_relay_config['base'], (array) $lwm_relay_config['httpHosts'] );
			\LightweightPlugins\Migration\Storage\Destinations\AddressGuard::useResolver(
				static function ( string $host ): array {
					if ( in_array( $host, LWM_E2E_RELAY_HOSTS, true ) ) {
						return [ '127.0.0.1' ];
					}
					$found = gethostbynamel( $host );

					return is_array( $found ) ? $found : [];
				}
			);
		},
		0
	);
	add_filter(
		'http_request_args',
		static function ( array $args, string $url ) use ( $lwm_relay_config ): array {
			if ( in_array( (string) parse_url( $url, PHP_URL_HOST ), LWM_E2E_RELAY_HOSTS, true ) ) {
				$args['sslcertificates'] = (string) $lwm_relay_config['ca'];
			}

			return $args;
		},
		10,
		2
	);
	add_filter(
		'pre_wp_mail',
		static function ( $short, array $atts ) {
			file_put_contents( WP_CONTENT_DIR . '/lwm-e2e-relay-mails.jsonl', wp_json_encode( [ 'to' => $atts['to'], 'subject' => $atts['subject'] ] ) . "\n", FILE_APPEND );

			return true;
		},
		10,
		2
	);
}
