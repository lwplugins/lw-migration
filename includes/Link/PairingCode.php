<?php
/**
 * The pairing code one site shows and the other site types in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. `lwm1.<base64url of the address>.<32 hex nonce>`: the address the
 * other site sends its requests to, and the one-time secret of the code
 * (stored by the code's site as a lookup hash and sealed, Codes). Whitespace a copy adds
 * (line breaks, tabs) is ignored. The nonce never leaves the site that
 * typed the code: requests carry KeyExchange::lookup() and pairMac() only.
 * The messages are for logs; Pairing shows its own translated text.
 */
final class PairingCode {

	public const PREFIX = 'lwm1';

	public const MAX_ADDRESS = 2048;

	public const NONCE_PATTERN = '/^[a-f0-9]{32}\z/';

	/**
	 * @param string $address http(s) address without query or fragment.
	 * @param string $nonce   32 lower-case hex.
	 * @throws \InvalidArgumentException For another address or nonce form.
	 */
	public static function encode( string $address, string $nonce ): string {
		self::assertAddress( $address );
		if ( 1 !== preg_match( self::NONCE_PATTERN, $nonce ) ) {
			throw new \InvalidArgumentException( 'A pairing nonce has 32 hex characters.' );
		}

		return self::PREFIX . '.' . rtrim( strtr( base64_encode( $address ), '+/', '-_' ), '=' ) . '.' . $nonce;
	}

	/**
	 * @param string $code Typed or pasted code.
	 * @return array{address: string, nonce: string}
	 * @throws \InvalidArgumentException When it is not a pairing code.
	 */
	public static function decode( string $code ): array {
		$parts = explode( '.', (string) preg_replace( '/\s+/', '', $code ) );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] || 1 !== preg_match( '/^[A-Za-z0-9_-]{4,2800}\z/', $parts[1] ) || 1 !== preg_match( self::NONCE_PATTERN, $parts[2] ) ) {
			throw new \InvalidArgumentException( 'This is not a pairing code.' );
		}
		$b64     = strtr( $parts[1], '-_', '+/' );
		$address = base64_decode( str_pad( $b64, (int) ( ceil( strlen( $b64 ) / 4 ) * 4 ), '=' ), true );
		if ( false === $address || rtrim( strtr( base64_encode( $address ), '+/', '-_' ), '=' ) !== $parts[1] ) {
			throw new \InvalidArgumentException( 'This is not a pairing code.' );
		}
		self::assertAddress( $address );

		return [
			'address' => $address,
			'nonce'   => $parts[2],
		];
	}

	/**
	 * Allow-list: http or https in lower case, an LDH host name of at most 253
	 * bytes or an IP literal, an optional port 1-65535 and a path of unreserved
	 * characters, sub-delimiters, "/" and %XX. No credentials, query, fragment,
	 * backslash, whitespace or non-ASCII character. Both sides of a pairing use
	 * it: the code's site for its own address, the other site for the address a
	 * pairing request declares.
	 *
	 * @param string $address Address.
	 */
	public static function isAddress( string $address ): bool {
		$label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
		$re    = '#^https?://(?<host>' . $label . '(?:\.' . $label . ')*|\[[0-9A-Fa-f:.]{2,45}\])(?::(?<port>[0-9]{1,5}))?(?<path>/(?:[A-Za-z0-9._~!$&\'()*+,;=:/-]|%[0-9A-Fa-f]{2})*)?\z#';
		$ok    = strlen( $address ) <= self::MAX_ADDRESS && 1 === preg_match( $re, $address, $m );
		if ( $ok && '[' === $m['host'][0] ) {
			$ok = false !== filter_var( substr( $m['host'], 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}
		if ( $ok && '' !== ( $m['port'] ?? '' ) ) {
			$ok = (int) $m['port'] >= 1 && (int) $m['port'] <= 65535;
		}
		if ( $ok ) {
			$keys = array_keys( (array) parse_url( $address ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class, plain parsing.
			$ok   = [] === array_diff( $keys, [ 'scheme', 'host', 'port', 'path' ] ) && strlen( $m['host'] ) <= 253;
		}

		return $ok;
	}

	/**
	 * The ASCII form of an address, so a site under an international name fits
	 * a code: the host name as punycode (UTS #46 nontransitional, like
	 * browsers: ext-intl, or the IDNA encoder WordPress bundles when intl is
	 * missing or fails), every other non-ASCII byte percent-encoded. A host
	 * that cannot be converted stays as it is (and isAddress() refuses it).
	 *
	 * @param string $address Address.
	 */
	public static function ascii( string $address ): string {
		if ( 1 !== preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*://)([^/?\#]*)(.*)\z#s', $address, $m ) ) {
			return $address;
		}
		$host = 1 === preg_match( '/[\x80-\xFF]/', $m[2] ) ? self::idn( $m[2] ) : $m[2];

		return $m[1] . $host . (string) preg_replace_callback( '/[\x80-\xFF]/', static fn( array $c ): string => rawurlencode( $c[0] ), $m[3] );
	}

	/**
	 * @param string $host Host with non-ASCII characters (an optional :port stays).
	 */
	private static function idn( string $host ): string {
		$port = '';
		if ( 1 === preg_match( '/^(.*)(:[0-9]*)\z/s', $host, $m ) ) {
			[ , $host, $port ] = $m;
		}
		$ascii = false;
		if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
		}
		if ( ( ! is_string( $ascii ) || '' === $ascii ) && class_exists( \WpOrg\Requests\IdnaEncoder::class ) ) {
			try { // The encoder maps no case: lower-case first, as UTS #46 does.
				$ascii = \WpOrg\Requests\IdnaEncoder::encode( function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host ) );
			} catch ( \Throwable $e ) {
				$ascii = false;
			}
		}

		return ( is_string( $ascii ) && '' !== $ascii ? $ascii : $host ) . $port;
	}

	/**
	 * @param string $address Address.
	 * @throws \InvalidArgumentException When isAddress() says no.
	 */
	private static function assertAddress( string $address ): void {
		if ( ! self::isAddress( $address ) ) {
			throw new \InvalidArgumentException( 'This is not a site address.' );
		}
	}
}
