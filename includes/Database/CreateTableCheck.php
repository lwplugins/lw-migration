<?php
/**
 * Decides whether a CREATE TABLE stays inside the import tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the statement that will actually run. Quoted text ('…', "…", `…`)
 * is masked first so it cannot hide or fake keywords; any comment is
 * refused, because a versioned comment is executed by the server. Table
 * options that can read or write another table, another database or the
 * file system (MERGE/FEDERATED-style engines, UNION, CONNECTION,
 * DATA/INDEX DIRECTORY, AS SELECT, ( LIKE t ), foreign keys) are refused.
 * LIKE elsewhere (CHECK constraints, generated columns) is an operator. A
 * ";" outside quoted text is allowed only as the one terminator at the end.
 * Column defaults, generated columns, CHECK constraints and key parts are
 * checked by CreateTableExpressions.
 */
final class CreateTableCheck {

	private const ENGINES = [ 'innodb', 'myisam', 'aria', 'memory', 'heap' ];

	private const FORBIDDEN = '/\b(UNION|CONNECTION|DIRECTORY|INSERT_METHOD|SELECT|TABLE|REFERENCES)\b/i';

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
		$parts = $this->columnList( $sql );
		if ( null === $parts ) {
			return false;
		}
		[ $rest, $quoted ] = $parts;
		if ( 1 === preg_match( '/^\s*LIKE\b/i', $rest ) || 1 === preg_match( self::FORBIDDEN, $rest ) ) {
			return false;
		}
		preg_match_all( '/\bENGINE\b\s*=?\s*([^\s,();]*)/i', $rest, $engines );
		foreach ( $engines[1] as $engine ) {
			if ( ! in_array( strtolower( $engine ), self::ENGINES, true ) ) {
				return false;
			}
		}
		return CreateTableExpressions::areSafe( $rest, $quoted );
	}

	/**
	 * Whether a CREATE TABLE of an import table is refused for a column
	 * default, a generated column, a CHECK constraint or a key part (for the
	 * import's warning).
	 *
	 * @param string $sql Statement.
	 */
	public function refusesExpression( string $sql ): bool {
		$parts = $this->columnList( $sql );

		return null !== $parts && ! CreateTableExpressions::areSafe( $parts[0], $parts[1] );
	}

	/**
	 * One CREATE TABLE of an import table, masked.
	 *
	 * @param string $sql Statement.
	 * @return array{0: string, 1: array<int, string>}|null Masked text after the list's "(" and the quoted parts.
	 */
	private function columnList( string $sql ): ?array {
		$parts = SqlQuotes::mask( $sql );
		if ( null === $parts || ! self::isOneStatement( $parts[0] ) ) {
			return null;
		}
		[ $masked, $quoted ] = $parts;
		if ( 1 !== preg_match( '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?\x01(\d+)\x02\s*\(/i', $masked, $m ) ) {
			return null;
		}
		if ( ! SqlQuotes::identifierStartsWith( $quoted[ (int) $m[2] ], $this->prefixes ) ) {
			return null;
		}

		return [ substr( $masked, strlen( $m[0] ) ), $quoted ];
	}

	/**
	 * @param string $masked Statement with its quoted parts masked.
	 */
	private static function isOneStatement( string $masked ): bool {
		$body = rtrim( $masked, " \t\r\n\f\v" );
		if ( str_ends_with( $body, ';' ) ) {
			$body = substr( $body, 0, -1 );
		}

		return ! str_contains( $body, ';' );
	}
}
