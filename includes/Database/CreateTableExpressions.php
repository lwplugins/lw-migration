<?php
/**
 * Checks the expressions a CREATE TABLE gives the server to evaluate.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Works on the masked column and key list (quoted parts are \x01N\x02).
 *
 * A DEFAULT is one of: a literal (string, number, 0x-hex, bit), NULL, TRUE,
 * FALSE, a current date or time function (with or without parentheses or a
 * precision), UUID(), UUID_TO_BIN(UUID()[, 0|1]), or one of these inside the
 * parentheses of an expression default; ON UPDATE takes a time function only.
 * After the value only another column attribute may follow.
 *
 * A generated column (AS (…)) and a CHECK constraint may hold quoted column
 * names, literals, operators, a few operator words and calls of PURE
 * functions only: string, number, JSON-reading and NULL-handling built-ins
 * that read nothing but their arguments (MariaDB writes CHECK
 * (json_valid(`col`)) for every JSON column). No time, random, file, sleep,
 * user, lock or stored function. A functional key part ((…) in a key list)
 * is refused: a "(" is allowed only after a name, as in a type's length or a
 * key's column list.
 */
final class CreateTableExpressions {

	private const TIME = '(?:CURRENT_TIMESTAMP|NOW|LOCALTIMESTAMP|LOCALTIME|CURDATE|CURTIME|UTC_TIMESTAMP|UTC_DATE|UTC_TIME)(?:\s*\(\s*\d*\s*\))?';

	private const NOT_WORD = '(?![\w$\x80-\xff])';

	/** The next keyword, or a "(" that must follow a name. */
	private const NEXT = '/\bDEFAULT\b|\bON\s+UPDATE\b|\b(?:CHECK|AS)\s*(?=\()|\(/i';

	/** Words that would continue a default as an expression. */
	private const OPERATOR_WORDS = [ 'and', 'or', 'xor', 'div', 'mod', 'like', 'regexp', 'rlike', 'is', 'in', 'between', 'sounds', 'member', 'interval' ];

	/** Functions a generated column or CHECK expression may call: built-ins that read only their arguments. */
	private const PURE = [ 'json_valid', 'json_extract', 'json_unquote', 'json_contains', 'json_length', 'json_type', 'concat', 'concat_ws', 'lower', 'upper', 'lcase', 'ucase', 'length', 'char_length', 'character_length', 'substring', 'substr', 'left', 'right', 'trim', 'ltrim', 'rtrim', 'replace', 'lpad', 'rpad', 'reverse', 'locate', 'instr', 'abs', 'ceil', 'ceiling', 'floor', 'round', 'truncate', 'greatest', 'least', 'if', 'ifnull', 'nullif', 'coalesce', 'md5', 'sha1', 'sha2', 'crc32', 'hex', 'unhex' ];

	/** Words allowed in a generated column or CHECK expression. */
	private const EXPRESSION_WORDS = [ 'and', 'or', 'not', 'xor', 'like', 'escape', 'is', 'null', 'true', 'false', 'in', 'between', 'regexp', 'rlike', 'div', 'mod' ];

	/**
	 * @param string             $rest   Masked text after the "(" that opens the column and key list.
	 * @param array<int, string> $quoted Quoted parts by marker number.
	 * @return bool
	 */
	public static function areSafe( string $rest, array $quoted ): bool {
		$body = self::body( $rest );
		if ( null === $body ) {
			return false;
		}
		$options = substr( $rest, strlen( $body ) + 1 );
		if ( 1 === preg_match( '/\bDEFAULT\b(?!\s*(?:CHARSET|CHARACTER|COLLATE)\b)|\b(?:CHECK|AS)\s*\(/i', $options ) ) {
			return false; // A column expression after the list.
		}

		return self::listIsSafe( $body, $quoted );
	}

	/**
	 * The column and key list up to its closing parenthesis.
	 *
	 * @param string $rest Masked text after the list's "(".
	 * @return string|null Null when the parenthesis never closes.
	 */
	private static function body( string $rest ): ?string {
		$depth  = 1;
		$length = strlen( $rest );
		for ( $i = strcspn( $rest, '()' ); $i < $length; $i += 1 + strcspn( $rest, '()', $i + 1 ) ) {
			$depth += '(' === $rest[ $i ] ? 1 : -1;
			if ( 0 === $depth ) {
				return substr( $rest, 0, $i );
			}
		}

		return null;
	}

	/**
	 * @param string             $body   Masked column and key list.
	 * @param array<int, string> $quoted Quoted parts.
	 */
	private static function listIsSafe( string $body, array $quoted ): bool {
		$i = 0;
		while ( 1 === preg_match( self::NEXT, $body, $m, PREG_OFFSET_CAPTURE, $i ) ) {
			$at   = $m[0][1];
			$word = strtoupper( (string) preg_replace( '/\s+/', ' ', $m[0][0] ) );
			if ( '(' === $word ) {
				if ( 1 !== preg_match( '/[\w$\x02]\s*$/', substr( $body, max( 0, $at - 64 ), min( 64, $at ) ) ) ) {
					return false; // An expression in parentheses where only a name's list may stand.
				}
				$i = $at + 1;
				continue;
			}
			$from = $at + strlen( $m[0][0] );
			$i    = 'DEFAULT' === $word || 'ON UPDATE' === $word
				? self::value( $body, $from, $quoted, 'DEFAULT' === $word )
				: self::expression( $body, $from, $quoted );
			if ( null === $i ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string             $body       Masked list.
	 * @param int                $i          Offset after DEFAULT or ON UPDATE.
	 * @param array<int, string> $quoted     Quoted parts.
	 * @param bool               $anyDefault Whether every default form is allowed (false: a time function only).
	 * @return int|null Offset after the value, or null when it is refused.
	 */
	private static function value( string $body, int $i, array $quoted, bool $anyDefault ): ?int {
		$pattern = $anyDefault
			? '/\G\s*(?:' . self::defaultValue() . '|\(\s*' . self::defaultValue() . '\s*\))' . self::NOT_WORD . '/Ai'
			: '/\G\s*' . self::TIME . self::NOT_WORD . '/Ai';
		if ( 1 !== preg_match( $pattern, $body, $m, PREG_UNMATCHED_AS_NULL, $i ) ) {
			return null;
		}
		$marker = $m[1] ?? $m[2] ?? null;
		if ( null !== $marker && '`' === ( $quoted[ (int) $marker ][0] ?? '`' ) ) {
			return null; // A name is no literal.
		}
		$end = $i + strlen( (string) $m[0] );
		if ( 1 === preg_match( '/\G\s*([a-z_]+)/Ai', $body, $next, 0, $end ) ) {
			return in_array( strtolower( $next[1] ), self::OPERATOR_WORDS, true ) ? null : $end;
		}

		return 1 === preg_match( '/\G\s*(?:,|\z)/A', $body, $next, 0, $end ) ? $end : null;
	}

	/**
	 * The allowed DEFAULT values; a string's marker number is its one capture group.
	 */
	private static function defaultValue(): string {
		return '(?:(?:_[a-z0-9]+\s*|[nxb])?\x01(\d+)\x02|(?-i:0x)[0-9a-f]+|(?-i:0b)[01]+|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?'
			. '|NULL|TRUE|FALSE|UUID\s*\(\s*\)|UUID_TO_BIN\s*\(\s*UUID\s*\(\s*\)\s*(?:,\s*[01]\s*)?\)|' . self::TIME . ')';
	}

	/**
	 * A generated column or CHECK expression: its parentheses and every token inside.
	 *
	 * @param string             $body   Masked list.
	 * @param int                $i      Offset of the opening parenthesis.
	 * @param array<int, string> $quoted Quoted parts.
	 * @return int|null Offset after the closing parenthesis, or null when it is refused.
	 */
	private static function expression( string $body, int $i, array $quoted ): ?int {
		$depth = 0;
		$token = '/\G(?:\s+|\x01(\d+)\x02|[nxb](?=\x01)|_[a-z0-9]+\s*(?=\x01)|(?-i:0x)[0-9a-f]+' . self::NOT_WORD . '|(?-i:0b)[01]+' . self::NOT_WORD
			. '|(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?' . self::NOT_WORD . '|[()=<>!+\-*\/%&|^~,]|([a-z_][a-z0-9_]*)' . self::NOT_WORD . ')/Ai';
		while ( 1 === preg_match( $token, $body, $m, PREG_UNMATCHED_AS_NULL, $i ) ) {
			$i += strlen( (string) $m[0] );
			if ( '(' === $m[0] ) {
				++$depth;
			} elseif ( ')' === $m[0] && 0 === --$depth ) {
				return $i;
			} elseif ( ! self::allowedToken( $m, $body, $i, $quoted ) ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * @param array<int|string, string|null> $m      Token match.
	 * @param string                         $body   Masked list.
	 * @param int                            $after  Offset after the token.
	 * @param array<int, string>             $quoted Quoted parts.
	 */
	private static function allowedToken( array $m, string $body, int $after, array $quoted ): bool {
		$call = 1 === preg_match( '/\G\s*\(/A', $body, $unused, 0, $after );
		if ( null !== ( $m[1] ?? null ) ) {
			return ! ( $call && '`' === ( $quoted[ (int) $m[1] ][0] ?? '`' ) ); // A quoted name called as a function.
		}
		if ( null !== ( $m[2] ?? null ) ) {
			$word = strtolower( $m[2] );

			return in_array( $word, self::EXPRESSION_WORDS, true ) || ( $call && in_array( $word, self::PURE, true ) );
		}

		return true;
	}
}
