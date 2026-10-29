<?php
/**
 * Linear scanner for quoted SQL string literals.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * No regex: PCRE hits its backtrack limit on multi-MB literals. Double-quoted
 * strings and backtick identifiers are skipped and kept byte-identical (the
 * dumps never write them, so they are never rewritten).
 */
final class SqlLiterals {

	/**
	 * Rewrites every single-quoted literal body (still escaped, quotes excluded).
	 *
	 * @param string   $sql Statement.
	 * @param callable $fn  fn( string $rawBody, int $ordinal ): string, returns the new raw body; $ordinal counts the single-quoted literals from 0.
	 * @return string
	 * @throws DatabaseException On an unterminated literal.
	 */
	public static function map( string $sql, callable $fn ): string {
		$out = '';
		$pos = 0;
		$nth = 0;
		$len = strlen( $sql );
		while ( $pos < $len ) {
			$q = $pos + strcspn( $sql, "'\"`", $pos );
			if ( $q >= $len ) {
				break;
			}
			$end = SqlQuotes::closingQuote( $sql, $q );
			if ( null === $end ) {
				throw new DatabaseException( 'Unterminated string literal in statement.' );
			}
			$out .= substr( $sql, $pos, $q - $pos );
			if ( "'" === $sql[ $q ] ) {
				$out .= "'" . $fn( substr( $sql, $q + 1, $end - $q - 1 ), $nth ) . "'";
				++$nth;
			} else {
				$out .= substr( $sql, $q, $end - $q + 1 );
			}
			$pos = $end + 1;
		}

		return $out . substr( $sql, $pos );
	}
}
