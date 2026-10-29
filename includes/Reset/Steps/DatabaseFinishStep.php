<?php
/**
 * Reset step: removes the replaced tables and settles the fresh site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Maintenance\Cleanup;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetSwap;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * First call: the replaced and retired tables are dropped one by one (one a
 * foreign key holds stays, named in a warning, for the daily sweep — ruling
 * M13), the run is forgotten and its marker freed once nothing of it is
 * left; the step returns "more" so this is recorded before the second call
 * settles the fresh site (caches, our daily cleanup and schedule events,
 * which the fresh cron option lacks, and the rewrite rules).
 */
final class DatabaseFinishStep implements StepInterface {

	public const KEY = ResetType::DB_FINISH;

	/**
	 * @param \Closure $connect      fn(): Connection.
	 * @param \Closure $forget       fn( string $runId ): bool, removes the run from the site's registry.
	 * @param \Closure $afterInstall fn(): void, settles the fresh site.
	 */
	public function __construct( private \Closure $connect, private \Closure $forget, private \Closure $afterInstall ) {
	}

	/**
	 * @param Services $services Services.
	 */
	public static function fromWordPress( Services $services ): self {
		return new self(
			static fn() => $services->connection(),
			static fn( string $run ): bool => ( new TmpRunRegistry() )->forget( $run ),
			static function () use ( $services ): void {
				self::settle( $services );
			}
		);
	}

	/**
	 * The fresh site's settling: caches, our daily cleanup, our schedule
	 * events (the fresh cron option has none) and the rewrite rules. Also run
	 * by the cleanup of a reset that failed after its swap.
	 *
	 * @param Services $services Services.
	 */
	public static function settle( Services $services ): void {
		wp_cache_flush();
		Cleanup::schedule();
		Events::healSite( $services, time() );
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Ruling M13: a table another table still references stays, named as the
	 * site knew it, for the daily sweep.
	 *
	 * @param Job                $job  Reset job.
	 * @param ResetSwap          $swap The swap of this run.
	 * @param array<string, int> $kept Name => server error number (dropOld()).
	 */
	private static function warnKept( Job $job, ResetSwap $swap, array $kept ): void {
		foreach ( $kept as $old => $errno ) {
			/* translators: %d: database error number */
			$why = in_array( $errno, [ 1451, 3730 ], true ) ? __( 'another table still references it', 'lw-migration' ) : sprintf( __( 'database error %d', 'lw-migration' ), $errno );
			/* translators: 1: table name on the site, 2: the reason, 3: the name it was kept under */
			$job->addWarning( sprintf( __( 'The table %1$s could not be removed (%2$s), so it was kept as %3$s; the daily cleanup tries again.', 'lw-migration' ), $swap->originalName( $old, (array) ( $job->state['retired'] ?? [] ) ), $why, $old ) );
		}
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( empty( $job->cursor['dropped'] ) ) {
			$db   = ( $this->connect )();
			$tmp  = (string) ( $job->state['tmpPrefix'] ?? '' );
			$site = Site::fromArray( (array) ( $job->state['site'] ?? [] ) );
			$swap = ResetSwap::forJob( $db, $tmp, $site->tablePrefix );
			self::warnKept( $job, $swap, $swap->dropOld() );
			$swap->dropFresh(); // Anything else the fresh install made under the run's prefix.
			try {
				RunMarker::releaseIfUnused( $db, $tmp, $this->forget );
			} catch ( QueryException $e ) {
				unset( $e ); // The daily sweep frees it.
			}
			$job->cursor = [ 'dropped' => true ];

			return StepResult::more( 95, __( 'Removing the old tables...', 'lw-migration' ) );
		}
		( $this->afterInstall )();

		/* translators: %s: user login */
		return StepResult::done( sprintf( __( 'Fresh database ready. You are still logged in as %s; every other user was removed.', 'lw-migration' ), ResetJobs::admin( $job )['login'] ) );
	}
}
