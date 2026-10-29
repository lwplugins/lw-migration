<?php
/**
 * Quote-aware scanning shared by the import statement checks.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the end of '…', "…" and `…` (backslash escapes outside backticks,
 * doubled quotes everywhere) in linear time, and masks quoted parts so
 * structural checks never look inside them.
 */
final class SqlQuotes {

	/**
	 * @param string $sql   Statement.
	 * @param int    $start Offset of the opening quote.
	 * @return int|null Offset of the closing quote, or null when unterminated.
	 */
	public static function closingQuote( string $sql, int $start ): ?int {
		$quote  = $sql[ $start ];
		$stops  = '`' === $quote ? '`' : $quote . '\\';
		$length = strlen( $sql );
		$i      = $start + 1;
		while ( $i < $length ) {
			$i += strcspn( $sql, $stops, $i );
			if ( $i >= $length ) {
				return null;
			}
			if ( '\\' === $sql[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( ( $sql[ $i + 1 ] ?? '' ) !== $quote ) {
				return $i;
			}
			$i += 2;
		}

		return null;
	}

	/**
	 * Replaces every quoted part with "\x01N\x02".
	 *
	 * @param string $sql Statement.
	 * @return array{0: string, 1: array<int, string>}|null Null on a comment, a marker byte or an unterminated quote.
	 */
	public static function mask( string $sql ): ?array {
		$out    = '';
		$quoted = [];
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$plain = strcspn( $sql, "'\"`#/-\x01\x02", $i );
			$out  .= substr( $sql, $i, $plain );
			$i    += $plain;
			if ( $i >= $length ) {
				break;
			}
			$c = $sql[ $i ];
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$end = self::closingQuote( $sql, $i );
				if ( null === $end ) {
					return null;
				}
				$quoted[] = substr( $sql, $i, $end - $i + 1 );
				$out     .= "\x01" . ( count( $quoted ) - 1 ) . "\x02";
				$i        = $end + 1;
				continue;
			}
			$next = $sql[ $i + 1 ] ?? '';
			if ( '#' === $c || "\x01" === $c || "\x02" === $c || ( '/' === $c && '*' === $next ) || ( '-' === $c && '-' === $next ) ) {
				return null;
			}
			$out .= $c;
			++$i;
		}

		return [ $out, $quoted ];
	}

	/**
	 * Like mask(), but comments stay in the masked text (a rewrite may remove
	 * them); a quote inside a comment does not open a string.
	 *
	 * @param string $sql Statement.
	 * @return array{0: string, 1: array<int, string>}|null Null on a marker byte, an unterminated quote or block comment.
	 */
	public static function maskQuotes( string $sql ): ?array {
		$out    = '';
		$quoted = [];
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$plain = strcspn( $sql, "'\"`#/-\x01\x02", $i );
			$out  .= substr( $sql, $i, $plain );
			$i    += $plain;
			if ( $i >= $length ) {
				break;
			}
			$c = $sql[ $i ];
			if ( "\x01" === $c || "\x02" === $c ) {
				return null;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$end = self::closingQuote( $sql, $i );
				if ( null === $end ) {
					return null;
				}
				$quoted[] = substr( $sql, $i, $end - $i + 1 );
				$out     .= "\x01" . ( count( $quoted ) - 1 ) . "\x02";
				$i        = $end + 1;
				continue;
			}
			$end = self::commentEnd( $sql, $i );
			if ( -1 === $end ) {
				return null;
			}
			$end  = $end ?? $i + 1;
			$out .= substr( $sql, $i, $end - $i );
			$i    = $end;
		}

		return [ $out, $quoted ];
	}

	/**
	 * @param string $sql Statement.
	 * @param int    $i   Offset of "#", "/" or "-".
	 * @return int|null Offset after the comment, null when none starts here, -1 when a block comment is unterminated.
	 */
	private static function commentEnd( string $sql, int $i ): ?int {
		$next = $sql[ $i + 1 ] ?? '';
		if ( '/' === $sql[ $i ] && '*' === $next ) {
			$close = strpos( $sql, '*/', $i + 2 );

			return false === $close ? -1 : $close + 2;
		}
		$line = '#' === $sql[ $i ] || ( '-' === $sql[ $i ] && '-' === $next && ( ! isset( $sql[ $i + 2 ] ) || ctype_space( $sql[ $i + 2 ] ) || ctype_cntrl( $sql[ $i + 2 ] ) ) );
		if ( ! $line ) {
			return null;
		}
		$newline = strpos( $sql, "\n", $i );

		return false === $newline ? strlen( $sql ) : $newline;
	}

	/**
	 * Whether a backtick-quoted identifier (with its backticks) starts with one of
	 * the prefixes in their exact case (a temporary table in another case would
	 * never be swapped in nor cleaned up by its run).
	 *
	 * @param string             $quoted   `name`.
	 * @param array<int, string> $prefixes Prefixes.
	 * @return bool
	 */
	public static function identifierStartsWith( string $quoted, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $quoted, '`' . $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
