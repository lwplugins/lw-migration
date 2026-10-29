<?php
/**
 * Finds references to another database in a view statement.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * A deferred view runs as the site's database user, so it could expose any
 * other database that user can read. A name's first part is a database when
 * the name has three parts (db.table.column, db.table.*), when it stands in a
 * table position (ViewPositions) or when it is called (db.function()). Any
 * other two-part name passes only when its first part is declared as a table
 * or an alias in a table position (ViewPositions). Anything the scan cannot
 * resolve (a comment, an unterminated quote, whatever ViewPositions cannot
 * follow, too many names) means the view cannot be checked, and it is skipped.
 * Quoted strings follow the import's own quote rules (backslash escapes,
 * sql_mode = '').
 */
final class ViewReferences {

	/** Distinct qualifiers followed up at most; more cannot be checked. */
	private const MAX_NAMES = 1000;

	/**
	 * Table positions and declarations of the statement.
	 *
	 * @var ViewPositions
	 */
	private ViewPositions $positions;

	/**
	 * First parts of two-part names outside a table position: lower-case => as written.
	 *
	 * @var array<string, string>
	 */
	private array $qualifiers = [];

	private function __construct() {
		$this->positions = new ViewPositions();
	}

	/**
	 * @param string $sql     View statement.
	 * @param string $current The database the view is created in.
	 * @return string|null The first other database the statement names, '' when the statement cannot be checked, null when it names none.
	 */
	public static function otherDatabase( string $sql, string $current ): ?string {
		$masked = SqlQuotes::mask( $sql );

		return null === $masked ? '' : ( new self() )->scan( $masked[0], $masked[1], $current );
	}

	/**
	 * Why a view is skipped, for a warning (the database name is clipped).
	 *
	 * @param string $database otherDatabase() result that is not null.
	 */
	public static function reason( string $database ): string {
		if ( '' === $database ) {
			return 'it cannot be checked for references to another database';
		}

		return sprintf( 'it reads another database (`%s`)', Text::clip( $database, 64 ) );
	}

	/**
	 * @param string             $masked  Statement with its quoted parts masked.
	 * @param array<int, string> $quoted  The quoted parts.
	 * @param string             $current Current database.
	 * @return string|null
	 */
	private function scan( string $masked, array $quoted, string $current ): ?string {
		$name = [];
		$bare = false;
		$dot  = false;
		$var  = false;
		$at   = false;
		foreach ( ViewTokens::of( $masked, $quoted ) as [ $type, $value ] ) {
			if ( [] !== $name ) {
				if ( 'punct' === $type && '.' === $value && ! $dot ) {
					$dot = true;
					continue;
				}
				if ( $dot && ( 'id' === $type || 'word' === $type || 'num' === $type || '*' === $value ) ) {
					$name[] = $value;
					$dot    = false;
					continue;
				}
				$found = $this->name( $name, $bare, $var, 'punct' === $type && '(' === $value, $current );
				if ( null !== $found ) {
					return $found;
				}
				$name = [];
				$dot  = false;
			}
			$this->positions->follow( $type, $value );
			if ( 'id' === $type || 'word' === $type ) {
				$name = [ $value ];
				$bare = 'word' === $type;
				$var  = $at; // @var.name and @@scope.variable are variables.
				$at   = false;
				continue;
			}
			$at = 'punct' === $type && '@' === $value;
			$this->positions->punctuation( $type, $value );
			if ( $this->positions->unresolved() ) {
				return '';
			}
		}

		return $this->verdict( [] === $name ? null : $this->name( $name, $bare, $var, false, $current ) );
	}

	/**
	 * After the last token: an unfinished statement cannot be checked, and a
	 * qualifier never declared as a table or an alias is a database.
	 *
	 * @param string|null $found A database the last name started with.
	 */
	private function verdict( ?string $found ): ?string {
		if ( ! $this->positions->finish() || null !== $found ) {
			return $found ?? '';
		}
		foreach ( $this->qualifiers as $qualifier ) {
			if ( ! $this->positions->declares( $qualifier ) ) {
				return $qualifier;
			}
		}

		return null;
	}

	/**
	 * A finished name that may start with a database.
	 *
	 * @param array<int, string> $parts   Name parts.
	 * @param bool               $bare    The first part is unquoted.
	 * @param bool               $var     The name follows an @.
	 * @param bool               $called  Followed by an opening parenthesis.
	 * @param string             $current Current database.
	 * @return string|null Another database the name starts with.
	 */
	private function name( array $parts, bool $bare, bool $var, bool $called, string $current ): ?string {
		$count = count( $parts );
		$table = $this->positions->name( $parts, $bare, $called );
		if ( 1 === $count || $var || $parts[0] === $current ) {
			return null;
		}
		if ( $count > 2 || $table || $called ) {
			return $parts[0];
		}
		$this->qualifiers[ strtolower( $parts[0] ) ] ??= $parts[0];
		if ( count( $this->qualifiers ) > self::MAX_NAMES ) {
			$this->positions->fail();
		}

		return null;
	}
}
