<?php
/**
 * Sends a request to an address someone else may have supplied: a checked, pinned public address.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #2, the one send path of the connections (Link\Client) and of the
 * URL import (RangeDownloader). Before every request the host is resolved
 * once and every address is checked with AddressGuard::webKind(): a refused
 * range is never reached; loopback and private ranges only for a host
 * WordPress allows (this site's own host, or the core filter
 * http_request_host_is_external, the seam local and test setups already
 * use). GuardedHttp then sends to exactly the checked address (CURLOPT_RESOLVE,
 * cURL forced, failing closed on any other transport) through
 * wp_safe_remote_request, so core's own checks (ports, blocked hosts) still
 * apply. Redirects are never left to the transport: send() follows none,
 * follow() follows at most a given number, each hop checked again.
 * Failures come back as a WP_Error the callers already classify: a refused
 * address as core's own refusal, a name that does not resolve as cURL's
 * lookup failure, anything that kept the request from being sent as
 * `http_request_not_executed`.
 */
final class SafeTransport {

	/** Answers whose Location follow() goes to. */
	public const REDIRECTS = [ 301, 302, 303, 307, 308 ];

	/**
	 * The request itself: fn( string $url, array $args ): array|\WP_Error.
	 *
	 * @var \Closure
	 */
	private \Closure $send;

	/**
	 * Whether internal addresses may be used: fn( string $host, string $url ): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $internal;

	/**
	 * @param \Closure|null           $send     The request (default wp_safe_remote_request).
	 * @param \Closure|null           $internal fn( string $host, string $url ): bool (default: as WordPress decides).
	 * @param array<string, \Closure> $probes   GuardedHttp's environment checks (tests).
	 */
	public function __construct( ?\Closure $send = null, ?\Closure $internal = null, private array $probes = [] ) {
		$this->send     = $send ?? static fn( string $url, #[\SensitiveParameter] array $args ) => wp_safe_remote_request( $url, $args );
		$this->internal = $internal ?? static fn( string $host, string $url ): bool => self::hostAllowed( $host, $url );
	}

	/**
	 * One request to a checked, pinned address; a redirect is answered, never followed.
	 *
	 * @param string               $url  http or https URL.
	 * @param array<string, mixed> $args Transport arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function send( string $url, #[\SensitiveParameter] array $args ) {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the same parse as the address check below.
		$host  = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		$bare  = trim( $host, '[]' );
		if ( '' === $bare || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return self::refusal();
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$ips    = ( new AddressGuard() )->addresses( $bare );
		if ( [] === $ips ) { // Nothing to pin: the transport is never asked to look the name up itself.
			return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $bare );
		}
		$internal = in_array( AddressGuard::WEB_INTERNAL, array_map( [ AddressGuard::class, 'webKind' ], $ips ), true );
		if ( ! AddressGuard::webAllowed( $ips, $internal && (bool) ( $this->internal )( $bare, $url ) ) ) {
			return self::refusal();
		}
		$authority           = $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$raw                 = null;
		$send                = $this->send;
		$capture             = static function ( string $target, #[\SensitiveParameter] array $options ) use ( $send, &$raw ) {
			$raw = $send( $target, $options );

			return $raw;
		};
		$args['redirection'] = 0;
		// The checked addresses are handed over as they are: GuardedHttp pins the first one without a second lookup.
		$http = new GuardedHttp( $scheme . '://' . $authority, $authority, $scheme, true, $capture, new AddressGuard( static fn(): array => $ips ), $this->probes );
		try {
			return $http->send( $url, $args );
		} catch ( CloudFailure $e ) {
			return is_wp_error( $raw ) ? $raw : new \WP_Error( 'http_request_not_executed', $e->getMessage() );
		}
	}

	/**
	 * Like send(), following at most $hops redirects by hand: every hop is checked
	 * and pinned like the first request. A redirect to anything but http or
	 * https, or one more than $hops allows, is answered as it is.
	 *
	 * @param string               $url  http or https URL.
	 * @param array<string, mixed> $args Transport arguments (kept for every hop).
	 * @param int                  $hops Most redirects followed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function follow( string $url, #[\SensitiveParameter] array $args, int $hops ) {
		for ( $hop = 0; ; ++$hop ) {
			$response = $this->send( $url, $args );
			$next     = $hop < $hops ? self::location( $response, $url ) : null;
			if ( null === $next ) {
				return $response;
			}
			$url = $next;
		}
	}

	/**
	 * Where a redirect answer leads, as an absolute http or https URL.
	 *
	 * @param array<string, mixed>|\WP_Error $response Answer.
	 * @param string                         $base     The URL that answered.
	 */
	public static function location( $response, string $base ): ?string {
		if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), self::REDIRECTS, true ) ) {
			return null;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( is_array( $location ) ) {
			$location = end( $location ); // Like core: the last one.
		}
		$target = self::absolute( trim( (string) $location ), $base );

		return null !== $target && 1 === preg_match( '#^https?://[^/?\#]#i', $target ) ? $target : null;
	}

	/**
	 * @param string $location Location header.
	 * @param string $base     The URL that answered.
	 */
	private static function absolute( string $location, string $base ): ?string {
		$location = (string) preg_replace( '/#.*\z/s', '', $location );
		if ( '' === $location ) {
			return null;
		}
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			return $location;
		}
		if ( 1 !== preg_match( '#^([a-z][a-z0-9+.-]*:)//([^/?\#]*)([^?\#]*)#i', $base, $m ) ) {
			return null;
		}
		if ( str_starts_with( $location, '//' ) ) {
			return $m[1] . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $m[1] . '//' . $m[2] . $location;
		}
		$path = '' === $m[3] ? '/' : $m[3];
		if ( str_starts_with( $location, '?' ) ) {
			return $m[1] . '//' . $m[2] . $path . $location;
		}

		return $m[1] . '//' . $m[2] . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}

	/**
	 * As core decides for its own address check: this site's own host, or a
	 * host the http_request_host_is_external filter allows.
	 *
	 * @param string $host Host without brackets.
	 * @param string $url  Requested URL.
	 */
	private static function hostAllowed( string $host, string $url ): bool {
		$home = strtolower( (string) parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the parse core's check uses.
		if ( '' !== $home && strtolower( $host ) === $home ) {
			return true;
		}

		/** This filter is documented in wp-includes/http.php */
		return (bool) apply_filters( 'http_request_host_is_external', false, $host, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
	}

	/** Core's own refusal of an address, so every caller classifies it as one. */
	private static function refusal(): \WP_Error {
		// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message, as WP_Http gives it.
		return new \WP_Error( 'http_request_failed', __( 'A valid URL was not provided.' ) );
	}
}
