<?php
/**
 * HMAC-SHA256 signatures of the requests between two connected sites.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (no WordPress). A request is signed over its link id, method, REST
 * route, Unix time, a one-time nonce and the SHA-256 of its raw body, with
 * the link's 256-bit secret. parse() checks the form of every header (one
 * value each: WordPress joins a repeated header with ",", which no pattern
 * accepts), verify() the time window and the signature (hash_equals).
 * Remembering the nonce is the caller's job (Nonces), and only after
 * verify() said yes. An answer of a signed request is signed too (answer()):
 * over the link id, that request's nonce, the HTTP status and the SHA-256
 * of the raw body, so an answer cannot be forged, replayed for another
 * request or changed on its way (issue #1).
 */
final class Signer {

	public const WINDOW = 300;

	public const SECRET_BYTES = 32;

	public const LINK_PATTERN = '/^[a-f0-9]{16}\z/';

	public const NONCE_PATTERN = '/^[a-f0-9]{32}\z/';

	public const SIGNATURE_PATTERN = '/^[a-f0-9]{64}\z/';

	public const TIME_PATTERN = '/^[1-9][0-9]{0,11}\z/';

	public const HEADER_LINK = 'X-LWM-Link';

	public const HEADER_TIME = 'X-LWM-Time';

	public const HEADER_NONCE = 'X-LWM-Nonce';

	public const HEADER_SIGNATURE = 'X-LWM-Signature';

	public const HEADERS = [ self::HEADER_LINK, self::HEADER_TIME, self::HEADER_NONCE, self::HEADER_SIGNATURE ];

	/** The signature of an answer (answer()). */
	public const HEADER_RESPONSE = 'X-LWM-Response-Signature';

	private const SCHEME = 'LWM-HMAC-SHA256';

	private const ANSWER_SCHEME = 'LWM-RESP';

	/**
	 * @param string $linkId Link id.
	 * @param string $method HTTP method (letters only).
	 * @param string $route  REST route (visible ASCII only).
	 * @param int    $time   Unix time.
	 * @param string $nonce  Request nonce.
	 * @param string $body   Raw body.
	 * @return string The signed text.
	 * @throws \InvalidArgumentException For a method or route that could shift a line of the text.
	 */
	public static function canonical( string $linkId, string $method, string $route, int $time, string $nonce, string $body ): string {
		if ( ! self::plain( $method, $route ) ) {
			throw new \InvalidArgumentException( 'A method has letters only and a route visible ASCII only.' );
		}

		return implode( "\n", [ self::SCHEME, $linkId, strtoupper( $method ), $route, (string) $time, $nonce, hash( 'sha256', $body ) ] );
	}

	/**
	 * @param string      $linkId Link id.
	 * @param string      $secret 32 raw bytes.
	 * @param string      $method HTTP method.
	 * @param string      $route  REST route (/lw-migration/v1/link/...).
	 * @param string      $body   Raw body ('' for none).
	 * @param int         $now    Unix time.
	 * @param string|null $nonce  Fixed nonce (tests); null = a fresh one.
	 * @return array<string, string> The four headers.
	 * @throws \InvalidArgumentException For a malformed link id or secret.
	 */
	public static function headers( string $linkId, string $secret, string $method, string $route, string $body, int $now, ?string $nonce = null ): array {
		if ( 1 !== preg_match( self::LINK_PATTERN, $linkId ) || self::SECRET_BYTES !== strlen( $secret ) ) {
			throw new \InvalidArgumentException( 'A link id has 16 hex characters and a secret 32 bytes.' );
		}
		$nonce ??= bin2hex( random_bytes( 16 ) );
		if ( 1 !== preg_match( self::NONCE_PATTERN, $nonce ) ) {
			throw new \InvalidArgumentException( 'A nonce has 32 hex characters.' );
		}

		return [
			self::HEADER_LINK      => $linkId,
			self::HEADER_TIME      => (string) $now,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => hash_hmac( 'sha256', self::canonical( $linkId, $method, $route, $now, $nonce, $body ), $secret ),
		];
	}

	/**
	 * @param array<string, mixed> $headers Values by HEADER_* name (missing = absent).
	 * @return array{link: string, time: int, nonce: string, signature: string}|null Null unless every header has its form.
	 */
	public static function parse( array $headers ): ?array {
		$link      = $headers[ self::HEADER_LINK ] ?? null;
		$time      = $headers[ self::HEADER_TIME ] ?? null;
		$nonce     = $headers[ self::HEADER_NONCE ] ?? null;
		$signature = $headers[ self::HEADER_SIGNATURE ] ?? null;
		if ( ! is_string( $link ) || ! is_string( $time ) || ! is_string( $nonce ) || ! is_string( $signature ) ) {
			return null;
		}
		if ( 1 !== preg_match( self::LINK_PATTERN, $link ) || 1 !== preg_match( self::TIME_PATTERN, $time )
			|| 1 !== preg_match( self::NONCE_PATTERN, $nonce ) || 1 !== preg_match( self::SIGNATURE_PATTERN, $signature ) ) {
			return null;
		}

		return [
			'link'      => $link,
			'time'      => (int) $time,
			'nonce'     => $nonce,
			'signature' => $signature,
		];
	}

	public static function inWindow( int $time, int $now ): bool {
		return abs( $now - $time ) <= self::WINDOW;
	}

	/**
	 * @param array{link: string, time: int, nonce: string, signature: string} $parts  From parse().
	 * @param string                                                           $secret 32 raw bytes.
	 * @param string                                                           $method HTTP method.
	 * @param string                                                           $route  REST route.
	 * @param string                                                           $body   Raw body.
	 * @param int                                                              $now    Unix time.
	 */
	public static function verify( array $parts, string $secret, string $method, string $route, string $body, int $now ): bool {
		if ( self::SECRET_BYTES !== strlen( $secret ) || ! self::inWindow( $parts['time'], $now ) || ! self::plain( $method, $route ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', self::canonical( $parts['link'], $method, $route, $parts['time'], $parts['nonce'], $body ), $secret );

		return hash_equals( $expected, $parts['signature'] );
	}

	private static function plain( string $method, string $route ): bool {
		return 1 === preg_match( '/^[A-Za-z]+\z/', $method ) && 1 === preg_match( '/^[\x21-\x7E]+\z/', $route );
	}

	/**
	 * The signature of an answer to a verified request (HEADER_RESPONSE).
	 *
	 * @param string $linkId Link id.
	 * @param string $secret 32 raw bytes.
	 * @param string $nonce  The request's nonce.
	 * @param int    $status HTTP status of the answer.
	 * @param string $body   Raw body of the answer.
	 */
	public static function answer( string $linkId, string $secret, string $nonce, int $status, string $body ): string {
		return hash_hmac( 'sha256', implode( "\n", [ self::ANSWER_SCHEME, $linkId, $nonce, (string) $status, hash( 'sha256', $body ) ] ), $secret );
	}

	/**
	 * @param string $linkId    Link id.
	 * @param string $secret    32 raw bytes.
	 * @param string $nonce     The nonce this site sent with the request.
	 * @param int    $status    HTTP status of the answer.
	 * @param string $body      Raw body of the answer.
	 * @param string $signature The answer's HEADER_RESPONSE.
	 */
	public static function verifyAnswer( string $linkId, string $secret, string $nonce, int $status, string $body, string $signature ): bool {
		if ( self::SECRET_BYTES !== strlen( $secret ) || 1 !== preg_match( self::SIGNATURE_PATTERN, $signature ) ) {
			return false;
		}

		return hash_equals( self::answer( $linkId, $secret, $nonce, $status, $body ), $signature );
	}
}
