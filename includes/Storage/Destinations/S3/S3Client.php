<?php
/**
 * One signed S3 request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4: WordPress' HTTP API (wp_remote_request, plan D4), TLS checked,
 * no redirect followed, an explicit timeout, the answer capped unless the
 * caller asks for none (a ranged read sets its own cap). The payload hash is
 * signed (x-amz-content-sha256 = SHA-256 of the body). The Host header is
 * signed but not sent: the transport sends the URL's host[:port], which is
 * the same string. Any answer other than 2xx throws S3Errors' kind of
 * failure; the request and its headers are never logged.
 *
 * SSRF: every request goes through GuardedHttp (the host checked by
 * AddressGuard, the checked address pinned, cURL forced, fail closed).
 */
final class S3Client {

	public const TIMEOUT = 60;

	/** Largest XML answer read (a listing page of 1000 keys is far below). */
	public const MAX_XML = 4194304;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * The checked, pinned send path.
	 *
	 * @var GuardedHttp
	 */
	private GuardedHttp $http;

	/**
	 * @param S3Endpoint              $endpoint Where the bucket is.
	 * @param SigV4Signer             $signer   Signer with the credentials.
	 * @param \Closure|null           $send     Transport (default wp_remote_request).
	 * @param \Closure|null           $now      fn(): int (default time()).
	 * @param AddressGuard|null       $guard Address check (default: real DNS).
	 * @param array<string, \Closure> $probes Environment checks for tests: curl fn(): bool, libcurl fn(): int, proxy fn( string $url ): bool.
	 */
	public function __construct( private S3Endpoint $endpoint, private SigV4Signer $signer, ?\Closure $send = null, ?\Closure $now = null, ?AddressGuard $guard = null, array $probes = [] ) {
		$this->now  = $now ?? static fn(): int => time();
		$this->http = new GuardedHttp( $endpoint->display(), $endpoint->host(), $endpoint->scheme, $endpoint->custom, $send, $guard, $probes );
	}

	public function endpoint(): S3Endpoint {
		return $this->endpoint;
	}

	/** The endpoint in display form, for messages. */
	public function where(): string {
		return $this->endpoint->display();
	}

	public function now(): int {
		return (int) ( $this->now )();
	}

	/**
	 * @param string                $method  HTTP method.
	 * @param string                $key     Object key ('' = the bucket).
	 * @param array<string, string> $query   Decoded query.
	 * @param string                $body    Body ('' = none).
	 * @param array<string, string> $headers More headers to sign and send (Range, Content-Type).
	 * @param int                   $timeout Seconds.
	 * @param int|null              $limit   Answer cap in bytes (null = none).
	 * @return array{status: int, body: string, etag: string, length: int, range: string}
	 * @throws CloudFailure On no answer or an answer other than 2xx.
	 */
	public function request( string $method, string $key, array $query = [], string $body = '', array $headers = [], int $timeout = self::TIMEOUT, ?int $limit = self::MAX_XML ): array {
		$now      = (int) ( $this->now )();
		$args     = $this->args( $method, $key, $query, $body, $headers, $timeout, $limit, $now );
		$response = $this->http->send( $this->endpoint->url( $key, $query ), $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw $this->failure( $response, $status, $now );
		}
		$text = (string) wp_remote_retrieve_body( $response );

		$length = (string) wp_remote_retrieve_header( $response, 'content-length' );

		return [
			'status' => $status,
			'body'   => $text,
			'etag'   => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'range'  => (string) wp_remote_retrieve_header( $response, 'content-range' ),
			'length' => 1 === preg_match( '/^\d{1,18}\z/', $length ) ? (int) $length : -1,
		];
	}

	/**
	 * The transport arguments: signed headers (Host is signed, not sent), TLS
	 * checked, no redirect, the timeout and the cap.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $key     Object key.
	 * @param array<string, string> $query   Decoded query.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers More headers.
	 * @param int                   $timeout Seconds.
	 * @param int|null              $limit   Answer cap (null = none).
	 * @param int                   $now     Unix time.
	 * @return array<string, mixed>
	 */
	private function args( string $method, string $key, array $query, string $body, array $headers, int $timeout, ?int $limit, int $now ): array {
		$hash   = hash( 'sha256', $body );
		$signed = $this->signer->sign(
			$method,
			$this->endpoint->path( $key ),
			$query,
			array_merge(
				[
					'Host'                 => $this->endpoint->host(),
					'x-amz-content-sha256' => $hash,
				],
				$headers
			),
			$hash,
			$now
		);
		unset( $signed['Host'] );
		$args = [
			'method'      => $method,
			'headers'     => $signed,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
		];
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		if ( null !== $limit ) {
			$args['limit_response_size'] = $limit;
		}

		return $args;
	}

	/**
	 * The pin options of GuardedHttp (kept here for the callers of M7a).
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @param string $ip   Checked address.
	 * @return array<int, mixed>
	 */
	public static function pinOptions( string $host, int $port, string $ip ): array {
		return GuardedHttp::pinOptions( $host, $port, $ip );
	}

	public static function pinEntry( string $host, int $port, string $ip ): string {
		return GuardedHttp::pinEntry( $host, $port, $ip );
	}

	/**
	 * @param mixed $response Transport answer or error.
	 * @param int   $status   HTTP status (0 = no answer).
	 * @param int   $now      Unix time.
	 */
	private function failure( $response, int $status, int $now ): CloudFailure {
		if ( is_wp_error( $response ) ) {
			return S3Errors::transport( (string) $response->get_error_code(), (string) $response->get_error_message(), $this->where() );
		}

		return S3Errors::classify( $status, (string) wp_remote_retrieve_body( $response ), (string) wp_remote_retrieve_header( $response, 'retry-after' ), $this->where(), $now );
	}
}
