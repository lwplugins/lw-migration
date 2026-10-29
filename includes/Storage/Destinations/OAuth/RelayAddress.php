<?php
/**
 * Whether the LW sign-in service accepts this site's callback address.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Spec auth-lwplugins-com §2.5, checked before a connect issues any state
 * and in the schema and view blocks, so the browser is never sent to the
 * relay's error page: https (http only for a test relay's hosts,
 * Relay::httpHosts()); no user name, password or fragment; port absent or
 * 443; a DNS name (lower-cased first) of at most 253 characters with at least
 * two labels, never an IP literal or a numeric last label; the plugin's callback route only — a path ending in
 * /wp-json/lw-migration/v1/oauth/callback without a query, or a path ending
 * in / or /index.php with exactly the query
 * rest_route=/lw-migration/v1/oauth/callback; at most 2048 characters, no
 * whitespace or control character, and no `%` anywhere (the path's
 * characters exclude it and the query must match exactly, so no
 * percent-encoded form gets through). firstBroken() calls no WordPress
 * function (unit tested); check() adds our text, which never repeats the
 * address.
 */
final class RelayAddress {

	public const HTTPS = 'https';

	public const IP = 'ip';

	public const ROUTE = 'route';

	public const ADDRESS = 'address';

	public const MAX_LENGTH = 2048;

	private const PATH = '/wp-json/lw-migration/v1/oauth/callback';

	private const QUERY = 'rest_route=/lw-migration/v1/oauth/callback';

	private const HOST = '/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/**
	 * @param string $uri This site's callback address.
	 * @return array{code: string, text: string}|null Null when the relay accepts it.
	 */
	public static function check( string $uri ): ?array {
		$code = self::firstBroken( $uri, Relay::httpHosts() );

		return null === $code ? null : [
			'code' => $code,
			'text' => self::text( $code ),
		];
	}

	/**
	 * The address the relay will use: scheme and host lower-cased, ":443" dropped.
	 *
	 * @param string             $uri       Callback address.
	 * @param array<int, string> $httpHosts Hosts allowed on plain http (a test relay's).
	 * @return string|null Null when the relay refuses it.
	 */
	public static function normalized( string $uri, array $httpHosts = [] ): ?string {
		return self::inspect( $uri, $httpHosts )[1];
	}

	/**
	 * @param string             $uri       Callback address.
	 * @param array<int, string> $httpHosts Hosts allowed on plain http (a test relay's).
	 * @return string|null The first rule it breaks (a class constant), null when acceptable.
	 */
	public static function firstBroken( string $uri, array $httpHosts = [] ): ?string {
		return self::inspect( $uri, $httpHosts )[0];
	}

	/**
	 * Mirrors the relay's returnurl.Validate. Pure: no WordPress function.
	 *
	 * @param string             $uri       Callback address.
	 * @param array<int, string> $httpHosts Hosts allowed on plain http.
	 * @return array{0: string|null, 1: string|null} Broken rule (null: none) and the normalised address.
	 */
	private static function inspect( string $uri, array $httpHosts ): array {
		$fail = static function ( string $code ): array {
			return [ $code, null ];
		};
		if ( '' === $uri || strlen( $uri ) > self::MAX_LENGTH || 1 === preg_match( '/[^\x21-\x7E]|[\\\\#]/', $uri ) ) {
			return $fail( self::ADDRESS );
		}
		if ( 1 !== preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*)://([^/?]*)((?:/[^?]*)?)(?:(\?)(.*))?\z#', $uri, $m ) ) {
			return $fail( self::ADDRESS );
		}
		$scheme = strtolower( $m[1] );
		$path   = $m[3];
		$query  = isset( $m[4] ) ? $m[5] : null;
		$auth   = strtolower( $m[2] );
		if ( '' === $auth || str_contains( $auth, '@' ) || '' === $query ) {
			return $fail( self::ADDRESS );
		}
		$bracket = '[' === $auth[0];
		if ( 1 !== preg_match( $bracket ? '/^\[([^\]]*)\](?::([0-9]*))?\z/' : '/^([^:\[\]]*)(?::([0-9]*))?\z/', $auth, $h ) ) {
			return $fail( self::ADDRESS );
		}
		$host = $h[1];
		$port = $h[2] ?? null;
		if ( $bracket && in_array( $host, array_map( 'strtolower', $httpHosts ), true ) ) {
			return $fail( self::ADDRESS );
		}
		$test = in_array( $host, array_map( 'strtolower', $httpHosts ), true ) && in_array( $scheme, [ 'http', 'https' ], true );
		if ( $test ) {
			if ( '' === $port ) {
				return $fail( self::ADDRESS );
			}
			$hostPart = $host . ( null === $port ? '' : ':' . $port );
		} elseif ( 'https' !== $scheme ) {
			return $fail( self::HTTPS );
		} else {
			$broken = self::brokenHost( $host, $bracket, $port );
			if ( null !== $broken ) {
				return $fail( $broken );
			}
			$hostPart = $host;
		}
		if ( ! self::isCallback( $path, $query ) ) {
			return $fail( self::ROUTE );
		}

		return [ null, $scheme . '://' . $hostPart . $path . ( null === $query ? '' : '?' . $query ) ];
	}

	private static function brokenHost( string $host, bool $bracket, ?string $port ): ?string {
		if ( $bracket || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::IP;
		}
		$labels = explode( '.', $host );
		if ( ( null !== $port && '443' !== $port ) || 1 !== preg_match( self::HOST, $host ) || count( $labels ) < 2 || 1 === preg_match( '/^(0x[0-9a-f]*|[0-9]+)\z/', (string) end( $labels ) ) ) {
			return self::ADDRESS;
		}

		return null;
	}

	private static function isCallback( string $path, ?string $query ): bool {
		if ( 1 !== preg_match( '#^/[A-Za-z0-9._~$&+,/:;=@-]*\z#', $path ) || str_contains( $path, '//' ) || in_array( '.', explode( '/', $path ), true ) || in_array( '..', explode( '/', $path ), true ) ) {
			return false;
		}
		if ( null === $query ) {
			return str_ends_with( $path, self::PATH );
		}

		return self::QUERY === $query && ( str_ends_with( $path, '/' ) || str_ends_with( $path, '/index.php' ) );
	}

	private static function text( string $code ): string {
		switch ( $code ) {
			case self::HTTPS:
				return __( 'Connecting through the LW app needs this site on https. Switch the site\'s address to https, or use your own app.', 'lw-migration' );
			case self::IP:
				return __( 'Connecting through the LW app needs a domain name in this site\'s address, not a bare IP number.', 'lw-migration' );
			case self::ROUTE:
				return __( 'Connecting through the LW app needs the standard address of the WordPress REST API on this site (…/wp-json/ or ?rest_route=). Undo the change of that address, or use your own app.', 'lw-migration' );
			default:
				return __( 'This site\'s address cannot be used for connecting through the LW app (a port other than 443, a user name, a host that is not a full domain name, or a very long address). Use your own app instead.', 'lw-migration' );
		}
	}
}
