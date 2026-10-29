<?php
/**
 * Decides whether an INSERT only writes literal rows into an import table.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts exactly: INSERT INTO `<tmp>name` [(col, …)] VALUES (v, …)[, (…)…] [;]
 * where every value is a literal: a quoted string (optionally _charset, N, X
 * or B prefixed), a number, 0x-hex (a lower-case 0x only: MySQL reads 0X…
 * as an identifier), NULL, TRUE, FALSE or DEFAULT. No
 * comments, functions, subqueries or ON DUPLICATE KEY. One linear scan:
 * strings are skipped with strcspn, other tokens are \G-anchored matches.
 */
final class InsertCheck {

	private const WS = " \t\r\n\f\v";

	private const LITERAL = '/\G(?:(?-i:0x)[0-9a-f]+|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?|NULL|TRUE|FALSE|DEFAULT)(?![\w$\x80-\xff])/Ai';

	private const STRING_PREFIX = '/\G(?:_[a-z0-9]+\s*|[nxb])(?=[\'"])/Ai';

	/** @var array<int, string> */
	private array $prefixes;

	/**
	 * @param string             $tmpPrefix      Temporary prefix.
	 * @param string             $extraTmpPrefix Extra-table temporary prefix ('' = none).
	 * @param array<int, string> $only           When given, the only table prefixes allowed (replace both above).
	 */
	public function __construct( string $tmpPrefix, string $extraTmpPrefix = '', array $only = [] ) {
		$this->prefixes = [] === $only ? [ $tmpPrefix, $extraTmpPrefix ] : array_values( $only ); // An empty prefix never matches.
	}

	public function isSafe( string $sql ): bool {
		if ( 1 !== preg_match( '/\GINSERT\s+INTO\s+(?=`)/Ai', $sql, $m ) ) {
			return false;
		}
		$i   = strlen( $m[0] );
		$end = SqlQuotes::closingQuote( $sql, $i );
		if ( null === $end || ! SqlQuotes::identifierStartsWith( substr( $sql, $i, $end - $i ), $this->prefixes ) ) {
			return false;
		}
		$i = $this->columns( $sql, $this->skipWs( $sql, $end + 1 ) );
		if ( null === $i || 1 !== preg_match( '/\GVALUES/Ai', $sql, $m, 0, $i ) ) {
			return false;
		}
		$i = $this->tuples( $sql, $i + 6 );
		if ( null === $i ) {
			return false;
		}
		if ( ';' === ( $sql[ $i ] ?? '' ) ) {
			$i = $this->skipWs( $sql, $i + 1 );
		}

		return strlen( $sql ) === $i;
	}

	private function skipWs( string $sql, int $i ): int {
		return $i + strspn( $sql, self::WS, $i );
	}

	/**
	 * Optional column list of backticked or plain names.
	 *
	 * @param string $sql Statement.
	 * @param int    $i   Offset.
	 * @return int|null Offset after it, or null when malformed.
	 */
	private function columns( string $sql, int $i ): ?int {
		if ( '(' !== ( $sql[ $i ] ?? '' ) ) {
			return $i;
		}
		do {
			$i = $this->skipWs( $sql, $i + 1 );
			if ( '`' === ( $sql[ $i ] ?? '' ) ) {
				$end = SqlQuotes::closingQuote( $sql, $i );
				if ( null === $end ) {
					return null;
				}
				$i = $end + 1;
			} elseif ( 1 === preg_match( '/\G[a-z0-9_$]+/Ai', $sql, $m, 0, $i ) ) {
				$i += strlen( $m[0] );
			} else {
				return null;
			}
			$i = $this->skipWs( $sql, $i );
		} while ( ',' === ( $sql[ $i ] ?? '' ) );

		return ')' === ( $sql[ $i ] ?? '' ) ? $this->skipWs( $sql, $i + 1 ) : null;
	}

	/**
	 * @param string $sql Statement.
	 * @param int    $i   Offset after VALUES.
	 * @return int|null Offset after the last tuple and whitespace, or null when malformed.
	 */
	private function tuples( string $sql, int $i ): ?int {
		while ( true ) {
			$i = $this->skipWs( $sql, $i );
			if ( '(' !== ( $sql[ $i ] ?? '' ) ) {
				return null;
			}
			do {
				$i = $this->value( $sql, $this->skipWs( $sql, $i + 1 ) );
				if ( null === $i ) {
					return null;
				}
				$i = $this->skipWs( $sql, $i );
			} while ( ',' === ( $sql[ $i ] ?? '' ) );
			if ( ')' !== ( $sql[ $i ] ?? '' ) ) {
				return null;
			}
			$i = $this->skipWs( $sql, $i + 1 );
			if ( ',' !== ( $sql[ $i ] ?? '' ) ) {
				return $i;
			}
			++$i;
		}
	}

	private function value( string $sql, int $i ): ?int {
		if ( 1 === preg_match( self::STRING_PREFIX, $sql, $m, 0, $i ) ) {
			$i += strlen( $m[0] );
		}
		$c = $sql[ $i ] ?? '';
		if ( "'" === $c || '"' === $c ) {
			$end = SqlQuotes::closingQuote( $sql, $i );

			return null === $end ? null : $end + 1;
		}
		if ( isset( $m[0] ) ) {
			return null; // A prefix must be followed by a string.
		}
		if ( 1 === preg_match( self::LITERAL, $sql, $m, 0, $i ) ) {
			return $i + strlen( $m[0] );
		}

		return null;
	}
}
