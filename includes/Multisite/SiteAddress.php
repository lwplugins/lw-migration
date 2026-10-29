<?php
/**
 * Checks an address given for a restored site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * Decision 12: an http(s) address with the network's scheme and port; on a
 * subdirectory install the network host plus exactly one lower-case name that
 * WordPress does not reserve, on a subdomain install the network path with a
 * valid host (custom domains allowed). The main site's address is refused
 * (decision 13). Pure; the reason is translated only when refusing.
 */
final class SiteAddress {

	/** Core folders and routes a subdirectory site can never be named after, whatever the reserved list says. */
	private const ALWAYS_RESERVED = [ 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'files' ];

	/**
	 * @param string        $url Address given (or proposed).
	 * @param TargetNetwork $to  Target network.
	 * @return array{domain: string, path: string, url: string} domain as wp_blogs holds it (with the port), path with slashes on both ends, url without a trailing slash.
	 * @throws \InvalidArgumentException With the translated reason.
	 */
	public static function check( string $url, TargetNetwork $to ): array {
		[ $scheme, $host, $port, $path ] = self::parse( $url );
		if ( $scheme !== $to->scheme ) {
			/* translators: %s: scheme of the network's address (http or https) */
			throw new \InvalidArgumentException( sprintf( __( 'Use the network\'s scheme (%s).', 'lw-migration' ), $to->scheme ) );
		}
		if ( $port !== $to->port ) {
			throw new \InvalidArgumentException(
				null === $to->port
					? __( 'The network\'s addresses have no port: leave it out.', 'lw-migration' )
					/* translators: %d: port of the network's address */
					: sprintf( __( 'Use the network\'s port (%d).', 'lw-migration' ), $to->port )
			);
		}
		$to->subdomain ? self::checkSubdomain( $host, $path, $to ) : self::checkSubdirectory( $host, $path, $to );

		return [
			'domain' => $to->blogDomain( $host ),
			'path'   => $path,
			'url'    => $to->scheme . '://' . $to->blogDomain( $host ) . rtrim( $path, '/' ),
		];
	}

	/**
	 * @param string $url Address.
	 * @return array{0: string, 1: string, 2: int|null, 3: string} Scheme, host and path (lower case, slashes on both ends), port.
	 * @throws \InvalidArgumentException When it is not a plain http(s) address.
	 */
	private static function parse( string $url ): array {
		$parts  = 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ? false : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of a given address.
		$scheme = strtolower( (string) ( is_array( $parts ) ? ( $parts['scheme'] ?? '' ) : '' ) );
		$extra  = is_array( $parts ) ? array_intersect_key( $parts, array_flip( [ 'user', 'pass', 'query', 'fragment' ] ) ) : [];
		if ( ! is_array( $parts ) || ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === (string) ( $parts['host'] ?? '' ) || [] !== $extra ) {
			throw new \InvalidArgumentException( self::notAnAddress() );
		}
		$path = trim( strtolower( (string) ( $parts['path'] ?? '' ) ), '/' );

		return [ $scheme, strtolower( (string) $parts['host'] ), isset( $parts['port'] ) ? (int) $parts['port'] : null, '' === $path ? '/' : '/' . $path . '/' ];
	}

	/**
	 * @param string        $host Host, lower case.
	 * @param string        $path Path with slashes on both ends.
	 * @param TargetNetwork $to   Target network.
	 * @throws \InvalidArgumentException When the address breaks the network's rules.
	 */
	private static function checkSubdirectory( string $host, string $path, TargetNetwork $to ): void {
		if ( $host !== $to->domain || ! str_starts_with( $path, $to->path ) ) {
			/* translators: %s: the network's main address; "<name>" stands for the site's name */
			throw new \InvalidArgumentException( sprintf( __( 'On this network a site\'s address is %s/<name>.', 'lw-migration' ), $to->mainUrl() ) );
		}
		$name = trim( substr( $path, strlen( $to->path ) ), '/' );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( self::mainSiteRefusal() );
		}
		if ( 1 !== preg_match( '/^[a-z0-9-]+\z/', $name ) ) {
			throw new \InvalidArgumentException( __( 'A site\'s name may contain only letters, digits and hyphens, in one part.', 'lw-migration' ) );
		}
		if ( in_array( $name, array_merge( self::ALWAYS_RESERVED, array_map( 'strtolower', $to->reserved ) ), true ) ) {
			/* translators: %s: the site name WordPress reserves */
			throw new \InvalidArgumentException( sprintf( __( 'WordPress reserves the name "%s". Choose another one.', 'lw-migration' ), $name ) );
		}
	}

	/**
	 * @param string        $host Host, lower case.
	 * @param string        $path Path with slashes on both ends.
	 * @param TargetNetwork $to   Target network.
	 * @throws \InvalidArgumentException When the address breaks the network's rules.
	 */
	private static function checkSubdomain( string $host, string $path, TargetNetwork $to ): void {
		if ( $path !== $to->path ) {
			/* translators: %s: the network's path */
			throw new \InvalidArgumentException( sprintf( __( 'On this network a site\'s address has no path of its own: end it with %s.', 'lw-migration' ), $to->path ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+\z/', $host ) ) {
			throw new \InvalidArgumentException( __( 'This is not a valid host name.', 'lw-migration' ) );
		}
		if ( in_array( $host, [ $to->domain, 'www.' . $to->domain ], true ) ) {
			throw new \InvalidArgumentException( self::mainSiteRefusal() );
		}
	}

	private static function notAnAddress(): string {
		return __( 'Enter a full http or https address without spaces, a query or a password, such as https://example.com/shop.', 'lw-migration' );
	}

	/** Decision 13: why the main site is never the target of a subsite import. */
	public static function mainSiteRefusal(): string {
		return __( 'The main site cannot be replaced by a subsite import: choose another address.', 'lw-migration' );
	}
}
