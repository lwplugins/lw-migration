<?php
/**
 * One request to a provider API, with the Bearer token where it belongs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.8 / plan E7 with preflight P-C2: every request goes through
 * ProviderHttp (https on 443 to a DNS name, checked and pinned, TLS
 * checked, no redirect followed — a provider-given address is followed by
 * the caller explicitly). An authorized request (the default) carries the
 * TokenKeeper's Bearer token and may go only to a provider API host; that
 * is decided before any token is fetched, and a caller never sets its own
 * Authorization header (both programming errors, LogicException). A 401 on
 * an authorized request renews the token once and sends the request once
 * more; a second 401 goes back to the caller, whose error map makes it
 * REAUTH. A request with auth false (an upload session address, a download
 * Location) is sent without a token and never renews one. Answers are
 * capped at MAX_JSON unless the caller sets a limit (null: none, for a
 * download it bounds itself). HTTP statuses never throw here — each
 * provider's error map classifies them; a refused address, a request never
 * sent and a transport failure throw (fixed words, never the address).
 */
final class OAuthHttp {

	public const MAX_JSON = 4194304;

	private const HEADERS = [ 'location', 'range', 'retry-after', 'dropbox-api-result', 'content-range', 'content-length' ];

	/**
	 * The guarded send path.
	 *
	 * @var ProviderHttp
	 */
	private ProviderHttp $http;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * @param TokenKeeper       $tokens Tokens.
	 * @param \Closure|null     $send   Transport (default wp_remote_request).
	 * @param \Closure|null     $now    Clock (default time()).
	 * @param AddressGuard|null $guard  Address check (default: real DNS, or the tests' resolver).
	 */
	public function __construct( private TokenKeeper $tokens, ?\Closure $send = null, ?\Closure $now = null, ?AddressGuard $guard = null ) {
		$this->http = new ProviderHttp( $send, $guard );
		$this->now  = $now ?? static fn(): int => time();
	}

	/**
	 * @param string                $method  HTTP method.
	 * @param string                $url     https address.
	 * @param array<string, string> $headers Request headers (never Authorization).
	 * @param string                $body    Body.
	 * @param array<string, mixed>  $options auth (bool, default true), timeout (int, default CloudTimeout::seconds()), limit (int|null, default MAX_JSON).
	 * @return array{status: int, body: string, headers: array<string, string>}
	 * @throws \LogicException When the caller sets Authorization, or asks for a token to a host that is not a provider API host (a CloudFailure for a refused address, a transport failure or no usable token passes through).
	 */
	public function request( string $method, #[\SensitiveParameter] string $url, array $headers = [], #[\SensitiveParameter] string $body = '', array $options = [] ): array {
		$auth = (bool) ( $options['auth'] ?? true );
		$host = ProviderHttp::host( $url, $this->label() );
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( trim( (string) $name ) ) ) {
				throw new \LogicException( 'The Authorization header is set by OAuthHttp only.' );
			}
		}
		if ( $auth && ! in_array( $host, ProviderHttp::BEARER_HOSTS, true ) ) {
			throw new \LogicException( 'A Bearer token is sent only to a provider API host.' );
		}
		$answer = $this->send( $method, $url, $headers, $body, $options, $auth ? $this->tokens->token() : null );
		if ( $auth && 401 === $answer['status'] ) {
			$answer = $this->send( $method, $url, $headers, $body, $options, $this->tokens->renew() );
		}

		return $answer;
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer An answer.
	 * @return array<string, mixed>
	 */
	public function json( array $answer ): array {
		return HttpFailures::json( $answer['body'] );
	}

	public function label(): string {
		return $this->tokens->app()->provider->label;
	}

	public function now(): int {
		return (int) ( $this->now )();
	}

	public function tokens(): TokenKeeper {
		return $this->tokens;
	}

	/**
	 * @param string                $method  Method.
	 * @param string                $url     Address.
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Body.
	 * @param array<string, mixed>  $options Options.
	 * @param string|null           $token   Bearer token (null: none).
	 * @return array{status: int, body: string, headers: array<string, string>}
	 * @throws CloudFailure On a refused address or a transport failure.
	 */
	private function send( string $method, #[\SensitiveParameter] string $url, array $headers, #[\SensitiveParameter] string $body, array $options, #[\SensitiveParameter] ?string $token ): array {
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args  = [
			'method'  => $method,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => (int) ( $options['timeout'] ?? CloudTimeout::seconds() ),
		];
		$limit = array_key_exists( 'limit', $options ) ? $options['limit'] : self::MAX_JSON;
		if ( null !== $limit ) {
			$args['limit_response_size'] = (int) $limit;
		}
		$response = $this->http->send( $url, $args, $this->label() );
		$found    = [];
		foreach ( self::HEADERS as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_string( $value ) && '' !== $value ) {
				$found[ $name ] = $value;
			}
		}

		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $found,
		];
	}
}
