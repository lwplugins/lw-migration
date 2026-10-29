<?php
/**
 * The one send path of every OAuth provider request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight P-C2 (spec 21.4, H1–H3): a URL is refused before anything is
 * sent unless it is https on port 443, without userinfo or fragment, to a
 * lower-case DNS name (no IP literal, no whitespace or control character).
 * The request then goes through GuardedHttp in the hosted-preset mode
 * (custom = false): the host is checked by AddressGuard and pinned, cURL is
 * forced and an answer cURL never saw is dropped; behind a WordPress proxy
 * it goes through that proxy unpinned — the API hosts are constants of our
 * code and a provider-given host came in a TLS-verified provider answer,
 * so neither is steerable by the admin. TLS is always checked and no
 * redirect is followed, whatever the caller passed. An Authorization header
 * goes only to a provider API host (O8); asking for one elsewhere (a padded
 * key counts), passing headers that are not an array (WordPress parses a
 * raw header string before sending, so a hidden Authorization line would
 * slip past the check), or a line break in a header key or value is a
 * programming error (LogicException), never sent. A transport error cURL
 * saw is classified by HttpErrors (P-I1). The arguments carry secrets and
 * are never logged.
 */
final class ProviderHttp {

	/** The only hosts a Bearer token is sent to (Google Drive, Dropbox, Microsoft Graph). */
	public const BEARER_HOSTS = [ 'www.googleapis.com', 'api.dropboxapi.com', 'content.dropboxapi.com', 'graph.microsoft.com' ];

	private const HOST = '/^(?=.{4,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/**
	 * @param \Closure|null           $send   Transport (default wp_remote_request).
	 * @param AddressGuard|null       $guard  Address check (default: real DNS, or the tests' resolver).
	 * @param array<string, \Closure> $probes Environment checks for tests (see GuardedHttp).
	 */
	public function __construct( private ?\Closure $send = null, private ?AddressGuard $guard = null, private array $probes = [] ) {
	}

	/**
	 * @param string               $url   Request URL.
	 * @param array<string, mixed> $args  Transport arguments (sslverify and redirection are forced).
	 * @param string               $label Provider name, for messages.
	 * @return array<string, mixed> The transport answer (any HTTP status).
	 * @throws CloudFailure On a refused URL or address, a request never sent or dropped, a transport error or a non-array answer.
	 * @throws \LogicException When the headers are not an array, or an Authorization header would go to another host than a provider API host.
	 */
	public function send( #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $args, string $label ): array {
		$host = self::host( $url, $label );
		if ( self::authorized( $args ) && ! in_array( $host, self::BEARER_HOSTS, true ) ) {
			throw new \LogicException( 'An Authorization header is sent only to a provider API host.' );
		}
		$args['sslverify']   = true;
		$args['redirection'] = 0;
		$response            = ( new GuardedHttp( $label, $host, 'https', false, $this->send, $this->guard, $this->probes ) )->send( $url, $args );
		if ( is_wp_error( $response ) ) {
			$failure = HttpFailures::transport( (string) $response->get_error_code(), (string) $response->get_error_message(), $label );
			throw $failure;
		}
		if ( ! is_array( $response ) ) {
			throw self::refused( $label );
		}

		return $response;
	}

	/**
	 * The host of a URL this class may send to.
	 *
	 * @param string $url   Request URL.
	 * @param string $label Provider name, for messages.
	 * @throws PermanentFailure INVALID for any other URL.
	 */
	public static function host( #[\SensitiveParameter] string $url, string $label ): string {
		$parts = 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) ? false : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- PHP 8 parses these addresses correctly; no WordPress function on this path.
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['port'] ?? 443, [ 443 ], true ) ) {
			throw self::refused( $label );
		}
		$host = (string) ( $parts['host'] ?? '' );
		if ( 1 !== preg_match( self::HOST, $host ) ) {
			throw self::refused( $label );
		}

		return $host;
	}

	/**
	 * Whether the headers carry an Authorization header (key trimmed, any case).
	 *
	 * @param array<string, mixed> $args Transport arguments.
	 * @throws \LogicException When the headers are not an array, or a key or value is not plain or holds a line break.
	 */
	private static function authorized( #[\SensitiveParameter] array $args ): bool {
		$headers = $args['headers'] ?? [];
		if ( ! is_array( $headers ) ) { // WordPress parses a raw header string itself and an object hides its keys: neither can be checked.
			throw new \LogicException( 'Provider request headers must be an array.' );
		}
		$found = false;
		foreach ( $headers as $key => $value ) {
			if ( ! is_scalar( $value ) || 1 === preg_match( '/[\r\n]/', $key . "\0" . $value ) ) {
				throw new \LogicException( 'A provider request header is a plain value on one line.' );
			}
			$found = $found || 'authorization' === strtolower( trim( (string) $key ) );
		}

		return $found;
	}

	private static function refused( string $label ): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s gave an address this site does not send requests to.', 'lw-migration' ), $label ), PermanentFailure::INVALID );
	}
}
