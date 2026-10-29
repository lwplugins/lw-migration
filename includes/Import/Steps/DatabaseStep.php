<?php
/**
 * Import step: imports database.sql into temporary tables.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\Importer;
use LightweightPlugins\Migration\Database\StatementReader;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Database\TmpRunException;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportPlanFactory;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The live tables are never touched here: everything lands in lwtXXXX_ /
 * lwxXXXX_ tables. The first run only reserves and registers a run (TmpRun).
 * Each call runs Importer::run() exactly once and
 * copies its cursor into the job only after it returned normally; the Runner
 * saves the job before calling again, so committed rows are never replayed
 * and a failed or killed run replays only its own uncommitted part.
 * The connection is built per call (a reconnect replaces the handle).
 */
final class DatabaseStep implements StepInterface {

	/** State key: {prefix, token} of the run id being reserved (saved before the reservation). */
	public const CANDIDATE = TmpRun::CANDIDATE;

	/**
	 * Reserves and registers the run.
	 *
	 * @var TmpRun
	 */
	private TmpRun $run;

	/**
	 * @param JobStore      $store           Job store.
	 * @param \Closure      $connect         fn(): Connection.
	 * @param \Closure      $pluginInstalled fn( string $basename ): bool.
	 * @param \Closure      $themeInstalled  fn( string $slug ): bool.
	 * @param \Closure      $register        fn( string $tmpPrefix ): bool, records the run as this site's (TmpRunRegistry).
	 * @param \Closure|null $newPrefix       fn(): string; default TableSwap::newTempPrefix().
	 */
	public function __construct( private JobStore $store, private \Closure $connect, private \Closure $pluginInstalled, private \Closure $themeInstalled, \Closure $register, ?\Closure $newPrefix = null ) {
		$this->run = new TmpRun( $store, $register, $newPrefix );
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! ImportState::hasDatabase( $job ) ) {
			return StepResult::done();
		}
		$db   = ( $this->connect )();
		$site = ImportState::site( $job );
		if ( empty( $job->state['tmpPrefix'] ) ) {
			$job->state['tmpPrefix'] = $this->reserve( $db, $site->tablePrefix, $job );
			unset( $job->state[ self::CANDIDATE ] );
			return StepResult::more( 0, __( 'Importing the database...', 'lw-migration' ) );
		}
		$dir    = WorkDir::path( $this->store, $job );
		$plan   = ImportPlanFactory::build( $db, ImportState::package( $job ), $site, (string) $job->state['tmpPrefix'], $dir . '/' . ImportFiles::DEFERRED, ImportState::subsiteId( $job ), $this->pluginInstalled, $this->themeInstalled, ImportState::network( $job ) );
		$reader = new StatementReader( $dir . '/' . ImportFiles::DATABASE );
		$cursor = ImportCursor::fromArray( (array) ( $job->cursor['import'] ?? [] ) );
		try {
			$size = max( 1, $reader->size() );
			$done = ( new Importer( $db, $reader, $plan ) )->run( $cursor, $deadline );
			$this->keep( $job, $cursor ); // Only reached when run() returned normally.
		} finally {
			$reader->close();
		}
		if ( ! $done ) {
			return StepResult::more( min( 99, (int) ( 100 * $cursor->offset / $size ) ), __( 'Importing the database...', 'lw-migration' ) );
		}
		if ( $cursor->warningCount > count( $cursor->warnings ) ) {
			/* translators: %d: number of further database warnings */
			$job->addWarning( sprintf( __( '...and %d more database warnings.', 'lw-migration' ), $cursor->warningCount - count( $cursor->warnings ) ) );
		}
		if ( [] !== $cursor->leftOut ) {
			/* translators: %s: comma-separated table names */
			$job->addWarning( self::named( __( 'These tables of the archive belong to no site of this network and were not restored: %s', 'lw-migration' ), $cursor->leftOutShown(), $cursor->leftOutMore() ) );
		}
		if ( [] !== $cursor->skippedViews ) {
			/* translators: %s: comma-separated view names */
			$job->addWarning( self::named( __( 'The views of the archive were not restored (a site restored into a network brings no views): %s', 'lw-migration' ), $cursor->skippedViewsShown(), $cursor->skippedViewsMore() ) );
		}

		return StepResult::done( __( 'Database imported into temporary tables.', 'lw-migration' ) );
	}

	/**
	 * The import's texts for TmpRun's reasons.
	 *
	 * @param Connection $db         Destination database.
	 * @param string     $basePrefix Site table prefix.
	 * @param Job        $job        Import job.
	 * @throws ImportException When no run could be reserved or recorded.
	 */
	private function reserve( Connection $db, string $basePrefix, Job $job ): string {
		try {
			return $this->run->reserve( $db, $basePrefix, $job );
		} catch ( TmpRunException $e ) {
			if ( TmpRunException::UNREGISTERED === $e->reason ) {
				throw new ImportException( __( 'The temporary tables of this import cannot be recorded for this site, so the database import did not start. The site was not changed.', 'lw-migration' ) );
			}
			throw new ImportException( __( 'No free temporary table prefix was found, so the database import did not start. The site was not changed. Try again later.', 'lw-migration' ) );
		}
	}

	/**
	 * @param string             $format Translated sentence with one %s for the names.
	 * @param array<int, string> $shown  Names to list.
	 * @param int                $more   Further names only counted.
	 */
	private static function named( string $format, array $shown, int $more ): string {
		$text = sprintf( $format, implode( ', ', $shown ) );
		/* translators: %d: number of further names */
		return $more > 0 ? $text . ' ' . sprintf( __( '...and %d more.', 'lw-migration' ), $more ) : $text;
	}

	/**
	 * Copies the importer's progress into the job, adding the new warnings once.
	 *
	 * @param Job          $job    Import job.
	 * @param ImportCursor $cursor Cursor after a normal return of run().
	 * @return void
	 */
	private function keep( Job $job, ImportCursor $cursor ): void {
		$seen = (int) ( $job->cursor['warned'] ?? 0 );
		foreach ( array_slice( $cursor->warnings, $seen ) as $warning ) {
			$job->addWarning( $warning );
		}
		$job->cursor = [
			'import' => $cursor->toArray(),
			'warned' => count( $cursor->warnings ),
		];
	}

	/**
	 * @param Job $job Import job.
	 * @return array{prefix: string, token: string}|null The saved candidate of a reservation in progress.
	 */
	public static function candidate( Job $job ): ?array {
		return TmpRun::candidate( $job );
	}
}
