<?php
/**
 * Temporary import tables nobody owns any more.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Drops lwtXXXX_ / lwxXXXX_ / lwoXXXX_ / lwyXXXX_ tables and views (the
 * temporary and replaced tables of TableSwap) of runs this site registered
 * that no kept prefix covers. Only names matching the exact lower-case shape
 * TableSwap creates are touched; anything starting with the site's own prefix
 * (in any case) is never dropped. Each drop is on its own: one that fails (a
 * foreign key still points at the table) is tried once more, then reported.
 */
final class OrphanTables {

	public const PATTERN = '/^lw[toxy][a-z0-9]{4}_/i';

	private const FAMILIES = [ 'lwt', 'lwx', 'lwo', 'lwy' ];

	/**
	 * Every table prefix of one import run.
	 *
	 * @param string $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @return array<int, string> lwt, lwx, lwo and lwy prefixes.
	 */
	public static function runPrefixes( string $tmpPrefix ): array {
		return array_map( static fn( string $f ): string => $f . substr( $tmpPrefix, 3 ), self::FAMILIES );
	}

	/**
	 * The prefixes of the replaced live tables: the only undo path after a swap.
	 *
	 * @param string $tmpPrefix Temporary prefix (lwtXXXX_).
	 * @return array<int, string> lwo and lwy prefixes.
	 */
	public static function undoPrefixes( string $tmpPrefix ): array {
		return array_slice( self::runPrefixes( $tmpPrefix ), 2 );
	}

	/**
	 * The run id of a temporary prefix or table name (lwtXXXX_… → XXXX).
	 *
	 * @param string $name Prefix or table name.
	 */
	public static function runId( string $name ): string {
		return strtolower( substr( $name, 3, 4 ) );
	}

	/**
	 * Only runs this site registered are touched; tables of any other run
	 * (another site sharing the database) are reported, never dropped. Which
	 * prefixes a run keeps is asked right before its tables are dropped, after
	 * the listing, so a job that appeared meanwhile keeps its tables.
	 *
	 * @param Connection         $db         Database.
	 * @param array<int, string> $registered Run ids this site created.
	 * @param \Closure           $kept       fn( string $runId ): array<int, string>, the exact prefixes of that run still owned.
	 * @param string             $sitePrefix The site's table prefix.
	 * @return array{dropped: array<int, string>, failed: array<string, string>, unregistered: array<int, string>, cleared: array<int, string>}
	 *         Dropped names; name => error of the ones kept; tables of unknown runs;
	 *         registered runs that have no table left (but their RunMarker, which
	 *         is never dropped here) and keep none.
	 * @throws QueryException When the tables cannot be listed.
	 */
	public static function sweep( Connection $db, array $registered, \Closure $kept, string $sitePrefix ): array {
		$runs   = self::listRuns( $db, $sitePrefix );
		$result = [
			'dropped'      => [],
			'failed'       => [],
			'unregistered' => [],
			'cleared'      => [],
		];
		foreach ( $runs as $run => $objects ) {
			if ( ! in_array( (string) $run, $registered, true ) ) {
				$result['unregistered'] = array_merge( $result['unregistered'], array_keys( $objects ) );
			}
		}
		foreach ( $registered as $run ) {
			$keep                 = isset( $runs[ $run ] ) ? (array) $kept( $run ) : [];
			$queue                = array_filter( $runs[ $run ] ?? [], static fn( string $kind, string $name ): bool => ! RunMarker::is( $name ) && ! self::startsWithAny( $name, $keep ), ARRAY_FILTER_USE_BOTH );
			[ $dropped, $failed ] = self::dropAll( $db, $queue );
			$result['dropped']    = array_merge( $result['dropped'], $dropped );
			$result['failed']    += $failed;
			if ( [] === $keep && [] === $failed ) {
				$result['cleared'][] = $run;
			}
		}
		sort( $result['unregistered'] );

		return $result;
	}

	/**
	 * @param Connection $db         Database.
	 * @param string     $sitePrefix The site's table prefix.
	 * @return array<string, array<string, string>> Run id => (name => TABLE|VIEW), our shape only, never the site's prefix.
	 */
	private static function listRuns( Connection $db, string $sitePrefix ): array {
		$listed = ( new TableLister( $db ) )->list( [] ); // Every name: PATTERN also finds a run's tables in another case.
		$kinds  = [
			'VIEW'  => $listed['views'],
			'TABLE' => $listed['tables'],
		];
		$runs   = [];
		foreach ( $kinds as $kind => $names ) {
			foreach ( $names as $name ) {
				if ( 1 === preg_match( self::PATTERN, $name ) && ! self::startsWithAny( $name, [ $sitePrefix ] ) ) {
					$runs[ self::runId( $name ) ][ $name ] = $kind;
				}
			}
		}

		return $runs;
	}

	/**
	 * Each drop on its own; the ones that fail get one more pass (a parent of
	 * another orphan drops after its child).
	 *
	 * @param Connection            $db    Database.
	 * @param array<string, string> $queue Name => TABLE|VIEW.
	 * @return array{0: array<int, string>, 1: array<string, string>} Dropped; name => error.
	 */
	private static function dropAll( Connection $db, array $queue ): array {
		$dropped = [];
		$failed  = [];
		for ( $pass = 0; $pass < 2 && [] !== $queue; $pass++ ) {
			$failed = [];
			foreach ( $queue as $name => $kind ) {
				try {
					$db->execute( 'DROP ' . $kind . ' IF EXISTS ' . DumpFormat::quoteIdentifier( (string) $name ) );
					$dropped[] = (string) $name;
					unset( $queue[ $name ] );
				} catch ( QueryException $e ) {
					$failed[ (string) $name ] = $e->errno() . ': ' . ServerError::describe( $e->errno() );
				}
			}
		}

		return [ $dropped, $failed ];
	}

	/**
	 * Case-insensitive (names may fold on this server): errs on keeping.
	 *
	 * @param string             $name     Table or view name.
	 * @param array<int, string> $prefixes Prefixes.
	 */
	private static function startsWithAny( string $name, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && 0 === stripos( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
