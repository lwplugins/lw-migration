<?php
/**
 * One WebDAV request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 / plan D22, D35: wp_remote_request() with Basic auth, TLS
 * checked, no redirect followed, an explicit timeout and a capped answer,
 * sent through GuardedHttp as a host the admin typed (preflight P-C1): the
 * address is checked before anything (the password included) is sent, a name
 * is pinned, cURL is forced, a WordPress proxy refuses. $where must be
 * scheme://host[:port] (lower-case host, IPv6 in brackets) and a URL on another
 * server than $where is refused (the pin covers that server only). A
 * FileSlice body travels to the transport as $args[BODY_KEY]; the default
 * transport drops it and CurlUpload streams it in the guard's hook; tests see
 * it in RecordedHttp. Any answer other than 2xx throws WebDavErrors' kind of
 * failure; headers (with the password) are never logged or put in a message.
 */
final class WebDavClient {

	public const MAX_XML = 8388608;

	public const BODY_KEY = 'lw_migration_body';

	/** Server address: scheme://host[:port], a lower-case name or a bracketed IPv6 address, nothing after the port. */
	private const WHERE = '#^https?://([a-z0-9]([a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(:\d{1,5})?\z#';

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
	 * @param string                  $user     User name.
	 * @param string                  $password Password (an app password on Nextcloud).
	 * @param string                  $where    scheme://host[:port] for messages; every request URL starts with it.
	 * @param \Closure|null           $send     Transport fn( string $url, array $args ) (default: wp_remote_request without BODY_KEY).
	 * @param \Closure|null           $now      fn(): int (default time()).
	 * @param AddressGuard|null       $guard    Address check (default: real DNS).
	 * @param array<string, \Closure> $probes   Environment checks for tests (see GuardedHttp).
	 */
	public function __construct( private string $user, #[\SensitiveParameter] private string $password, private string $where, ?\Closure $send = null, ?\Closure $now = null, ?AddressGuard $guard = null, array $probes = [] ) {
		$send       = $send ?? static function ( string $url, array $args ) {
			unset( $args[ self::BODY_KEY ] );

			return wp_remote_request( $url, $args );
		};
		$scheme     = strtolower( (string) strstr( $where, '://', true ) );
		$authority  = (string) substr( (string) strstr( $where, '://' ), 3 );
		$this->now  = $now ?? static fn(): int => time();
		$this->http = new GuardedHttp( $where, $authority, $scheme, true, $send, $guard, $probes );
	}

	public function where(): string {
		return $this->where;
	}

	public function now(): int {
		return (int) ( $this->now )();
	}

	/**
	 * @param string                $method  HTTP method.
	 * @param string                $url     Full address (on the server of where()).
	 * @param array<string, string> $headers More headers.
	 * @param string|FileSlice      $body    Body ('' = none).
	 * @param int                   $timeout Seconds (0 = CloudTimeout::seconds()).
	 * @param int|null              $limit   Answer cap in bytes (null = none).
	 * @return array{status: int, body: string, length: int, range: string}
	 * @throws CloudFailure On a server address that is not plain, a URL on another server, a refused address, no answer or an answer other than 2xx.
	 * @throws \LightweightPlugins\Migration\Storage\Cloud\CloudJobException When a FileSlice cannot be read.
	 */
	public function request( string $method, string $url, array $headers = [], $body = '', int $timeout = 0, ?int $limit = self::MAX_XML ): array {
		if ( 1 !== preg_match( self::WHERE, $this->where ) ) {
			throw $this->unplain();
		}
		if ( ! str_starts_with( $url, $this->where . '/' ) ) {
			throw $this->elsewhere();
		}
		$args = $this->args( $method, $headers, $timeout, $limit );
		if ( $body instanceof FileSlice ) {
			$args[ self::BODY_KEY ] = $body;
			$response               = CurlUpload::send( $body, fn( \Closure $configure ) => $this->http->send( $url, $args, $configure ) );
		} else {
			if ( '' !== $body ) {
				$args['body'] = $body;
			}
			$response = $this->http->send( $url, $args );
		}
		$failure = $this->failure( $response );
		if ( null !== $failure ) {
			throw $failure;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$length = (string) wp_remote_retrieve_header( $response, 'content-length' );

		return [
			'status' => $status,
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'length' => 1 === preg_match( '/^\d{1,18}\z/', $length ) ? (int) $length : -1,
			'range'  => (string) wp_remote_retrieve_header( $response, 'content-range' ),
		];
	}

	/** A server address that is not plain; it is not repeated (it is not the form a message may show). */
	private function unplain(): PermanentFailure {
		return new PermanentFailure( __( 'The storage address of this destination is not valid. Check its settings.', 'lw-migration' ), PermanentFailure::INVALID );
	}

	/** A URL on another server: the address checked and pinned is where()'s only. */
	private function elsewhere(): PermanentFailure {
		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( 'A request of the destination at %s was for another server, so it was not sent.', 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
	}

	/**
	 * @param mixed $response Transport answer or error.
	 * @return CloudFailure|null Null for a 2xx answer.
	 */
	private function failure( $response ): ?CloudFailure {
		if ( is_wp_error( $response ) ) {
			return WebDavErrors::transport( (string) $response->get_error_code(), (string) $response->get_error_message(), $this->where );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );

		return $status >= 200 && $status < 300 ? null : WebDavErrors::classify( $status, (string) wp_remote_retrieve_header( $response, 'retry-after' ), $this->where, $this->now() );
	}

	/**
	 * @param string                $method  HTTP method.
	 * @param array<string, string> $headers More headers.
	 * @param int                   $timeout Seconds (0 = CloudTimeout::seconds()).
	 * @param int|null              $limit   Answer cap (null = none).
	 * @return array<string, mixed>
	 */
	private function args( string $method, array $headers, int $timeout, ?int $limit ): array {
		$args = [
			'method'      => $method,
			'headers'     => array_merge( [ 'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ) ], $headers ),
			'timeout'     => $timeout > 0 ? $timeout : CloudTimeout::seconds(),
			'redirection' => 0,
			'sslverify'   => true,
		];
		if ( null !== $limit ) {
			$args['limit_response_size'] = $limit;
		}

		return $args;
	}
}
