<?php
/**
 * The one RENAME of a database reset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

/**
 * One RENAME TABLE: each live core table to lwoXXXX_<suffix>, each fresh
 * lwtXXXX_<suffix> to <base><suffix>, each retired table to lwoXXXX_r<n>
 * (short names: a 64-character table never overflows). Only the run's own
 * prefixes (lwt/lwo of a valid lwtXXXX_ prefix, which the site's prefix may
 * not start with in any case) are ever dropped, and the
 * run's marker (lwyXXXX_lwm_run) is never touched here. Table names are
 * compared the way the server compares them (lower_case_table_names).
 */
final class ResetSwap {

	/** Seconds the RENAME waits for a table another session holds before it gives up (ruling m8). */
	public const LOCK_WAIT = 5;

	/** MySQL/MariaDB ER_LOCK_WAIT_TIMEOUT. */
	private const LOCK_WAIT_TIMEOUT = 1205;

	/** @var callable strval or strtolower, by the server's table-name rule. */
	private $fold;

	/**
	 * @param Connection $db   Site database.
	 * @param string     $tmp  The run's temporary prefix (lwtXXXX_).
	 * @param string     $base Site table prefix.
	 * @param int        $lockWait Seconds the RENAME waits for a lock.
	 * @throws DatabaseException When a prefix could address the wrong tables.
	 */
	public function __construct( private Connection $db, private string $tmp, private string $base, private int $lockWait = self::LOCK_WAIT ) {
		if ( ! PrefixRules::isValidTempPrefix( $tmp ) ) {
			throw new DatabaseException( 'Invalid temporary table prefix.' );
		}
		$run = substr( $tmp, 3 );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $base ) || ResetTables::unsafePrefix( $base ) || PrefixRules::isValidTempPrefix( $base ) || 0 === stripos( $base, 'lwt' . $run ) || 0 === stripos( $base, 'lwo' . $run ) ) {
			throw new DatabaseException( 'Invalid site table prefix for a reset.' );
		}
		$this->fold = '0' === (string) $db->variable( 'lower_case_table_names' ) ? 'strval' : 'strtolower';
	}

	/**
	 * For the steps: a prefix this swap refuses becomes the job's readable failure.
	 *
	 * @param Connection $db   Site database.
	 * @param string     $tmp  The run's temporary prefix.
	 * @param string     $base Site table prefix.
	 * @throws JobException When a prefix cannot be used.
	 * @throws QueryException When the server cannot be asked.
	 */
	public static function forJob( Connection $db, string $tmp, string $base ): self {
		try {
			return new self( $db, $tmp, $base );
		} catch ( QueryException $e ) {
			throw $e;
		} catch ( DatabaseException $e ) {
			throw new JobException( __( 'This site\'s table prefix cannot be used for a database reset, so the database was not reset. The site was not changed.', 'lw-migration' ) );
		}
	}

	/** Where the replaced and retired tables wait until they are dropped. */
	public function oldPrefix(): string {
		return 'lwo' . substr( $this->tmp, 3 );
	}

	/**
	 * @param array<int, string> $coreSuffixes Core table names without prefix.
	 * @return array<int, string> The suffixes whose fresh table waits beside the live one.
	 */
	public function pending( array $coreSuffixes ): array {
		$all = $this->folded( $this->tables() );

		return array_values( array_filter( array_unique( $coreSuffixes ), fn( string $s ): bool => isset( $all[ ( $this->fold )( $this->tmp . $s ) ] ) ) );
	}

	/**
	 * Ruling m4: every table the fresh install built, by suffix (recorded
	 * right after the build as state.built).
	 *
	 * @return array<int, string>
	 */
	public function freshSuffixes(): array {
		$suffixes = array_map( fn( string $t ): string => substr( $t, strlen( $this->tmp ) ), $this->startingWith( $this->tables(), $this->tmp ) );
		sort( $suffixes );

		return $suffixes;
	}

	/**
	 * Ruling I8: what would make the RENAME fail, checked before the swap
	 * marker is written.
	 *
	 * @param array<int, string> $fresh  Suffixes of the fresh tables the build recorded (each must still be there).
	 * @param array<int, string> $retire Full names of the tables to retire.
	 * @param array<int, string> $core   Core tables every record must hold (a missing one would retire the live table).
	 * @return string|null Null when it can run, else the name in the way.
	 */
	public function check( array $fresh, array $retire, array $core = [] ): ?string {
		$missing = array_values( array_diff( $core, $fresh ) );
		if ( [] !== $missing ) {
			return $this->tmp . $missing[0];
		}
		$tables = $this->folded( $this->tables() );
		$views  = $this->folded( $this->views() );
		foreach ( $fresh as $suffix ) {
			if ( ! isset( $tables[ ( $this->fold )( $this->tmp . $suffix ) ] ) ) {
				return $this->tmp . $suffix;
			}
			if ( isset( $views[ ( $this->fold )( $this->base . $suffix ) ] ) ) {
				return $this->base . $suffix;
			}
		}
		foreach ( $retire as $table ) {
			if ( ! isset( $tables[ ( $this->fold )( $table ) ] ) ) {
				return $table;
			}
		}
		$old = $this->startingWith( array_merge( $this->tables(), $this->views() ), $this->oldPrefix() );

		return [] === $old ? null : $old[0];
	}

	/**
	 * @param array<int, string> $coreSuffixes The suffixes whose fresh table exists (pending()).
	 * @param array<int, string> $retire       Full names of the tables to retire.
	 * @return array<int, string> Warnings (none: the RENAME either runs whole or throws).
	 * @throws QueryException When the RENAME fails (nothing was renamed; busy() tells a lock wait).
	 */
	public function swap( array $coreSuffixes, array $retire ): array {
		$live    = $this->folded( $this->tables() );
		$renames = [];
		foreach ( $coreSuffixes as $suffix ) {
			if ( isset( $live[ ( $this->fold )( $this->base . $suffix ) ] ) ) {
				$renames[] = self::q( $this->base . $suffix ) . ' TO ' . self::q( $this->oldPrefix() . $suffix );
			}
			$renames[] = self::q( $this->tmp . $suffix ) . ' TO ' . self::q( $this->base . $suffix );
		}
		foreach ( array_values( $retire ) as $n => $table ) {
			$renames[] = self::q( $table ) . ' TO ' . self::q( $this->oldPrefix() . 'r' . $n );
		}
		if ( [] !== $renames ) {
			$this->renameSoon( 'RENAME TABLE ' . implode( ', ', $renames ) );
		}

		return [];
	}

	/**
	 * Whether the RENAME gave up behind another session's lock (nothing was renamed).
	 *
	 * @param \Throwable $e What swap() threw.
	 */
	public static function busy( \Throwable $e ): bool {
		return $e instanceof QueryException && self::LOCK_WAIT_TIMEOUT === $e->errno();
	}

	/**
	 * A RENAME queued behind a long transaction would queue every later query
	 * on those tables behind itself: the site would stall. With a short
	 * session lock_wait_timeout it gives up instead (restored afterwards).
	 *
	 * @param string $rename The RENAME TABLE statement.
	 * @throws QueryException When it fails (ER_LOCK_WAIT_TIMEOUT when it waited too long).
	 */
	private function renameSoon( string $rename ): void {
		$before = (int) ( $this->db->query( 'SELECT @@SESSION.lock_wait_timeout AS t' )[0]['t'] ?? 0 );
		$this->db->execute( 'SET SESSION lock_wait_timeout = ' . max( 1, $this->lockWait ) );
		try {
			$this->db->execute( $rename );
		} finally {
			if ( $before > 0 ) {
				$this->db->execute( 'SET SESSION lock_wait_timeout = ' . $before );
			}
		}
	}

	/**
	 * Drops the replaced and retired tables one by one; a table a foreign key
	 * still holds is tried once more after the others (its child may have
	 * gone), then kept.
	 *
	 * @return array<string, int> Name => server error number of each table that stayed.
	 */
	public function dropOld(): array {
		$failed = $this->dropEach( $this->startingWith( $this->tables(), $this->oldPrefix() ) );
		if ( [] !== $failed ) {
			$failed = $this->dropEach( array_keys( $failed ) );
		}

		return $failed;
	}

	/**
	 * The name a replaced or retired table had on the live site.
	 *
	 * @param string             $old     Its name under oldPrefix().
	 * @param array<int, string> $retired The retired names in RENAME order (state.retired).
	 */
	public function originalName( string $old, array $retired ): string {
		$suffix = substr( $old, strlen( $this->oldPrefix() ) );
		if ( 1 === preg_match( '/^r(\d+)\z/', $suffix, $m ) && isset( array_values( $retired )[ (int) $m[1] ] ) ) {
			return (string) array_values( $retired )[ (int) $m[1] ];
		}

		return $this->base . $suffix;
	}

	/**
	 * Drops the run's fresh tables (lwtXXXX_…): a build that died, or a reset
	 * that never swapped. Never a live table, never the run's marker.
	 *
	 * @throws QueryException When a table cannot be dropped.
	 */
	public function dropFresh(): void {
		foreach ( $this->startingWith( $this->tables(), $this->tmp ) as $table ) {
			$this->db->execute( 'DROP TABLE IF EXISTS ' . self::q( $table ) );
		}
	}

	/**
	 * @param array<int, string> $tables Table names.
	 * @return array<string, int> Name => server error number of each table that could not be dropped.
	 */
	private function dropEach( array $tables ): array {
		$failed = [];
		foreach ( $tables as $table ) {
			try {
				$this->db->execute( 'DROP TABLE IF EXISTS ' . self::q( $table ) );
			} catch ( QueryException $e ) {
				$failed[ $table ] = $e->errno();
			}
		}

		return $failed;
	}

	/**
	 * @param array<int, string> $names  Table names.
	 * @param string             $prefix One of the run's lower-case prefixes.
	 * @return array<int, string> The names that start with it under the server's rule.
	 */
	private function startingWith( array $names, string $prefix ): array {
		$folded = ( $this->fold )( $prefix );

		return array_values( array_filter( $names, fn( string $t ): bool => str_starts_with( ( $this->fold )( $t ), $folded ) ) );
	}

	/**
	 * @param array<int, string> $names Table names.
	 * @return array<string, true> Folded name => true.
	 */
	private function folded( array $names ): array {
		$out = [];
		foreach ( $names as $name ) {
			$out[ ( $this->fold )( $name ) ] = true;
		}

		return $out;
	}

	/** @return array<int, string> Base tables, without any run's marker. */
	private function tables(): array {
		return array_values( array_filter( $this->listed( 'BASE TABLE' ), static fn( string $t ): bool => ! RunMarker::is( $t ) ) );
	}

	/** @return array<int, string> */
	private function views(): array {
		return $this->listed( 'VIEW' );
	}

	/**
	 * @param string $type BASE TABLE or VIEW.
	 * @return array<int, string>
	 */
	private function listed( string $type ): array {
		$names = [];
		foreach ( $this->db->query( "SHOW FULL TABLES WHERE `Table_type` = '{$type}'" ) as $row ) {
			$names[] = (string) array_values( $row )[0];
		}

		return $names;
	}

	private static function q( string $name ): string {
		return DumpFormat::quoteIdentifier( $name );
	}
}
