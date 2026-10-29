<?php
/**
 * Import step: swaps the imported tables in.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\SessionMode;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\TmpLinkOwners;
use LightweightPlugins\Migration\Import\TmpTables;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * One RENAME TABLE replaces the live tables. Right before it the site lock is
 * refreshed (a job that lost it fails, nothing is swapped) and the lock row and
 * our own options are copied into the temporary options table again, so the
 * site goes live with the current lock, never a stale one. A marker file
 * written before the RENAME lets a replay after a request died tell "already
 * swapped" (temporary tables gone, marker present: the flag is set first, the
 * lock is still required to go on) from "tables missing". The
 * object cache is flushed in this request right away (it still holds the old
 * options), and the step returns "more" so the swapped flag is saved before
 * the leftover tables are dropped on the next call. On a network the held
 * staging tables of a subsite import are neither pending nor swapped nor
 * dropped here, only the mapped sites' tables are swapped in, and the final
 * table names are saved before the RENAME.
 */
final class SwapStep implements StepInterface {

	public const KEY = 'swap';

	/** Cursor flag: the deferred views were created (ImportCleanup reads it). */
	public const DEFERRED = 'deferred';

	/** Cursor flag: the replaced tables were removed (ImportCleanup reads it). */
	public const CLEANED = 'cleaned';

	/**
	 * @param JobStore $store       Job store.
	 * @param \Closure $connect     fn(): Connection.
	 * @param \Closure $flushCaches fn(): void.
	 * @param SiteLock $lock        Site lock.
	 * @param \Closure $now         fn(): int, Unix time.
	 * @param \Closure $forget      fn( string $runId ): bool, removes the run from the site's registry once nothing of it is left.
	 */
	public function __construct( private JobStore $store, private \Closure $connect, private \Closure $flushCaches, private SiteLock $lock, private \Closure $now, private \Closure $forget ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! ImportState::hasDatabase( $job ) ) {
			return StepResult::done();
		}
		$db   = ( $this->connect )();
		$base = ImportState::site( $job )->tablePrefix;
		$tmp  = (string) ( $job->state['tmpPrefix'] ?? '' );
		$swap = new TableSwap( $db, $tmp, $base, ImportPlan::DEFAULT_EXTRA_PREFIXES, ImportState::heldSuffixes( $job ), ImportState::siteSuffixes( $job ) );
		if ( ! empty( $job->state['swapped'] ) ) {
			self::warn( $job, $swap->cleanup() );
			self::release( $db, $tmp, $this->forget );
			$job->cursor[ self::CLEANED ] = true;

			return StepResult::done( __( 'The imported database is now live.', 'lw-migration' ) );
		}
		$dir     = WorkDir::path( $this->store, $job );
		$marker  = $dir . '/' . ImportFiles::SWAP_STARTED;
		$pending = [] !== $swap->pendingTables();
		if ( ! $pending && is_file( $marker ) ) {
			// The RENAME ran in a request that died before the job was saved.
			self::markSwapped( $job );
			if ( ! $this->lock->refresh( $job->id, ( $this->now )() ) ) {
				throw new ImportException( __( 'The imported database is already live, but this import lost the site lock before it could finish. The remaining import steps did not run.', 'lw-migration' ) );
			}
			$warnings = [];
		} else {
			$this->holdLock( $job );
			if ( ! $pending ) {
				throw new ImportException( __( 'The temporary tables of this import are missing, so the imported database cannot be swapped in. The site was not changed.', 'lw-migration' ) );
			}
			$this->recordFinals( $job, $swap );
			$warnings = $this->swapIn( $db, $swap, $tmp, $base, $marker, (array) ( $job->state['keptAdmin'] ?? [] ) );
			self::markSwapped( $job );
			// The live lock row is now the copy made before the RENAME; refresh it once
			// more. A lock lost after this point is caught by the driver's next refresh.
			$this->lock->refresh( $job->id, ( $this->now )() );
		}
		( $this->flushCaches )();
		self::warn( $job, array_merge( $warnings, $swap->applyDeferred( $dir . '/' . ImportFiles::DEFERRED ) ) );
		$job->cursor[ self::DEFERRED ] = true;

		return StepResult::more( 90, __( 'The imported database is now live.', 'lw-migration' ) );
	}

	/**
	 * Every replaced table is gone: the run is forgotten and its id freed. A
	 * database error leaves that to the daily sweep (the swap succeeded).
	 *
	 * @param Connection $db     Site database.
	 * @param string     $tmp    Temporary prefix.
	 * @param \Closure   $forget fn( string $runId ): bool.
	 */
	private static function release( Connection $db, string $tmp, \Closure $forget ): void {
		try {
			RunMarker::releaseIfUnused( $db, $tmp, $forget );
		} catch ( QueryException $e ) {
			unset( $e );
		}
	}

	/**
	 * The swapped flag and a new cursor phase go together, so the cleanup call
	 * has its own attempt fingerprint.
	 *
	 * @param Job $job Import job.
	 */
	private static function markSwapped( Job $job ): void {
		$job->state['swapped'] = true;
		$job->cursor           = [ 'phase' => 'cleanup' ];
	}

	/**
	 * @param Job $job Import job.
	 * @throws ImportException When another job holds the site lock (or it is gone).
	 */
	private function holdLock( Job $job ): void {
		if ( ! $this->lock->refresh( $job->id, ( $this->now )() ) ) {
			throw new ImportException( __( 'This import lost the site lock, so the imported database was not swapped in. The site was not changed.', 'lw-migration' ) );
		}
	}

	/**
	 * Copies the current lock row and our options into the temporary options,
	 * points the kept admin's incoming links at its id there (TmpLinkOwners),
	 * writes the marker, then swaps. No transaction is open here (TmpTables
	 * starts its own), and the copy runs with an empty sql_mode.
	 *
	 * @param Connection           $db     Site database.
	 * @param TableSwap            $swap   Swap of this import.
	 * @param string               $tmp    Temporary prefix.
	 * @param string               $base   Site prefix.
	 * @param string               $marker Marker file.
	 * @param array<string, mixed> $kept   {from, id, login} of the admin PreserveStep kept ([] = none).
	 * @return array<int, string> Technical warnings of the swap.
	 * @throws ImportException When the marker cannot be written.
	 */
	private function swapIn( Connection $db, TableSwap $swap, string $tmp, string $base, string $marker, array $kept ): array {
		SessionMode::relaxed(
			$db,
			static function () use ( $db, $tmp, $base, $kept ): void {
				$tables = new TmpTables( $db, $tmp, $base );
				if ( $tables->has( 'options' ) ) {
					$tables->copyOptionPrefixes( Options::DUMP_EXCLUDED_PREFIXES, Options::NOT_CARRIED );
					if ( is_int( $kept['from'] ?? null ) && is_int( $kept['id'] ?? null ) && is_string( $kept['login'] ?? null ) ) {
						( new TmpLinkOwners( $db, $tmp ) )->move( $kept['from'], $kept['login'], $kept['id'] );
					}
				}
			}
		);
		Files::privateFile( $marker );
		if ( ! is_file( $marker ) ) {
			throw new ImportException( __( 'The job folder cannot be written, so the imported database was not swapped in. The site was not changed.', 'lw-migration' ) );
		}

		return $swap->swap()->warnings;
	}

	/**
	 * On a network the final names the swap brings in are saved before the
	 * RENAME (ruling R5: the user merge renumbers only these), so a replay
	 * after a request died still knows them.
	 *
	 * @param Job       $job  Import job.
	 * @param TableSwap $swap Swap of this import.
	 */
	private function recordFinals( Job $job, TableSwap $swap ): void {
		if ( ! ImportState::onNetwork( $job ) ) {
			return;
		}
		$job->state['swappedTables'] = $swap->plannedFinals();
		$this->store->save( $job );
	}

	/**
	 * @param Job                $job      Import job.
	 * @param array<int, string> $warnings Technical warnings (table names only, no paths).
	 */
	private static function warn( Job $job, array $warnings ): void {
		foreach ( $warnings as $warning ) {
			/* translators: %s: technical message from the database */
			$job->addWarning( sprintf( __( 'Database note after the swap: %s', 'lw-migration' ), $warning ) );
		}
	}
}
