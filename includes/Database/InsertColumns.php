<?php
/**
 * Names the column a literal of an INSERT statement goes into.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * For a statement InsertCheck accepted: INSERT INTO `t` [(cols)] VALUES (…)[, (…)].
 * Literals are counted as SqlLiterals::map() counts them (single-quoted ones
 * only), quoted parts are skipped with SqlQuotes, and the value's position in
 * its row picks the column name (or only the position without a column list).
 */
final class InsertColumns {

	private const WS = " \t\r\n\f\v";

	/**
	 * @param string $sql     INSERT statement.
	 * @param int    $literal Ordinal of a single-quoted literal (from 0).
	 * @return array{table: string, column: string|null, position: int}|null Position counts from 1; null when not found.
	 */
	public static function ofLiteral( string $sql, int $literal ): ?array {
		$found = self::ofLiterals( $sql, [ $literal ], 1 );

		return [] === $found || 0 === $found[0]['position'] ? null : $found[0];
	}

	/**
	 * The columns of many literals in one pass: each column once, in the
	 * order of their positions, at most $max of them; past that one more
	 * entry names only the table (position 0).
	 *
	 * @param string          $sql      INSERT statement.
	 * @param array<int, int> $literals Ordinals of single-quoted literals (from 0).
	 * @param int             $max      Columns named at most.
	 * @return array<int, array{table: string, column: string|null, position: int}>
	 */
	public static function ofLiterals( string $sql, array $literals, int $max ): array {
		if ( [] === $literals || 1 !== preg_match( '/\GINSERT\s+INTO\s+(?=`)/Ai', $sql, $m ) ) {
			return [];
		}
		$i   = strlen( $m[0] );
		$end = SqlQuotes::closingQuote( $sql, $i );
		if ( null === $end ) {
			return [];
		}
		$table = self::unquote( substr( $sql, $i, $end - $i + 1 ) );
		$names = self::names( $sql, $end + 1 );
		if ( null === $names ) {
			return [];
		}
		$positions = self::positions( $sql, $names[1], array_flip( $literals ) );
		sort( $positions );
		$out = [];
		foreach ( array_slice( $positions, 0, $max ) as $position ) {
			$out[] = [
				'table'    => $table,
				'column'   => $names[0][ $position ] ?? null,
				'position' => $position + 1,
			];
		}
		if ( count( $positions ) > $max ) {
			$out[] = [
				'table'    => $table,
				'column'   => null,
				'position' => 0,
			];
		}

		return $out;
	}

	/**
	 * @param string $sql Statement.
	 * @param int    $i   Offset after the table name.
	 * @return array{0: array<int, string>, 1: int}|null Column names (none without a list) and the offset after them.
	 */
	private static function names( string $sql, int $i ): ?array {
		$i = $i + strspn( $sql, self::WS, $i );
		if ( '(' !== ( $sql[ $i ] ?? '' ) ) {
			return [ [], $i ];
		}
		$names = [];
		do {
			$i += 1 + strspn( $sql, self::WS, $i + 1 );
			if ( '`' === ( $sql[ $i ] ?? '' ) ) {
				$end = SqlQuotes::closingQuote( $sql, $i );
				if ( null === $end ) {
					return null;
				}
				$names[] = self::unquote( substr( $sql, $i, $end - $i + 1 ) );
				$i       = $end + 1;
			} elseif ( 1 === preg_match( '/\G[a-z0-9_$]+/Ai', $sql, $m, 0, $i ) ) {
				$names[] = $m[0];
				$i      += strlen( $m[0] );
			} else {
				return null;
			}
			$i += strspn( $sql, self::WS, $i );
		} while ( ',' === ( $sql[ $i ] ?? '' ) );

		return ')' === ( $sql[ $i ] ?? '' ) ? [ $names, $i + 1 ] : null;
	}

	/**
	 * @param string          $sql    Statement.
	 * @param int             $i      Offset after the column list.
	 * @param array<int, int> $wanted Ordinal => any, of the literals asked for.
	 * @return array<int, int> Distinct positions in their row (from 0) of the literals found.
	 */
	private static function positions( string $sql, int $i, array $wanted ): array {
		$length   = strlen( $sql );
		$left     = count( $wanted );
		$found    = [];
		$depth    = 0;
		$position = 0;
		$seen     = 0;
		while ( $left > 0 ) {
			$i += strcspn( $sql, "'\"`(),", $i );
			if ( $i >= $length ) {
				break;
			}
			$c = $sql[ $i ];
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$end = SqlQuotes::closingQuote( $sql, $i );
				if ( null === $end ) {
					break;
				}
				if ( "'" === $c && isset( $wanted[ $seen++ ] ) ) {
					$found[ $position ] = $position;
					--$left;
				}
				$i = $end + 1;
				continue;
			}
			if ( '(' === $c && 0 === $depth++ ) {
				$position = 0;
			} elseif ( ')' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 1 === $depth ) {
				++$position;
			}
			++$i;
		}

		return array_values( $found );
	}

	private static function unquote( string $quoted ): string {
		return str_replace( '``', '`', substr( $quoted, 1, -1 ) );
	}
}
