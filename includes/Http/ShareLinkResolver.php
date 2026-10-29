<?php
/**
 * Turns share links of common file hosts into direct download links.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Local rewriting only; no request goes through a third-party service.
 *
 * Only http(s) URLs whose host is exactly a provider host are rewritten;
 * everything else is returned unchanged.
 */
final class ShareLinkResolver {

	public static function resolve( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $url;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return $url;
		}
		$host = strtolower( $parts['host'] );
		if ( 'dropbox.com' === $host || 'www.dropbox.com' === $host ) {
			return self::withQuery( $url, array( 'dl' => '1' ) );
		}
		if ( 'drive.google.com' === $host ) {
			$id = self::driveId( $url );
			return null === $id ? $url : 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $id );
		}
		if ( '1drv.ms' === $host || 'onedrive.live.com' === $host ) {
			return 'https://api.onedrive.com/v1.0/shares/u!' . rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' ) . '/root/content';
		}

		return $url;
	}

	public static function driveId( string $url ): ?string {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( 1 === preg_match( '#/file/d/([A-Za-z0-9_-]+)#', $path, $m ) ) {
			return $m[1];
		}
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$id = $query['id'] ?? null;

		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ? $id : null;
	}

	/**
	 * Large Google Drive files answer with a page holding a download form.
	 *
	 * @param string $html Page.
	 * @return string|null Download URL, https only.
	 */
	public static function confirmUrl( string $html ): ?string {
		if ( 1 !== preg_match( '/<form[^>]*\bid="download-form"[^>]*\baction="([^"]+)"/i', $html, $form ) ) {
			return null;
		}
		$action = html_entity_decode( $form[1], ENT_QUOTES | ENT_HTML5 );
		if ( ! str_starts_with( $action, 'https://' ) ) {
			return null;
		}
		preg_match_all( '/<input[^>]*\btype="hidden"[^>]*\bname="([^"]*)"[^>]*\bvalue="([^"]*)"/i', $html, $inputs, PREG_SET_ORDER );
		$query = array();
		foreach ( $inputs as $input ) {
			$query[ html_entity_decode( $input[1], ENT_QUOTES | ENT_HTML5 ) ] = html_entity_decode( $input[2], ENT_QUOTES | ENT_HTML5 );
		}

		return array() === $query ? $action : $action . ( str_contains( $action, '?' ) ? '&' : '?' ) . http_build_query( $query );
	}

	/**
	 * Whether a download confirmation target may be followed: an https address
	 * without user info whose host is google.com or a sub-host of google.com /
	 * googleusercontent.com (no trailing dot, letters, digits, dots and hyphens only).
	 *
	 * @param string $url Form target.
	 */
	public static function isGoogleHost( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z0-9.-]+\z/', $host ) ) {
			return false;
		}

		return 'google.com' === $host || str_ends_with( $host, '.google.com' ) || str_ends_with( $host, '.googleusercontent.com' );
	}

	/**
	 * @param string                $url URL.
	 * @param array<string, string> $set Query values to set.
	 * @return string
	 */
	private static function withQuery( string $url, array $set ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return $url;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$query = array_merge( $query, $set );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $parts['scheme'] . '://' . $parts['host'] . $port . ( $parts['path'] ?? '' ) . '?' . http_build_query( $query );
	}
}
