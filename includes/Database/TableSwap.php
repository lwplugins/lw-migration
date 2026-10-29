<?php
/**
 * Moves imported temporary tables into place in one atomic statement.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

use LightweightPlugins\Migration\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * RENAME TABLE live → old, tmp → live (all in one statement), then drop old.
 * Live tables the archive does not contain are left alone.
 *
 * Held temporary tables (a subsite import's staged network tables,
 * lwtXXXX_<held suffix>…) are never swapped in nor dropped by cleanup(), and
 * never count as pending; dropHeld() removes them. With site suffixes (a sites
 * or single-site restore into a network) only <tmp><site suffix>… tables are
 * swapped in and only <base><site suffix>… views created; any other temporary
 * table of the run is left to cleanup().
 *
 * Known limitation: live tables outside the archive that have foreign keys to
 * a replaced table keep pointing at the old (renamed) table; that old table
 * then cannot be dropped and stays, reported as a warning.
 */
final class TableSwap {

	/** Bytes of a warning at most. */
	private const WARNING_BYTES = 400;

	/**
	 * Allow-list for deferred view statements (defence in depth).
	 *
	 * @var ImportScope
	 */
	private ImportScope $scope;

	/**
	 * Initialize the swap.
	 *
	 * @param Connection         $db            Database connection.
	 * @param string             $tmpPrefix     Temporary table prefix (lwtXXXX_).
	 * @param string             $basePrefix    Final table prefix.
	 * @param array<int, string> $extraPrefixes Prefixes of non-site tables imported under lwxXXXX_ (wbk_, civicrm_).
	 * @param array<int, string> $held          Suffixes after the temporary prefix whose tables stay where they are (lwmsrc_).
	 * @param array<int, string> $only          A sites/single restore into a network: the only suffixes ("<newId>_") swapped in and allowed for views; [] = every unheld table.
	 *
	 * @throws DatabaseException When a prefix or suffix has the wrong shape.
	 */
	public function __construct( private Connection $db, private string $tmpPrefix, private string $basePrefix, private array $extraPrefixes = ImportPlan::DEFAULT_EXTRA_PREFIXES, private array $held = [], private array $only = [] ) {
		PrefixRules::assertImportPrefixes( $tmpPrefix, $extraPrefixes );
		foreach ( array_merge( $held, $only ) as $suffix ) {
			if ( 1 !== preg_match( '/^[a-z0-9]+_\z/', $suffix ) ) {
				throw new DatabaseException( 'Invalid held or site table suffix.' );
			}
		}
		$this->scope = new ImportScope( $basePrefix, $tmpPrefix, '', $only );
	}

	public static function newTempPrefix(): string {
		return 'lwt' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 ) . '_';
	}

	public function oldPrefix(): string {
		return 'lwo' . substr( $this->tmpPrefix, 3 );
	}

	/** Prefix of extra-prefix tables while imported. */
	public function extraTmpPrefix(): string {
		return PrefixRules::extraTempPrefix( $this->tmpPrefix );
	}

	/** Prefix replaced live extra-prefix tables get until they are dropped. */
	public function extraOldPrefix(): string {
		return 'lwy' . substr( $this->tmpPrefix, 3 );
	}

	/**
	 * @param string $prefix Exact, case-sensitive prefix.
	 * @return array<int, string>
	 */
	public function tablesWithPrefix( string $prefix ): array {
		return self::startingWith( $this->baseTables(), $prefix );
	}

	/**
	 * The temporary tables swap() would rename (held ones never are).
	 *
	 * @return array<int, string>
	 */
	public function pendingTables(): array {
		$all = $this->baseTables();

		return array_merge( $this->swappable( self::startingWith( $all, $this->tmpPrefix ) ), $this->extraTables( $all ) );
	}

	/**
	 * The final names swap() would give the pending tables right now.
	 *
	 * @return array<int, string>
	 */
	public function plannedFinals(): array {
		return $this->plan()['finals'];
	}

	/**
	 * Swaps the tables in with one RENAME. Once it has succeeded this never
	 * throws: replaced tables that cannot be dropped are kept and reported.
	 * lwtXXXX_<suffix> becomes <base><suffix>; lwxXXXX_<name> becomes <name>
	 * when <name> starts with an extra prefix (else it is skipped).
	 *
	 * @return SwapResult
	 */
	public function swap(): SwapResult {
		$plan = $this->plan();
		if ( [] === $plan['renames'] ) {
			return new SwapResult( [], $plan['warnings'] );
		}
		$this->db->execute( 'RENAME TABLE ' . implode( ', ', $plan['renames'] ) );

		return new SwapResult( $plan['finals'], array_merge( $plan['warnings'], $this->dropEach( $plan['olds'] ) ) );
	}

	/**
	 * @return array{renames: array<int, string>, olds: array<int, string>, finals: array<int, string>, warnings: array<int, string>}
	 */
	private function plan(): array {
		$all      = $this->baseTables();
		$fold     = '0' === $this->db->variable( 'lower_case_table_names' ) ? 'strval' : 'strtolower';
		$existing = array_flip( array_map( $fold, $all ) );
		$plan     = [
			'renames'  => [],
			'olds'     => [],
			'finals'   => [],
			'warnings' => [],
		];
		foreach ( $this->swappable( self::startingWith( $all, $this->tmpPrefix ) ) as $table ) {
			$suffix = substr( $table, strlen( $this->tmpPrefix ) );
			$this->planRename( $plan, $table, $this->basePrefix . $suffix, $this->oldPrefix() . $suffix, $existing, $fold );
		}
		foreach ( $this->extraTables( $all ) as $table ) {
			$name = substr( $table, strlen( $this->extraTmpPrefix() ) );
			if ( ! $this->isExtraName( $name ) ) {
				$plan['warnings'][] = sprintf( 'Imported table %s was not swapped in: %s does not start with an allowed table prefix.', $table, $name );
				continue;
			}
			$this->planRename( $plan, $table, $name, $this->extraOldPrefix() . $name, $existing, $fold );
		}

		return $plan;
	}

	/**
	 * Adds "live → old" (when the live table exists) and "temp → live" to the plan.
	 *
	 * @param array{renames: array<int, string>, olds: array<int, string>, finals: array<int, string>, warnings: array<int, string>} $plan     Rename plan.
	 * @param string                                                                                                                 $table    Temporary table.
	 * @param string                                                                                                                 $final    Final name.
	 * @param string                                                                                                                 $old      Name for the replaced live table.
	 * @param array<string, int>                                                                                                     $existing Folded live names.
	 * @param callable(string): string                                                                                               $fold     Name folding.
	 * @return void
	 */
	private function planRename( array &$plan, string $table, string $final, string $old, array $existing, callable $fold ): void {
		if ( isset( $existing[ $fold( $final ) ] ) ) {
			$plan['renames'][] = DumpFormat::quoteIdentifier( $final ) . ' TO ' . DumpFormat::quoteIdentifier( $old );
			$plan['olds'][]    = $old;
		}
		$plan['renames'][] = DumpFormat::quoteIdentifier( $table ) . ' TO ' . DumpFormat::quoteIdentifier( $final );
		$plan['finals'][]  = $final;
	}

	private function isExtraName( string $name ): bool {
		foreach ( $this->extraPrefixes as $prefix ) {
			if ( str_starts_with( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates the deferred views (DeferredViews: checked session, final-prefix
	 * views of this site only, none that reads another database).
	 *
	 * @param string $path Deferred statements file.
	 * @return array<int, string> Warnings.
	 */
	public function applyDeferred( string $path ): array {
		return ( new DeferredViews( $this->db, $this->scope ) )->apply( $path );
	}

	/**
	 * Drops the held temporary tables of this run, one by one.
	 *
	 * @return array<int, string> Warnings for tables that could not be dropped.
	 */
	public function dropHeld(): array {
		$tmp = self::startingWith( $this->baseTables(), $this->tmpPrefix );

		return $this->dropEach( array_values( array_diff( $tmp, $this->unheld( $tmp ) ) ) );
	}

	/**
	 * Drops leftover temporary and replaced tables (lwt/lwx/lwo/lwy of this run, held ones excepted), one by one.
	 *
	 * @return array<int, string> Warnings for tables that could not be dropped.
	 */
	public function cleanup(): array {
		$all = $this->baseTables();

		return $this->dropEach(
			array_merge(
				$this->unheld( self::startingWith( $all, $this->tmpPrefix ) ),
				self::startingWith( $all, $this->extraTmpPrefix() ),
				self::startingWith( $all, $this->oldPrefix() ),
				self::startingWith( $all, $this->extraOldPrefix() )
			)
		);
	}

	/**
	 * @param array<int, string> $tables Table names.
	 * @return array<int, string> Warnings.
	 */
	private function dropEach( array $tables ): array {
		$warnings = [];
		foreach ( $tables as $table ) {
			try {
				$this->db->execute( 'DROP TABLE IF EXISTS ' . DumpFormat::quoteIdentifier( $table ) );
			} catch ( QueryException $e ) {
				$warnings[] = Text::clip(
					sprintf(
						'Could not drop the replaced table %1$s (%2$d): %3$s — it was kept as %1$s.',
						$table,
						$e->errno(),
						ServerError::describe( $e->errno() )
					),
					self::WARNING_BYTES
				);
			}
		}

		return $warnings;
	}

	/**
	 * @param array<int, string> $tables Table names.
	 * @param string             $prefix Exact prefix.
	 * @return array<int, string>
	 */
	private static function startingWith( array $tables, string $prefix ): array {
		return array_values( array_filter( $tables, static fn( string $t ): bool => str_starts_with( $t, $prefix ) ) );
	}

	/**
	 * @param array<int, string> $tables Temporary tables of this run.
	 * @return array<int, string> The unheld ones swap() may rename: with site suffixes only those of the mapped sites.
	 */
	private function swappable( array $tables ): array {
		$out = [];
		foreach ( $this->unheld( $tables ) as $table ) {
			$allowed = [] === $this->only;
			foreach ( $this->only as $suffix ) {
				$allowed = $allowed || str_starts_with( $table, $this->tmpPrefix . $suffix );
			}
			if ( $allowed ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, string> $all Base tables.
	 * @return array<int, string> Extra-prefix temporary tables (none for a sites/single restore).
	 */
	private function extraTables( array $all ): array {
		return [] === $this->only ? self::startingWith( $all, $this->extraTmpPrefix() ) : [];
	}

	/**
	 * @param array<int, string> $tables Temporary tables of this run.
	 * @return array<int, string> The ones no held suffix covers.
	 */
	private function unheld( array $tables ): array {
		$out = [];
		foreach ( $tables as $table ) {
			$held = false;
			foreach ( $this->held as $suffix ) {
				$held = $held || str_starts_with( $table, $this->tmpPrefix . $suffix );
			}
			if ( ! $held ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function baseTables(): array {
		$names = [];
		foreach ( $this->db->query( "SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'" ) as $row ) {
			$name = (string) array_values( $row )[0];
			if ( ! RunMarker::is( $name ) ) {
				$names[] = $name; // The run's reservation is never swapped, renamed or dropped here.
			}
		}

		return $names;
	}
}
