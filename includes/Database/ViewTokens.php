<?php
/**
 * Tokens of a masked SQL statement.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a statement masked by SqlQuotes::mask() into [ type, value ] pairs:
 * 'id' (a backtick-quoted name, unescaped), 'str' (a string, value ''), 'word'
 * (an unquoted name or keyword), 'num' (a number) and 'punct' (one character).
 * A generator, so a 16 MB statement never becomes a token array.
 */
final class ViewTokens {

	/**
	 * @param string             $masked Masked statement.
	 * @param array<int, string> $quoted Quoted parts.
	 * @return \Generator<int, array{0: string, 1: string}>
	 */
	public static function of( string $masked, array $quoted ): \Generator {
		$length = strlen( $masked );
		$i      = 0;
		while ( $i < $length ) {
			$i += strspn( $masked, " \t\r\n\f\v", $i );
			if ( $i >= $length ) {
				return;
			}
			if ( "\x01" === $masked[ $i ] ) {
				$end   = (int) strpos( $masked, "\x02", $i );
				$quote = $quoted[ (int) substr( $masked, $i + 1, $end - $i - 1 ) ];
				$i     = $end + 1;
				yield '`' === $quote[0] ? [ 'id', str_replace( '``', '`', substr( $quote, 1, -1 ) ) ] : [ 'str', '' ];
				continue;
			}
			if ( 1 === preg_match( '/\G[A-Za-z0-9_$\x80-\xff]+/', $masked, $m, 0, $i ) ) {
				$i += strlen( $m[0] );
				yield [ 1 === preg_match( '/^(?:\d+(?:e\d*)?|0x[0-9a-f]+|0b[01]+)$/i', $m[0] ) ? 'num' : 'word', $m[0] ];
				continue;
			}
			yield [ 'punct', $masked[ $i ] ];
			++$i;
		}
	}
}
