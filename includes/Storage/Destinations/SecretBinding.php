<?php
/**
 * The server a destination's secrets are sealed for.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * A password, key or secret is sealed together with the server it was saved
 * for (SecretVault), and opened only for settings that name that server
 * (Destinations::open()). The server is the type's authority
 * (AuthorityBound::authority(), the values an edit compares to decide that
 * the server moved):
 * FTP host, port and security; SFTP host and port; WebDAV scheme, host and
 * port; S3 provider, endpoint and account. The user name, the folder, an S3
 * bucket or region stay free to change: the secret goes to the same server.
 * The OAuth types have none: their secrets only ever reach the provider the
 * type is built for.
 */
final class SecretBinding {

	/**
	 * @param DestinationType      $type     Type.
	 * @param array<string, mixed> $settings The profile's settings.
	 * @return string|null 64 hex digits; null for a type without an authority.
	 */
	public static function of( DestinationType $type, array $settings ): ?string {
		if ( ! $type instanceof AuthorityBound ) {
			return null;
		}
		$encoded = $type->key();
		foreach ( $type->authority( $settings ) as $part ) {
			$part     = (string) $part;
			$encoded .= "\0" . strlen( $part ) . ':' . $part; // Length-prefixed, so no two authorities encode alike.
		}

		return hash( 'sha256', $encoded );
	}
}
