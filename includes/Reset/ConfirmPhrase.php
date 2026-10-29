<?php
/**
 * The site address typed to confirm a database or full reset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-free: the lower-case host (and port) and path of the home
 * address, without the scheme and the trailing slash. An empty result means
 * the site has no address that can be typed, so Preflight refuses the
 * database and full resets (an empty phrase would confirm nothing).
 */
final class ConfirmPhrase {

	/**
	 * @param string $homeUrl The home address.
	 */
	public static function of( string $homeUrl ): string {
		$home = trim( $homeUrl );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress-free class; a home address always carries its scheme.
		$parts = parse_url( $home );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return strtolower( rtrim( preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $home ) ?? '', '/' ) );
		}
		$host = strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

		return $host . rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}
}
