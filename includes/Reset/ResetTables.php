<?php
/**
 * Which tables a database reset replaces, retires and keeps.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\OrphanTables;
use LightweightPlugins\Migration\Database\TableLister;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D14 (user ruling U2): every base table with this site's prefix is
 * replaced (a core table) or retired (anything else), except the tables of
 * another installation that shares the database under a longer prefix
 * (<base><x>, when both <base><x>options and <base><x>posts exist) and the
 * temporary tables of our runs. Views are never touched, only named.
 *
 * Names are compared the way the server compares them: exact when table
 * names are case-sensitive (lower_case_table_names = 0), otherwise ASCII
 * lower-case. The server's LIKE listing only narrows the list; a name it
 * returns that does not start with the exact prefix under that rule (a
 * collation look-alike: full-width letters, another space) is left alone.
 * The core list comes from WordPress ($wpdb->tables()), never from SQL.
 */
final class ResetTables {

	/** Retired tables the confirmation names; the rest are counted. */
	private const NAMED = 10;

	/**
	 * @param Connection         $db           Site database.
	 * @param string             $base         Site table prefix.
	 * @param array<int, string> $coreSuffixes Core table names without prefix.
	 * @return array{core: array<int, string>, retire: array<int, string>, kept: array<int, string>, views: array<int, string>} core = suffixes whose live table exists; the rest are full table names.
	 */
	public static function plan( Connection $db, string $base, array $coreSuffixes ): array {
		$fold   = '0' === (string) $db->variable( 'lower_case_table_names' ) ? 'strval' : 'strtolower';
		$listed = ( new TableLister( $db ) )->list( [ $base ] );
		$tables = self::ours( $listed['tables'], $base, $fold );
		$byName = array_flip( array_map( $fold, $tables ) );
		$core   = [];
		$names  = [];
		foreach ( array_values( array_unique( $coreSuffixes ) ) as $suffix ) {
			if ( isset( $byName[ $fold( $base . $suffix ) ] ) ) {
				$core[]                            = $suffix;
				$names[ $fold( $base . $suffix ) ] = true;
			}
		}
		$foreign = self::foreignPrefixes( $tables, $base, $byName, $fold );
		$retire  = [];
		$kept    = [];
		foreach ( $tables as $table ) {
			if ( isset( $names[ $fold( $table ) ] ) ) {
				continue;
			}
			if ( self::startsWithAny( $fold( $table ), $foreign ) ) {
				$kept[] = $table;
			} else {
				$retire[] = $table;
			}
		}
		sort( $retire );
		sort( $kept );
		$views = self::ours( $listed['views'], $base, $fold );
		sort( $views );

		return [
			'core'   => $core,
			'retire' => $retire,
			'kept'   => $kept,
			'views'  => $views,
		];
	}

	/**
	 * The core tables a fresh install builds: the lists wpdb declares, never
	 * its runtime list (plugins append their own tables to $wpdb->tables, and
	 * those get no fresh twin). Single site only (decision D2).
	 *
	 * @param string $class The database class (wpdb).
	 * @return array<int, string> Table names without prefix.
	 */
	public static function coreSuffixes( string $class = 'wpdb' ): array {
		$vars = get_class_vars( $class );

		return array_values( array_map( 'strval', array_merge( (array) ( $vars['global_tables'] ?? [] ), (array) ( $vars['tables'] ?? [] ) ) ) );
	}

	/**
	 * A prefix the database reset refuses: empty (every table of the database
	 * would be the site's), or one some name of our runs' temporary tables
	 * (OrphanTables::PATTERN) starts with — the plan leaves those out, so the
	 * site's own tables could never be replaced or retired.
	 *
	 * @param string $base Site table prefix.
	 */
	public static function unsafePrefix( string $base ): bool {
		return '' === $base || 1 === preg_match( '/^l(?:w(?:[toxy](?:[a-z0-9]{0,4}|[a-z0-9]{4}_.*))?)?\z/is', $base );
	}

	/**
	 * Ruling I7: what the confirmation names (ResetSummary.types.database.tables).
	 *
	 * @param array{core: array<int, string>, retire: array<int, string>, kept: array<int, string>, views: array<int, string>} $plan plan().
	 * @return array{replaced: int, retired: int, kept: int, retiredNames: array<int, string>, more: int}
	 */
	public static function named( array $plan ): array {
		$retire = array_values( $plan['retire'] );

		return [
			'replaced'     => count( $plan['core'] ),
			'retired'      => count( $retire ),
			'kept'         => count( $plan['kept'] ),
			'retiredNames' => array_slice( $retire, 0, self::NAMED ),
			'more'         => max( 0, count( $retire ) - self::NAMED ),
		];
	}

	/**
	 * Ruling I7: a prefix without an underscore at its end also matches other
	 * software's tables that merely start with it.
	 *
	 * @param string $base Site table prefix.
	 */
	public static function prefixWarning( string $base ): bool {
		return ! str_ends_with( $base, '_' );
	}

	/**
	 * The listed names that start with the exact prefix under the server's
	 * rule, without our runs' temporary tables.
	 *
	 * @param array<int, string> $names Listed names.
	 * @param string             $base  Site table prefix.
	 * @param callable           $fold  strval or strtolower.
	 * @return array<int, string>
	 */
	private static function ours( array $names, string $base, callable $fold ): array {
		$prefix = $fold( $base );

		return array_values( array_filter( $names, static fn( string $t ): bool => str_starts_with( $fold( $t ), $prefix ) && 1 !== preg_match( OrphanTables::PATTERN, $t ) ) );
	}

	/**
	 * Q = <base><x> (Q ≠ base) is another installation when Q.options and Q.posts both exist.
	 *
	 * @param array<int, string> $tables Our tables.
	 * @param string             $base   Site table prefix.
	 * @param array<string, int> $byName Folded name => index.
	 * @param callable           $fold   strval or strtolower.
	 * @return array<int, string> Folded prefixes.
	 */
	private static function foreignPrefixes( array $tables, string $base, array $byName, callable $fold ): array {
		$prefixes = [];
		$options  = $fold( $base . 'options' );
		foreach ( $tables as $table ) {
			$name = $fold( $table );
			if ( str_ends_with( $name, 'options' ) && strlen( $name ) > strlen( $options ) ) {
				$q = substr( $name, 0, -strlen( 'options' ) );
				if ( isset( $byName[ $q . 'posts' ] ) ) {
					$prefixes[] = $q;
				}
			}
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * @param string             $name     Folded table name.
	 * @param array<int, string> $prefixes Folded prefixes.
	 */
	private static function startsWithAny( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
