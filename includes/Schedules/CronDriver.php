<?php
/**
 * Drives scheduled backups without a browser.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\WebDriver;
use LightweightPlugins\Migration\Rest\WebLimits;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 4/8 CronDriver. One call works until its budget is spent (null: to the
 * end, in WP-CLI): it continues the site's unfinished scheduled job (the lock
 * holder, or one whose driver went away), then starts queued schedules,
 * oldest due first. Ticks go through Rest\WebDriver, so the lock rules are
 * the browser's. It waits (BUSY) while another job holds the site or a job
 * of no schedule was left past its point of no return (lock or not); leaves
 * a job whose file lock another process holds (ELSEWHERE); stops (STUCK)
 * when a job cannot be saved, and gives it up after SiteLock::TTL without a
 * save (once per call; AbandonedRuns keeps it over); fails a job not moved
 * for STALE seconds; skips a run that waited QUEUE_LIMIT seconds; logs a run
 * that cannot start. A run is claimed by taking it from the queue before its
 * job starts (never twice). With work left it asks for a continuation: at
 * once with the job (loopback) after a spent budget, after WAIT seconds
 * otherwise.
 */
final class CronDriver {

	/**
	 * Finds the job to deal with first.
	 *
	 * @var CurrentJob
	 */
	private CurrentJob $current;

	public const IDLE = 'idle';

	public const DONE = 'done';

	public const MORE = 'more';

	public const BUSY = 'busy';

	public const ELSEWHERE = 'elsewhere';

	public const STUCK = 'stuck';

	public const STALE = 21600;

	public const QUEUE_LIMIT = 86400;

	public const WAIT = 300;

	public const BUDGET = 20.0;

	/**
	 * @param JobStore      $jobs      Job store.
	 * @param SiteLock      $lock      Site lock.
	 * @param WebDriver     $driver    Ticks with the lock rules of the browser.
	 * @param Queue         $queue     Due runs.
	 * @param Repository    $schedules Schedules.
	 * @param ScheduleJobs  $starter   Starts a schedule's job.
	 * @param RunRecorder   $recorder  Logs runs that cannot start.
	 * @param \Closure      $continue  fn( ?string $jobId, int $delay ): void — asks for the next call.
	 * @param \Closure      $now       fn(): int.
	 * @param \Closure      $clock     fn(): float — seconds, for the budget.
	 * @param \Closure|null $discard   fn( Job $job ): void — removes the work files of a job given up.
	 */
	public function __construct(
		private JobStore $jobs,
		private SiteLock $lock,
		private WebDriver $driver,
		private Queue $queue,
		private Repository $schedules,
		private ScheduleJobs $starter,
		private RunRecorder $recorder,
		private \Closure $continue,
		private \Closure $now,
		private \Closure $clock,
		private ?\Closure $discard = null
	) {
		$this->current = new CurrentJob( $jobs, $lock, $schedules, static fn( string $type ): Runner => $driver->runner( $type ), $now );
	}

	public static function fromServices( Services $services ): self {
		$secrets  = $services->webSecrets();
		$now      = static fn(): int => time();
		$loopback = new Loopback();

		return new self(
			$services->jobs(),
			$services->lock(),
			new WebDriver( $services->jobs(), $services->lock(), static fn( string $type ): Runner => $services->runner( $type, $secrets ), $now, WebLimits::tickSeconds() ),
			new Queue(),
			$services->schedules(),
			ScheduleJobs::fromServices( $services, $secrets ),
			RunRecorder::fromServices( $services ),
			static function ( ?string $jobId, int $delay ) use ( $loopback ): void {
				Events::continueIn( max( 60, $delay ) );
				if ( null !== $jobId && 0 === $delay && ! self::cli() ) {
					$token = $loopback->issue( $jobId, time() );
					if ( null !== $token ) {
						$loopback->send( $jobId, $token );
					}
				}
			},
			$now,
			static fn(): float => microtime( true ),
			static function ( Job $job ) use ( $services ): void {
				$services->discardExportWork( $job );
			}
		);
	}

	/**
	 * Why a run that waited past QUEUE_LIMIT is logged as skipped.
	 */
	public static function skipReason(): string {
		return __( 'This run was skipped: another backup or restore kept the site busy for a day.', 'lw-migration' );
	}

	/**
	 * Seconds one web request works (Rest\WebLimits::cronSeconds()); null in WP-CLI (to the end).
	 *
	 * @param bool|null $cli Whether this is WP-CLI (default: the WP_CLI constant).
	 */
	public static function budget( ?bool $cli = null ): ?float {
		return ( $cli ?? self::cli() ) ? null : WebLimits::cronSeconds( self::BUDGET );
	}

	/**
	 * @param float|null $budget Seconds, or null for no limit.
	 * @return string IDLE (nothing left), MORE, BUSY, ELSEWHERE or STUCK (a continuation was asked for).
	 * @throws \Throwable Any other failure, after a continuation was asked for.
	 */
	public function work( ?float $budget ): string {
		if ( ! MainSite::isContext() ) {
			return self::IDLE;
		}
		$end     = null === $budget ? null : ( $this->clock )() + $budget;
		$givenUp = [];
		try {
			do {
				$result = $this->next( $end, $givenUp );
			} while ( null === $result );

			return $result;
		} catch ( SiteBusyException $e ) {
			return $this->stop( self::BUSY, null, self::WAIT );
		} catch ( \Throwable $e ) {
			( $this->continue )( null, self::WAIT ); // Whatever broke, the work is asked for again.
			throw $e;
		}
	}

	/**
	 * Deals with the current job, or starts the next queued run, and drives it.
	 *
	 * @param float|null         $end     Clock value to stop at, or null.
	 * @param array<int, string> $givenUp Jobs given up in this call; a job given up here is added.
	 * @return string|null The call's result, or null to go on.
	 * @throws SiteBusyException When another job took the site before a queued run started.
	 */
	private function next( ?float $end, array &$givenUp ): ?string {
		$job = $this->current->find();
		if ( null !== $job && in_array( $job->id, $givenUp, true ) ) {
			return $this->stop( self::STUCK, null, self::WAIT ); // Its end could not be recorded: never again in this call.
		}
		if ( null !== $job && ! CurrentJob::isScheduled( $job ) ) {
			return $this->stop( self::BUSY, null, self::WAIT );
		}
		$job ??= $this->startNext();
		if ( null === $job ) {
			return self::IDLE;
		}
		[ $result, $job ] = $this->drive( $job, $end );
		if ( self::STUCK === $result && $this->now() - $job->updatedAt >= SiteLock::TTL ) { // Not saved for the lock time (the copy on disk, not the one this call began with).
			$this->giveUp( $job );
			$givenUp[] = $job->id;
			$result    = self::DONE;
		}
		if ( self::DONE !== $result ) {
			return self::MORE === $result ? $this->stop( $result, $job->id, 0 ) : $this->stop( $result, null, self::WAIT );
		}
		if ( null !== $end && ( $this->clock )() >= $end ) {
			return $this->stop( self::MORE, null, 0 ); // A queued schedule may be next: the fallback event.
		}

		return null;
	}

	/**
	 * Asks for the next call and ends this one.
	 *
	 * @param string      $result The call's result.
	 * @param string|null $jobId  Job to continue at once (a loopback), or null.
	 * @param int         $delay  Seconds until the next call.
	 */
	private function stop( string $result, ?string $jobId, int $delay ): string {
		( $this->continue )( $jobId, $delay );

		return $result;
	}

	/**
	 * Ends a scheduled job that has not been saved for the lock time while
	 * ticks were tried (a full disk): its site lock goes, the run is logged as
	 * failed (and mailed as the schedule asks) through the options, which
	 * still work, its work files go (room for the next run), and CurrentJob
	 * never offers the job again (AbandonedRuns).
	 *
	 * @param Job $job The job as last saved.
	 */
	private function giveUp( Job $job ): void {
		$this->lock->release( $job->id ); // Only while this job holds it.
		$ended         = clone $job;
		$ended->status = Job::FAILED;
		$ended->error  = __( 'The scheduled backup stopped: its progress could not be saved (the disk may be full).', 'lw-migration' );
		try {
			if ( null !== $this->discard ) {
				( $this->discard )( $ended );
			}
		} finally {
			$this->recorder->finished( $ended, $this->now() );
		}
	}

	/**
	 * @param Job        $job Unfinished scheduled job.
	 * @param float|null $end Clock value to stop at, or null.
	 * @return array{0: string, 1: Job} DONE (it ended), MORE (budget spent), BUSY (another job holds the site), ELSEWHERE or STUCK (it cannot be saved); and the job as last saved.
	 */
	private function drive( Job $job, ?float $end ): array {
		try {
			$runner = $this->driver->runner( $job->type );
			do {
				if ( $this->heldElsewhere( $job->id ) ) {
					return [ self::ELSEWHERE, $job ]; // Even a stale job: another process drives it.
				}
				if ( $job->updatedAt < $this->now() - self::STALE ) {
					$this->driver->settle( $runner->fail( $job->id, __( 'The scheduled backup stopped because nothing continued it for hours (WP-Cron did not run). It runs again at its next time.', 'lw-migration' ), $this->now() ) );

					return [ self::DONE, $job ];
				}
				$job = $this->driver->tick( $job );
				if ( $job->isFinished() ) {
					return [ self::DONE, $job ];
				}
				if ( Job::NEEDS_INPUT === $job->status ) {
					$this->driver->settle( $runner->fail( $job->id, __( 'The scheduled backup asked a question only a person can answer, so it was stopped.', 'lw-migration' ), $this->now() ) );

					return [ self::DONE, $job ];
				}
			} while ( null === $end || ( $this->clock )() < $end );
		} catch ( RestException $e ) {
			return [ self::BUSY, $job ];
		} catch ( JobBusyException $e ) {
			return [ self::ELSEWHERE, $job ];
		} catch ( JobException $e ) {
			$saved = $this->current->load( $job->id );

			// Gone or ended: nothing left to drive. Still unfinished: it cannot be saved (a full disk); trying again now would loop.
			return null === $saved || $saved->isFinished() ? [ self::DONE, $job ] : [ self::STUCK, $saved ];
		}

		return [ self::MORE, $job ];
	}

	/**
	 * Starts the oldest due run that can start; logs and drops the ones that cannot.
	 *
	 * @throws SiteBusyException When another job took the site (the run is queued again with its due time, unless that job is this schedule's own run).
	 */
	private function startNext(): ?Job {
		foreach ( $this->queue->all() as $entry ) {
			$schedule = $this->schedules->find( $entry['id'] );
			if ( null === $schedule || ! $schedule->enabled ) {
				$this->queue->remove( $entry['id'] );
				continue;
			}
			if ( ! $this->queue->take( $entry['id'] ) ) {
				continue; // Another request claimed this run.
			}
			if ( $entry['due'] < $this->now() - self::QUEUE_LIMIT ) {
				$this->recorder->notStarted( $schedule, RunRecorder::CRON, RunRecorder::SKIPPED, self::skipReason(), $this->now() );
				continue;
			}
			try { // Taken before the start: a process dying in between never runs it twice.
				return $this->starter->start( $schedule, RunRecorder::CRON, $this->now() );
			} catch ( SiteBusyException $e ) {
				if ( ! $this->current->heldBy( $entry['id'] ) ) {
					$this->queue->add( $entry['id'], $entry['due'] ); // It waits again, with its due time.
				}
				throw $e;
			} catch ( ScheduleException | ExportException $e ) {
				$this->recorder->notStarted( $schedule, RunRecorder::CRON, Job::FAILED, $e->getMessage(), $this->now() );
			} catch ( \Throwable $e ) {
				$this->recorder->notStarted( $schedule, RunRecorder::CRON, Job::FAILED, __( 'The scheduled backup could not start. Check that the plugin\'s folders and the backups folder are writable.', 'lw-migration' ), $this->now() );
			}
		}

		return null;
	}

	/**
	 * The schedule whose job is the site's current unfinished job, if any: a
	 * due event of that schedule coalesces with the running run (Events::due()).
	 */
	public function runningSchedule(): ?string {
		return $this->current->runningSchedule();
	}

	/**
	 * Whether another process is inside a tick of the job right now (its file lock is held).
	 *
	 * @param string $id Job id.
	 */
	private function heldElsewhere( string $id ): bool {
		try {
			$handle = $this->jobs->acquire( $id );
		} catch ( JobException $e ) {
			return false; // Gone: the tick says so.
		}
		if ( null === $handle ) {
			return true;
		}
		$this->jobs->release( $handle );

		return false;
	}

	private static function cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
