<?php
/**
 * Reset step: swaps the fresh install in with one RENAME.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\SessionMode;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Reset\ResetSwap;
use LightweightPlugins\Migration\Reset\ResetTables;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\TmpCarry;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * The import's swap pattern with the reset's own tables. Before the RENAME:
 * the site lock is refreshed (a job that lost it fails, nothing changed),
 * the RENAME is checked (ruling I8: a fresh table gone, a live core name that
 * is a view, a retired table gone, a target name taken — each fails here,
 * before the marker, so the job stays cancellable), the names it retires are
 * saved in the job, and the current lock row and our own rows are copied
 * into the fresh options again. Then the marker file, then one RENAME. A
 * replay after a request died after the RENAME finds no fresh table and the
 * marker: it only records the swap (the lock is still required). The object
 * cache is flushed in this request right away.
 */
final class DatabaseSwapStep implements StepInterface {

	public const KEY = ResetType::DB_SWAP;

	/** State key: what the RENAME will replace, retire and keep (saved before it; the result shows it only once it ran). */
	private const PLANNED = 'swapPlanned';

	/**
	 * @param JobStore $store        Job store.
	 * @param \Closure $connect      fn(): Connection.
	 * @param SiteLock $lock         Site lock.
	 * @param \Closure $now          fn(): int, Unix time.
	 * @param \Closure $coreSuffixes fn(): array<int, string>, WordPress' core table names without prefix.
	 * @param \Closure $flushCaches  fn(): void.
	 */
	public function __construct( private JobStore $store, private \Closure $connect, private SiteLock $lock, private \Closure $now, private \Closure $coreSuffixes, private \Closure $flushCaches ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! empty( $job->state['swapped'] ) ) {
			return StepResult::done();
		}
		$db     = ( $this->connect )();
		$site   = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
		$swap   = ResetSwap::forJob( $db, (string) ( $job->state['tmpPrefix'] ?? '' ), $site->tablePrefix );
		$built  = self::built( $job, $swap, $this->coreSuffixes );
		$marker = WorkDir::path( $this->store, $job ) . '/' . ImportFiles::SWAP_STARTED;
		$fresh  = $swap->pending( $built );
		if ( [] === $fresh && is_file( $marker ) ) {
			self::markSwapped( $job ); // The RENAME ran in a request that died before the job was saved.
			self::recordRemoved( $job );
			if ( ! $this->lock->refresh( $job->id, ( $this->now )() ) ) {
				throw new JobException( __( 'The fresh database is already live, but this reset lost the site lock before it could finish. The remaining reset steps did not run.', 'lw-migration' ) );
			}
			( $this->flushCaches )();
			self::warnViews( $job, (array) ( $job->state['removed']['database']['views'] ?? [] ) );

			return StepResult::done( __( 'The fresh database is now live.', 'lw-migration' ) );
		}
		$plan = $this->prepare( $job, $db, $site, $built, $fresh, $swap );
		Files::privateFile( $marker );
		if ( ! is_file( $marker ) ) {
			throw new JobException( __( 'The job folder cannot be written, so the database was not replaced. The site was not changed.', 'lw-migration' ) );
		}
		try {
			$swap->swap( $fresh, $plan['retire'] );
		} catch ( QueryException $e ) {
			if ( ResetSwap::busy( $e ) ) {
				throw new JobException( __( 'Another process kept the site\'s tables busy, so the database was not replaced. The site was not changed. Try again in a few minutes.', 'lw-migration' ) );
			}
			throw $e;
		}
		self::markSwapped( $job );
		self::recordRemoved( $job );
		// The live lock row is now the copy made before the RENAME; refresh it once
		// more. A lock lost after this point is caught by the driver's next refresh.
		$this->lock->refresh( $job->id, ( $this->now )() );
		( $this->flushCaches )();
		self::warnViews( $job, $plan['views'] );

		return StepResult::done( __( 'The fresh database is now live.', 'lw-migration' ) );
	}

	/**
	 * Ruling m4: the tables the build recorded (state.built); a job without
	 * the record falls back to the core tables whose fresh twin exists.
	 *
	 * @param Job       $job  Reset job.
	 * @param ResetSwap $swap The swap of this run.
	 * @param \Closure  $core fn(): array<int, string>.
	 * @return array<int, string>
	 */
	private static function built( Job $job, ResetSwap $swap, \Closure $core ): array {
		$built = $job->state['built'] ?? null;

		return is_array( $built ) ? array_values( array_map( 'strval', $built ) ) : $swap->pending( array_values( array_map( 'strval', $core() ) ) );
	}

	/**
	 * @param Job               $job   Reset job.
	 * @param array<int, mixed> $views View names the reset left.
	 */
	private static function warnViews( Job $job, array $views ): void {
		if ( [] !== $views ) {
			/* translators: %s: comma-separated view names */
			$job->addWarning( sprintf( __( 'These database views were left as they are: %s', 'lw-migration' ), implode( ', ', array_slice( array_map( 'strval', $views ), 0, 20 ) ) ) );
		}
	}

	/**
	 * Everything before the marker: the lock, the check, the saved names and
	 * the copy of our rows. Nothing here changes a live table.
	 *
	 * @param Job                $job   Reset job.
	 * @param Connection         $db    Site database.
	 * @param Site               $site  Site snapshot.
	 * @param array<int, string> $built The suffixes the build made (the only tables that count as core).
	 * @param array<int, string> $fresh The ones that still wait.
	 * @param ResetSwap          $swap  The swap of this run.
	 * @return array{core: array<int, string>, retire: array<int, string>, kept: array<int, string>, views: array<int, string>}
	 * @throws JobException When the lock is lost, the fresh tables are missing or the RENAME could not run.
	 */
	private function prepare( Job $job, Connection $db, Site $site, array $built, array $fresh, ResetSwap $swap ): array {
		if ( ! $this->lock->refresh( $job->id, ( $this->now )() ) ) {
			throw new JobException( __( 'This reset lost the site lock, so the database was not replaced. The site was not changed.', 'lw-migration' ) );
		}
		if ( [] === $fresh ) {
			throw new JobException( __( 'The fresh tables of this reset are missing, so the database was not replaced. The site was not changed.', 'lw-migration' ) );
		}
		$plan    = ResetTables::plan( $db, $site->tablePrefix, $built ); // A live table without a fresh twin is retired, whatever $wpdb->tables lists.
		$blocked = $swap->check( $built, $plan['retire'], array_values( array_map( 'strval', ( $this->coreSuffixes )() ) ) );
		if ( null !== $blocked ) {
			/* translators: %s: a database table name */
			throw new JobException( sprintf( __( 'The fresh database cannot be swapped in (%s changed meanwhile), so the database was not replaced. The site was not changed.', 'lw-migration' ), $blocked ) );
		}
		$job->state['retired']       = $plan['retire'];
		$job->state[ self::PLANNED ] = [
			'replaced' => count( $fresh ),
			'retired'  => count( $plan['retire'] ),
			'kept'     => count( $plan['kept'] ),
			'views'    => $plan['views'],
		];
		$this->store->save( $job ); // The names the RENAME retires, before it runs.
		$tmp = (string) $job->state['tmpPrefix'];
		SessionMode::relaxed(
			$db,
			static function () use ( $db, $tmp, $site ): void {
				( new TmpCarry( $db, $tmp, $site->tablePrefix ) )->ownRows(); // The current lock and our rows, again.
			}
		);

		return $plan;
	}

	/**
	 * Ruling m1 of the second review: the result names replaced and retired
	 * tables only once the RENAME ran; a failed swap shows none.
	 *
	 * @param Job $job Reset job.
	 */
	private static function recordRemoved( Job $job ): void {
		if ( is_array( $job->state[ self::PLANNED ] ?? null ) ) {
			$job->state['removed']['database'] = $job->state[ self::PLANNED ];
		}
		unset( $job->state[ self::PLANNED ] );
	}

	/**
	 * The swapped flag and a new cursor phase go together.
	 *
	 * @param Job $job Reset job.
	 */
	private static function markSwapped( Job $job ): void {
		$job->state['swapped'] = true;
		$job->cursor           = [ 'phase' => 'swapped' ];
	}
}
