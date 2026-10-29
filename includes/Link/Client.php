<?php
/**
 * Signed requests from this site to a connected site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Http\AddressRedactor;
use LightweightPlugins\Migration\Http\SafeTransport;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Every request goes through SafeTransport (issue #2: the address is checked
 * against the refused and internal ranges and pinned; core's
 * wp_safe_remote_request still refuses internal hosts and unusual ports and
 * checks https certificates), never follows a redirect (its signature
 * belongs to this address) and reads at most
 * MAX_ANSWER bytes of an answer, streamed to a temporary file so that no
 * compressed answer is ever inflated in memory (transport()). http and https both work (user ruling
 * U1); insecure() tells the screens to warn about http.
 * The URL is `<address>/index.php?rest_route=<route>`, which works with and
 * without pretty permalinks, like the schedules' loopback. A successful
 * answer counts only with the other site's signature for the request
 * (assertSigned()); failures become LinkException with a user-facing text,
 * the other site's own text reduced (addresses without their tokens) and
 * clipped. Nothing here ever
 * prints, logs or throws the secret, a signature or a nonce.
 */
final class Client {

	public const BASE = '/lw-migration/v1/link';

	public const TIMEOUT = 30;

	public const TICK_TIMEOUT = 120;

	public const UPLOAD_TIMEOUT = 120;

	/** Most bytes of an answer read (review m2); a longer one is a protocol failure. */
	public const MAX_ANSWER = 1048576;

	/** Seconds the two clocks may differ before a refusal is explained as a clock problem. */
	public const CLOCK_SLACK = 240;

	private const REMOTE_TEXT_BYTES = 500;

	/**
	 * Transport: fn( string $url, array $args ): array|\WP_Error.
	 *
	 * @var \Closure
	 */
	private \Closure $send;

	/**
	 * Clock: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * @param array{id: string, address: string, key: string} $link Outgoing link with its opened secret.
	 * @param \Closure|null                                   $send Transport (default: SafeTransport, streamed).
	 * @param \Closure|null                                   $now  Clock.
	 */
	public function __construct( private array $link, ?\Closure $send = null, ?\Closure $now = null ) {
		$this->send = $send ?? self::transport();
		$this->now  = $now ?? static fn(): int => time();
	}

	public function id(): string {
		return $this->link['id'];
	}

	public function address(): string {
		return $this->link['address'];
	}

	public static function url( string $address, string $route ): string {
		return add_query_arg( 'rest_route', $route, rtrim( $address, '/' ) . '/index.php' );
	}

	/**
	 * @param string $url Address or URL.
	 * @throws LinkException SCHEME for anything but http and https.
	 */
	public static function assertScheme( string $url ): void {
		if ( ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) {
			throw new LinkException( LinkException::SCHEME, __( 'Connections work only with http and https addresses.', 'lw-migration' ) );
		}
	}

	/**
	 * Whether the whole site would travel unencrypted to or from this address
	 * (the screens and the CLI warn; ruling U1).
	 *
	 * @param string $address Address.
	 */
	public static function insecure( string $address ): bool {
		return 'http' === strtolower( (string) wp_parse_url( $address, PHP_URL_SCHEME ) );
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param string $body   Raw body.
	 * @return array<string, string>
	 */
	public function headers( string $method, string $route, string $body ): array {
		return Signer::headers( $this->link['id'], $this->link['key'], $method, $route, $body, (int) ( $this->now )() );
	}

	/**
	 * Fresh headers (time, nonce, signature) for every GET of one path, for
	 * RangeDownloader's retries and parts.
	 *
	 * @param string $path Path below BASE.
	 */
	public function signerFor( string $path ): \Closure {
		$route = self::BASE . $path;

		return fn(): array => $this->headers( 'GET', $route, '' );
	}

	public function urlOf( string $path ): string {
		return self::url( $this->link['address'], self::BASE . $path );
	}

	/**
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path below BASE.
	 * @param array<string, mixed>|null $data    JSON body, or none.
	 * @param int                       $timeout Seconds.
	 * @return array{status: int, json: array<mixed>}
	 * @throws LinkException On every failure.
	 */
	public function json( string $method, string $path, ?array $data = null, int $timeout = self::TIMEOUT ): array {
		$body = null === $data ? '' : (string) wp_json_encode( $data );

		// Also without a body: core's transports would otherwise declare a form for a POST.
		return $this->call( $method, $path, $body, [ 'Content-Type' => 'application/json' ], $timeout );
	}

	/**
	 * @param string $method  HTTP method.
	 * @param string $path    Path below BASE.
	 * @param string $bytes   Raw body.
	 * @param int    $timeout Seconds.
	 * @return array{status: int, json: array<mixed>}
	 * @throws LinkException On every failure.
	 */
	public function raw( string $method, string $path, string $bytes, int $timeout = self::UPLOAD_TIMEOUT ): array {
		return $this->call( $method, $path, $bytes, [ 'Content-Type' => 'application/octet-stream' ], $timeout );
	}

	/**
	 * The unsigned pairing request (it proves the code with its MAC).
	 *
	 * @param \Closure|null        $send    Transport (default: SafeTransport, streamed).
	 * @param string               $address The other site's address.
	 * @param string               $route   REST route.
	 * @param array<string, mixed> $data    JSON body.
	 * @param int                  $now     Unix time.
	 * @return array<mixed> The JSON answer.
	 * @throws LinkException On every failure (a refusal is never explained by the clock).
	 */
	public static function unsigned( ?\Closure $send, string $address, string $route, array $data, int $now ): array {
		$url = self::url( $address, $route );
		self::assertScheme( $url );
		$response = ( $send ?? self::transport() )(
			$url,
			[
				'method'              => 'POST',
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_ANSWER,
				'headers'             => [ 'Content-Type' => 'application/json' ],
				'body'                => (string) wp_json_encode( $data ),
			]
		);

		return self::answer( $response, $address, $now, false )['json'];
	}

	/**
	 * @param string                $method  HTTP method.
	 * @param string                $path    Path below BASE.
	 * @param string                $body    Raw body ('' for none).
	 * @param array<string, string> $headers Content type.
	 * @param int                   $timeout Seconds.
	 * @return array{status: int, json: array<mixed>}
	 * @throws LinkException On every failure.
	 */
	private function call( string $method, string $path, string $body, array $headers, int $timeout ): array {
		$route = self::BASE . $path;
		$url   = self::url( $this->link['address'], $route );
		self::assertScheme( $url );
		$args = [
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_ANSWER,
			'headers'             => array_merge( $headers, $this->headers( $method, $route, $body ) ),
		];
		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = ( $this->send )( $url, $args );
		$answer   = self::answer( $response, $this->link['address'], (int) ( $this->now )() );
		$this->assertSigned( $response, $args['headers'][ Signer::HEADER_NONCE ], $answer['status'] );

		return $answer;
	}

	/**
	 * A successful answer counts only with the other site's signature for
	 * this very request (its nonce), status and body (issue #1): on an http
	 * connection anyone on the way could otherwise answer instead of it.
	 * Never retried: an answer that was changed is no network hiccup.
	 *
	 * @param array<string, mixed> $response Transport answer (2xx).
	 * @param string               $nonce    The nonce this request was signed with.
	 * @param int                  $status   HTTP status.
	 * @throws LinkException UNSIGNED.
	 */
	private function assertSigned( array $response, string $nonce, int $status ): void {
		$signature = wp_remote_retrieve_header( $response, strtolower( Signer::HEADER_RESPONSE ) );
		$body      = (string) wp_remote_retrieve_body( $response );
		if ( ! is_string( $signature ) || ! Signer::verifyAnswer( $this->link['id'], $this->link['key'], $nonce, $status, $body, $signature ) ) {
			/* translators: %s: the other site's address */
			throw new LinkException( LinkException::UNSIGNED, sprintf( __( 'The answer from %s is not signed for this connection, so this site did not use it: it may have been changed on its way. Use https on both sites, and keep LW Migration up to date on both.', 'lw-migration' ), AddressRedactor::display( $this->link['address'] ) ), $status );
		}
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response Transport answer.
	 * @param string                         $address  The other site's address.
	 * @param int                            $now      Unix time.
	 * @param bool                           $signed   Whether the request was signed (a refusal may be the clock).
	 * @return array{status: int, json: array<mixed>}
	 * @throws LinkException Unless a 2xx JSON object.
	 */
	public static function answer( $response, string $address, int $now, bool $signed = true ): array {
		$shown = AddressRedactor::display( $address );
		if ( is_wp_error( $response ) ) {
			/* translators: 1: the other site's address, 2: what went wrong, e.g. "it did not answer in time." */
			throw new LinkException( LinkException::UNREACHABLE, sprintf( __( '%1$s cannot be reached: %2$s', 'lw-migration' ), $shown, TransportFailure::text( (string) $response->get_error_code(), (string) $response->get_error_message() ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = strlen( $raw ) >= self::MAX_ANSWER ? null : json_decode( $raw, true ); // Cut off by limit_response_size: never parsed.
		$code   = is_array( $json ) && is_string( $json['code'] ?? null ) ? $json['code'] : '';
		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $json ) ) {
				/* translators: %s: the other site's address */
				throw new LinkException( LinkException::PROTOCOL, sprintf( __( '%s sent an answer this site does not understand. Update LW Migration on both sites.', 'lw-migration' ), $shown ), $status );
			}

			return [
				'status' => $status,
				'json'   => $json,
			];
		}
		if ( 401 === $status && 'lw_migration_link_refused' === $code ) {
			$date    = wp_remote_retrieve_header( $response, 'date' );
			$refusal = self::refused( $shown, $signed && is_string( $date ) ? self::skew( $date, $now ) : null );
			throw $refusal;
		}
		if ( 404 === $status && 'rest_no_route' === $code ) {
			/* translators: %s: the other site's address */
			throw new LinkException( LinkException::NOT_AVAILABLE, sprintf( __( '%s does not offer connections. Is LW Migration active and up to date there? A multisite network cannot be connected.', 'lw-migration' ), $shown ), $status );
		}
		$text = is_array( $json ) && is_string( $json['message'] ?? null ) ? $json['message'] : (string) $status;
		$data = is_array( $json ) && is_array( $json['data'] ?? null ) ? $json['data'] : [];

		/* translators: 1: the other site's address, 2: its answer */
		throw new LinkException( LinkException::REMOTE, sprintf( __( '%1$s answered: %2$s', 'lw-migration' ), $shown, self::reduce( $text, self::REMOTE_TEXT_BYTES ) ), $status, $data, $code );
	}

	/**
	 * @param string   $shown The other site's address for display.
	 * @param int|null $skew  Seconds the other clock is ahead (null: unknown or unsigned).
	 */
	private static function refused( string $shown, ?int $skew ): LinkException {
		if ( null !== $skew && abs( $skew ) > self::CLOCK_SLACK ) {
			/* translators: 1: the other site's address, 2: minutes */
			return new LinkException( LinkException::CLOCK, sprintf( __( 'The clocks of this site and %1$s differ by about %2$d minutes; connections work only within 5 minutes. Fix the server time.', 'lw-migration' ), $shown, (int) round( abs( $skew ) / 60 ) ), 401 );
		}

		/* translators: %s: the other site's address */
		return new LinkException( LinkException::REFUSED, sprintf( __( '%s no longer accepts this connection: it was removed there, or that site\'s security keys changed. Remove it here and pair the sites again.', 'lw-migration' ), $shown ), 401 );
	}

	/**
	 * @param string $date The answer's Date header.
	 * @param int    $now  Unix time.
	 */
	private static function skew( string $date, int $now ): ?int {
		$then = '' === $date ? false : strtotime( $date );

		return false === $then ? null : $then - $now;
	}

	/**
	 * @param string $text  The other site's text.
	 * @param int    $bytes Byte limit.
	 */
	private static function reduce( string $text, int $bytes ): string {
		return Text::clip( AddressRedactor::reduce( trim( wp_strip_all_tags( $text ) ) ), $bytes );
	}

	/**
	 * The guarded safe request (SafeTransport), with the answer streamed to a temporary file of at
	 * most MAX_ANSWER bytes and read back from it: core inflates a body kept in
	 * memory after cURL already decoded it, so a doubly compressed answer would
	 * grow far past limit_response_size; a streamed body is never inflated
	 * again. `identity` asks the other site not to compress at all (the socket
	 * transport writes a compressed answer to the file as it is).
	 */
	private static function transport(): \Closure {
		$web = new SafeTransport();

		return static function ( string $url, array $args ) use ( $web ) {
			$file = tempnam( get_temp_dir(), 'lwm-link-' );
			if ( false === $file ) {
				throw new LinkException( LinkException::UNREACHABLE, __( 'This site has no temporary folder for the other site\'s answer.', 'lw-migration' ) );
			}
			$args['stream']                     = true;
			$args['filename']                   = $file;
			$args['headers']['Accept-Encoding'] = 'identity';
			try {
				$response = $web->send( $url, $args );
				if ( is_array( $response ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a local temporary file; a missing one reads as an empty answer.
					$response['body'] = (string) @file_get_contents( $file, false, null, 0, self::MAX_ANSWER );
				}

				return $response;
			} finally {
				Files::removeFile( $file );
			}
		};
	}
}
