<?php
/**
 * Addresses in messages without their secrets.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: share links carry their access token in the query, the user info or a
 * path segment, so an address is shown only as scheme://host[:port]/…/<last
 * segment>, and every piece of the request address is masked in a message.
 */
final class AddressRedactor {

	public const MASK = '***';

	/** Shorter path segments and query values ("s", "u", "dl=1") are structure, not secrets. */
	private const MIN_SEGMENT = 4;

	/** A last path segment this long without a dot is treated as a token. */
	private const TOKEN_MIN = 16;

	/** Punctuation (ASCII and UTF-8 closing quotes) that ends a sentence, not an address. */
	private const TRAILING = '(?:[,.;:!?"\']|\xE2\x80\x9D|\xE2\x80\x99|\xC2\xBB)*';

	/** Stands in for the request address while its pieces are masked. */
	private const HOLD = "\x00";

	/**
	 * Scheme, host, port and the last path segment only.
	 *
	 * @param string $url Address.
	 */
	public static function display( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '(address)';
		}
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$segments = explode( '/', ltrim( $parts['path'] ?? '', '/' ) );
		$last     = (string) array_pop( $segments );
		if ( self::isToken( $last ) ) {
			$path = '/…';
		} else {
			$path = '' === ( $parts['path'] ?? '' ) ? '' : ( [] === $segments ? '/' : '/…/' ) . $last;
		}

		return strtolower( $parts['scheme'] ) . '://' . $parts['host'] . $port . $path;
	}

	/**
	 * Whether a path segment looks like an access token rather than a file
	 * name: long, only letters, digits, "_" and "-" (no extension).
	 *
	 * @param string $segment Raw path segment.
	 */
	private static function isToken( string $segment ): bool {
		return strlen( $segment ) >= self::TOKEN_MIN && 1 === preg_match( '#^[A-Za-z0-9_-]+$#', $segment );
	}

	/**
	 * Replaces the request address (raw and decoded) with its display form, masks
	 * its credentials, query, fragment and inner path segments anywhere in the
	 * message, and reduces every other address in it.
	 *
	 * @param string $message Transport message.
	 * @param string $url     The requested address.
	 */
	public static function redact( string $message, string $url ): string {
		$whole   = self::byLength( [ $url, rawurldecode( $url ) ] );
		$message = str_replace( $whole, self::HOLD, $message );
		$secrets = self::secrets( $url );
		if ( [] !== $secrets ) {
			$message = str_replace( $secrets, self::MASK, $message );
		}
		$message = self::reduce( $message );

		return str_replace( self::HOLD, self::display( $url ), $message );
	}

	/**
	 * Shows every address in a text only in its display form (no user info,
	 * query, fragment or inner path segments).
	 *
	 * @param string $message Any text.
	 */
	public static function reduce( string $message ): string {
		return (string) preg_replace_callback( // A failed match yields '' (never the unreduced text).
			'#[a-z][a-z0-9+.-]*://[^\s"\'<>\x00]+#i',
			static function ( array $m ): string {
				preg_match( '#^(.+?)(' . self::TRAILING . ')$#s', $m[0], $split );

				return self::display( $split[1] ?? $m[0] ) . ( $split[2] ?? '' );
			},
			$message
		);
	}

	/**
	 * Raw and decoded user, password, query, fragment, query values, inner path
	 * segments and a token-like last segment.
	 *
	 * @param string $url Address.
	 * @return array<int, string> Longest first.
	 */
	private static function secrets( string $url ): array {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return [];
		}
		$always = [ $parts['user'] ?? '', $parts['pass'] ?? '', $parts['query'] ?? '', $parts['fragment'] ?? '' ];
		$long   = explode( '/', trim( $parts['path'] ?? '', '/' ) );
		$last   = (string) array_pop( $long );
		if ( self::isToken( $last ) ) {
			$long[] = $last;
		}
		foreach ( explode( '&', $parts['query'] ?? '' ) as $pair ) {
			$long[] = (string) substr( $pair, (int) strpos( $pair . '=', '=' ) + 1 );
		}
		$long = array_filter( $long, static fn( string $piece ): bool => strlen( $piece ) >= self::MIN_SEGMENT );

		return self::byLength( array_merge( $always, $long ) );
	}

	/**
	 * Non-empty raw and decoded forms, unique, longest first.
	 *
	 * @param array<int, string> $pieces Pieces.
	 * @return array<int, string>
	 */
	private static function byLength( array $pieces ): array {
		$all = [];
		foreach ( $pieces as $piece ) {
			$all[] = $piece;
			$all[] = rawurldecode( $piece );
		}
		$all = array_values( array_unique( array_filter( $all, static fn( string $piece ): bool => '' !== $piece && false === strpos( $piece, self::HOLD ) ) ) );
		usort( $all, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $all;
	}
}
