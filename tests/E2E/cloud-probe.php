<?php
/**
 * Plugin Name: LWM E2E cloud probe (test only)
 *
 * Dev only: copied into the mu-plugins of sites a and b by CloudE2ETest and
 * the CloudProtocol tests.
 *
 * - The names lwm-minio.invalid, lwm-sftp.invalid, lwm-sftp2.invalid,
 *   lwm-ftp.invalid and lwm-nextcloud.invalid resolve to 127.0.0.1 only
 *   through the plugin's address check (AddressGuard::useResolver); the
 *   system resolver does not know them. A request reaches MinIO or Nextcloud
 *   by that name only when the plugin pins the checked address with
 *   CURLOPT_RESOLVE, and an FTP or SFTP server only when the plugin connects
 *   to the checked address instead of the name.
 * - Every request of the site uses the fsockopen transport by default; only
 *   the plugin's per-request switch to cURL (a later hook on the same action)
 *   makes cURL send a storage request.
 * - While wp-content/lwm-e2e-kill-after-part holds a part number, the request
 *   that receives the answer to that S3 part ends PHP right there (once: the
 *   file is removed first) — the part arrived, the job was not saved, like a
 *   request killed by the host's time limit.
 * - Every part a destination announces (lw_migration_cloud_part) is logged to
 *   wp-content/lwm-e2e-parts.log as "<destination id> <offset>".
 * - While wp-content/lwm-e2e-kill-after-cloud-part holds a destination id,
 *   the request that sent a part to that destination ends PHP right after it
 *   (once), whatever the destination type.
 * - While wp-content/lwm-e2e-kill-after-move exists, the request that got a
 *   WebDAV MOVE answered with 201 ends PHP right there (once): the file is in
 *   place, the job does not know it.
 * - wp-content/lwm-e2e-single-put-bytes, when present, holds the limit of a
 *   one-request WebDAV upload outside WP-CLI (lw_migration_webdav_single_put_bytes).
 *
 * @package LightweightPlugins\Migration
 */

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\LightweightPlugins\\Migration\\Storage\\Destinations\\AddressGuard' ) ) {
			\LightweightPlugins\Migration\Storage\Destinations\AddressGuard::useResolver(
				static function ( string $host ): array {
					if ( in_array( $host, [ 'lwm-minio.invalid', 'lwm-sftp.invalid', 'lwm-sftp2.invalid', 'lwm-ftp.invalid', 'lwm-nextcloud.invalid' ], true ) ) {
						return [ '127.0.0.1' ];
					}
					$found = gethostbynamel( $host );

					return is_array( $found ) ? $found : [];
				}
			);
		}
	},
	0
);

add_action(
	'requests-requests.before_request',
	static function ( &$url, &$headers, &$data, &$type, &$options ) {
		$options['transport'] = \WpOrg\Requests\Transport\Fsockopen::class;
	},
	5,
	5
);

add_action(
	'http_api_debug',
	static function ( $response, $context, $transport, $args, $url ) {
		$flag = WP_CONTENT_DIR . '/lwm-e2e-kill-after-part';
		if ( ! is_file( $flag ) ) {
			return;
		}
		$part = trim( (string) file_get_contents( $flag ) );
		if ( '' !== $part && str_contains( (string) $url, 'partNumber=' . $part . '&' ) ) {
			unlink( $flag );
			exit;
		}
	},
	10,
	5
);

add_action(
	'http_api_debug',
	static function ( $response, $context, $transport, $args, $url ) {
		$flag = WP_CONTENT_DIR . '/lwm-e2e-kill-after-move';
		if ( is_file( $flag ) && 'MOVE' === ( $args['method'] ?? '' ) && 201 === wp_remote_retrieve_response_code( $response ) ) {
			unlink( $flag );
			exit; // The MOVE arrived, the job was not saved.
		}
	},
	10,
	5
);

add_action(
	'lw_migration_cloud_part',
	static function ( $id, $offset ) {
		file_put_contents( WP_CONTENT_DIR . '/lwm-e2e-parts.log', $id . ' ' . $offset . "\n", FILE_APPEND );
		$flag = WP_CONTENT_DIR . '/lwm-e2e-kill-after-cloud-part';
		if ( is_file( $flag ) && trim( (string) file_get_contents( $flag ) ) === (string) $id ) {
			unlink( $flag );
			exit; // The part arrived, the job was not saved: like a request killed by a time limit.
		}
	},
	10,
	2
);

add_filter(
	'lw_migration_webdav_single_put_bytes',
	static function ( $bytes ) {
		$flag = WP_CONTENT_DIR . '/lwm-e2e-single-put-bytes';
		return is_file( $flag ) ? (int) trim( (string) file_get_contents( $flag ) ) : $bytes;
	}
);
