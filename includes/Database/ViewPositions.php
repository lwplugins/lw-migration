<?php
/**
 * Where a name stands in a view statement: in a table position or not.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Follows the FROM lists of a view statement token by token, for
 * ViewReferences: which names are table references (after FROM, a JOIN,
 * TABLE, a comma or an opening parenthesis of a FROM list, inside
 * NEXTVAL()/LASTVAL()/SETVAL(), after NEXT|PREVIOUS VALUE FOR), and which
 * tables and aliases the statement declares (a table name, the alias after it,
 * the alias after a derived table or a table function). It fails closed: an
 * index hint (USE|IGNORE|FORCE INDEX|KEY [FOR JOIN|ORDER BY|GROUP BY] (…))
 * must come right after a table, its alias or another hint; a keyword that
 * would end a FROM list must be followed by what its grammar needs (WINDOW by
 * `name AS (`, FOR by UPDATE or SHARE, so MariaDB's FOR SYSTEM_TIME is not
 * followed); an ODBC brace, an unbalanced parenthesis or too many names make
 * the statement unresolved.
 */
final class ViewPositions {

	/** Keywords after which a table reference follows. */
	private const TABLE_KEYWORDS = [ 'FROM', 'JOIN', 'STRAIGHT_JOIN', 'TABLE' ];

	/** Keywords that end a FROM list at their parenthesis depth. */
	private const FROM_ENDS = [ 'SELECT', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'WINDOW', 'UNION', 'INTERSECT', 'EXCEPT', 'FOR', 'LOCK', 'INTO' ];

	/** What follows a set operator. */
	private const SET_FOLLOWERS = [ 'SELECT', 'ALL', 'DISTINCT', 'TABLE', 'VALUES', 'WITH', '(' ];

	/** The tokens these keywords must be followed by, one list per token: words, '(' or '' for a name that is no keyword. */
	private const FOLLOWERS = [
		'UNION'     => [ self::SET_FOLLOWERS ],
		'INTERSECT' => [ self::SET_FOLLOWERS ],
		'EXCEPT'    => [ self::SET_FOLLOWERS ],
		'GROUP'     => [ [ 'BY' ] ],
		'ORDER'     => [ [ 'BY' ] ],
		'WINDOW'    => [ [ '' ], [ 'AS' ], [ '(' ] ],
		'FOR'       => [ [ 'UPDATE', 'SHARE' ] ],
	];

	/** Other keywords: never a table, an alias or a name to declare. */
	private const KEYWORDS = [ 'USE', 'IGNORE', 'FORCE', 'LATERAL', 'ON', 'USING', 'AS', 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'NATURAL', 'OUTER', 'FULL', 'PARTITION', 'PROCEDURE', 'WITH', 'RECURSIVE', 'ALL', 'DISTINCT' ];

	/** Keywords that start an index hint. */
	private const HINTS = [ 'USE', 'IGNORE', 'FORCE' ];

	/** Functions whose argument is a sequence (a table name). */
	private const SEQUENCES = [ 'NEXTVAL', 'LASTVAL', 'SETVAL' ];

	/** Declared names followed up at most; more cannot be checked. */
	private const MAX_DECLARED = 10000;

	/**
	 * Per parenthesis depth: whether a FROM list is open, whether the parenthesis
	 * stands where a table does (its closing one may be followed by an alias), and
	 * whether it holds the index list of a hint.
	 *
	 * @var array<int, array{0: bool, 1: bool, 2: bool}>
	 */
	private array $depths = [ [ false, false, false ] ];

	/**
	 * Whether the next name is a table reference.
	 *
	 * @var bool
	 */
	private bool $expect = false;

	/**
	 * 1: an alias may follow, 2: AS was read and an alias must follow.
	 *
	 * @var int
	 */
	private int $alias = 0;

	/**
	 * The next name is the alias just declared (it is no table reference itself).
	 *
	 * @var bool
	 */
	private bool $aliasName = false;

	/**
	 * What came last: 'table' (a table reference), 'alias', 'hint' (an index
	 * list, also across the comma after it) or '' — an index hint may only follow
	 * one of the first three.
	 *
	 * @var string
	 */
	private string $unit = '';

	/**
	 * Index hint state: 1 after USE|IGNORE|FORCE, 2 after INDEX|KEY, 3 after FOR, 4 after ORDER|GROUP.
	 *
	 * @var int
	 */
	private int $hint = 0;

	/**
	 * The tokens still owed to a keyword (FOLLOWERS), one list per token.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $check = [];

	/**
	 * The name before '(' is a table function ('table') or a sequence function ('sequence').
	 *
	 * @var string
	 */
	private string $call = '';

	/**
	 * The previous single unquoted word, upper-case ('' after anything else).
	 *
	 * @var string
	 */
	private string $previous = '';

	/**
	 * Set when the statement cannot be followed.
	 *
	 * @var bool
	 */
	private bool $unresolved = false;

	/**
	 * Tables and aliases declared in a table position, lower-case => true.
	 *
	 * @var array<string, bool>
	 */
	private array $declared = [];

	public function unresolved(): bool {
		return $this->unresolved;
	}

	public function fail(): void {
		$this->unresolved = true;
	}

	/**
	 * @param string $name A qualifier.
	 */
	public function declares( string $name ): bool {
		return isset( $this->declared[ strtolower( $name ) ] );
	}

	/**
	 * After the last token: whether the statement was followed to its end.
	 */
	public function finish(): bool {
		$this->follow( 'end', '' );

		return ! $this->unresolved && 0 === $this->hint && 1 === count( $this->depths );
	}

	/**
	 * A finished name: a keyword, an index hint word, an alias, or a name.
	 *
	 * @param array<int, string> $parts  Name parts.
	 * @param bool               $bare   The first part is unquoted.
	 * @param bool               $called Followed by an opening parenthesis.
	 * @return bool Whether the name stands in a table position.
	 */
	public function name( array $parts, bool $bare, bool $called ): bool {
		$count          = count( $parts );
		$word           = $bare && 1 === $count ? strtoupper( $parts[0] ) : '';
		$previous       = $this->previous;
		$this->previous = $word;
		$unit           = $this->unit;
		$this->unit     = '';
		if ( $this->aliasName ) {
			$this->aliasName = false;
			$this->unit      = 'alias';
			return false;
		}
		if ( 0 !== $this->hint ) {
			$this->hintWord( $word );
			return false;
		}
		if ( '' !== $word && self::isKeyword( $word ) ) {
			$this->keyword( $word, $previous, $unit );
			return false;
		}
		$table        = $this->expect;
		$this->expect = false;
		if ( $table ) {
			$this->declare( $parts[ $count - 1 ] );
			$this->alias = $called ? 0 : 1;
			$this->call  = $called ? 'table' : '';
			$this->unit  = $called ? '' : 'table';
		} elseif ( $called && in_array( $word, self::SEQUENCES, true ) ) {
			$this->call = 'sequence';
		}

		return $table;
	}

	/**
	 * Checks the token after a keyword (FOLLOWERS) and reads the alias after a table.
	 *
	 * @param string $type  Token type ('end' after the last one).
	 * @param string $value Token.
	 */
	public function follow( string $type, string $value ): void {
		$word = 'word' === $type ? strtoupper( $value ) : '';
		$name = 'id' === $type || ( 'word' === $type && ! self::isKeyword( $word ) );
		if ( [] !== $this->check ) {
			$allowed = (array) array_shift( $this->check );
			$key     = 'punct' === $type ? $value : ( 'word' === $type ? $word : "\0" );
			if ( ! ( in_array( $key, $allowed, true ) || ( $name && in_array( '', $allowed, true ) ) ) ) {
				$this->unresolved = true;
				$this->check      = [];
			}
		}
		if ( 0 === $this->alias ) {
			return;
		}
		$after       = 2 === $this->alias;
		$this->alias = 0;
		if ( ! $after && 'AS' === $word ) {
			$this->alias = 2;
		} elseif ( $name ) {
			$this->declare( $value );
			$this->aliasName = true;
		} elseif ( $after ) {
			$this->unresolved = true;
		}
	}

	/**
	 * Any token that is not a name: a string, a number or a punctuation mark.
	 *
	 * @param string $type  Token type.
	 * @param string $value Token.
	 */
	public function punctuation( string $type, string $value ): void {
		$call           = $this->call;
		$unit           = $this->unit;
		$this->call     = '';
		$this->unit     = '';
		$this->previous = '';
		if ( 0 !== $this->hint ) {
			$this->hintList( $type, $value );
			return;
		}
		if ( 'punct' !== $type ) {
			$this->expect = false;
			return;
		}
		if ( '{' === $value ) {
			$this->unresolved = true; // ODBC escape syntax ({ OJ … }): not followed.
			return;
		}
		if ( '(' === $value ) {
			$this->depths[] = 'table' === $call ? [ false, true, false ] : ( 'sequence' === $call ? [ true, false, false ] : [ $this->expect, $this->expect, false ] );
			$this->expect   = 'sequence' === $call || ( '' === $call && $this->expect );
			return;
		}
		if ( ')' === $value ) {
			$this->close();
			return;
		}
		$this->expect = ',' === $value && $this->depths[ count( $this->depths ) - 1 ][0];
		$this->unit   = ',' === $value && 'hint' === $unit ? 'hint' : '';
	}

	private static function isKeyword( string $word ): bool {
		return in_array( $word, self::TABLE_KEYWORDS, true ) || in_array( $word, self::FROM_ENDS, true ) || in_array( $word, self::KEYWORDS, true );
	}

	/**
	 * @param string $word     Upper-case keyword.
	 * @param string $previous The single word before it.
	 * @param string $unit     What came before it (an index hint needs a table, an alias or a hint).
	 */
	private function keyword( string $word, string $previous, string $unit ): void {
		$depth = count( $this->depths ) - 1;
		if ( 'FOR' === $word && 'VALUE' === $previous ) {
			$this->expect = true; // NEXT|PREVIOUS VALUE FOR db.sequence.
			return;
		}
		if ( 'LATERAL' === $word ) {
			return;
		}
		if ( in_array( $word, self::HINTS, true ) ) {
			$this->hint       = 1;
			$this->expect     = false;
			$this->unresolved = $this->unresolved || ! in_array( $unit, [ 'table', 'alias', 'hint' ], true );
			return;
		}
		if ( in_array( $word, self::TABLE_KEYWORDS, true ) ) {
			$this->depths[ $depth ][0] = true;
			$this->expect              = true;
			return;
		}
		if ( isset( self::FOLLOWERS[ $word ] ) ) {
			$this->check = self::FOLLOWERS[ $word ];
		}
		if ( in_array( $word, self::FROM_ENDS, true ) ) {
			$this->depths[ $depth ][0] = false;
		}
		$this->expect = false;
	}

	/**
	 * One word of an index hint; anything out of its grammar cannot be followed.
	 *
	 * @param string $word Upper-case word ('' for a quoted or longer name).
	 */
	private function hintWord( string $word ): void {
		$next = [
			1 => [
				'INDEX' => 2,
				'KEY'   => 2,
			],
			2 => [ 'FOR' => 3 ],
			3 => [
				'JOIN'  => 2,
				'ORDER' => 4,
				'GROUP' => 4,
			],
			4 => [ 'BY' => 2 ],
		][ $this->hint ][ $word ] ?? 0;
		if ( 0 === $next ) {
			$this->unresolved = true;
		}
		$this->hint = $next;
	}

	/**
	 * The opening parenthesis of a hint's index list; anything else is out of its grammar.
	 *
	 * @param string $type  Token type.
	 * @param string $value Token.
	 */
	private function hintList( string $type, string $value ): void {
		if ( 2 === $this->hint && 'punct' === $type && '(' === $value ) {
			$this->hint     = 0;
			$this->depths[] = [ false, false, true ];
			return;
		}
		$this->unresolved = true;
	}

	private function close(): void {
		if ( 1 === count( $this->depths ) ) {
			$this->unresolved = true;
			return;
		}
		$closed       = array_pop( $this->depths );
		$this->expect = false;
		$this->alias  = $closed[1] ? 1 : 0;
		$this->unit   = $closed[2] ? 'hint' : '';
	}

	private function declare( string $name ): void {
		$this->declared[ strtolower( $name ) ] = true;
		$this->unresolved                      = $this->unresolved || count( $this->declared ) > self::MAX_DECLARED;
	}
}
