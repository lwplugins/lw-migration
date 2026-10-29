<?php
/**
 * Proposes a site's address on the target network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Multisite;

defined( 'ABSPATH' ) || exit;

/**
 * The established rule (appendix R6, decision 10): a single site is named by
 * the first label of its host (without www.), the last path segment winning;
 * a subsite by the label left of the old main host, then the last segment of
 * its path relative to the old main path; a domain outside the old main host
 * (not equal to it and not ending in "." + it, ruling R25) is kept. Scheme and
 * port always come from the target; an archive host's own port is dropped.
 */
final class UrlProposal {

	/**
	 * @param SiteEntry     $old         Site of the archive.
	 * @param string        $oldMainHome Home URL of the archive's main site (package.json).
	 * @param TargetNetwork $to          Target network.
	 * @return string Address without a trailing slash.
	 */
	public static function for( SiteEntry $old, string $oldMainHome, TargetNetwork $to ): string {
		$name = null === $old->blogId ? self::singleName( $old ) : self::subsiteName( $old, $oldMainHome );
		if ( null === $name ) {
			return self::url( $to, self::hostOnly( $old->domain ), '/' . trim( $old->path, '/' ) ); // A custom domain stays.
		}
		if ( '' === $name ) {
			return $to->mainUrl();
		}
		$host = self::withoutWww( $to->domain );

		return $to->subdomain
			? self::url( $to, $name . '.' . $host, rtrim( $to->path, '/' ) )
			: self::url( $to, $to->domain, rtrim( $to->path, '/' ) . '/' . $name );
	}

	private static function singleName( SiteEntry $old ): string {
		$home = '' !== $old->homeUrl ? $old->homeUrl : 'http://' . $old->domain . $old->path;
		$host = self::withoutWww( strtolower( (string) parse_url( $home, PHP_URL_HOST ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.
		$name = explode( '.', $host )[0];
		$path = trim( (string) parse_url( $home, PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.

		return strtolower( '' !== $path ? basename( $path ) : $name );
	}

	/**
	 * @param SiteEntry $old         Site of the archive.
	 * @param string    $oldMainHome Home URL of the archive's main site.
	 * @return string|null Null for a custom domain (outside the old main host), '' for the old main site.
	 */
	private static function subsiteName( SiteEntry $old, string $oldMainHome ): ?string {
		$mainHost = self::withoutWww( strtolower( (string) parse_url( $oldMainHome, PHP_URL_HOST ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.
		$mainPath = trim( (string) parse_url( $oldMainHome, PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing of archive data.
		$domain   = self::withoutWww( self::hostOnly( $old->domain ) );
		if ( '' === $mainHost ) {
			return null;
		}
		if ( $domain === $mainHost ) {
			$name = '';
		} elseif ( str_ends_with( $domain, '.' . $mainHost ) ) {
			$name = trim( substr( $domain, 0, -strlen( $mainHost ) ), '.' );
		} else {
			return null;
		}
		$path = trim( $old->path, '/' );
		if ( '' !== $mainPath && str_starts_with( $path . '/', $mainPath . '/' ) ) {
			$path = trim( substr( $path, strlen( $mainPath ) ), '/' );
		}
		if ( '' !== $path ) {
			$name = basename( $path );
		}

		return strtolower( $name );
	}

	private static function url( TargetNetwork $to, string $host, string $path ): string {
		return $to->scheme . '://' . $to->blogDomain( $host ) . ( '/' === $path ? '' : $path );
	}

	/**
	 * A stored domain without its port (wp_blogs.domain and package domains may carry one), lower case.
	 *
	 * @param string $domain Domain, maybe with :port.
	 */
	private static function hostOnly( string $domain ): string {
		return strtolower( (string) preg_replace( '/:\d+\z/', '', $domain ) );
	}

	private static function withoutWww( string $host ): string {
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
