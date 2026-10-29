<?php
/**
 * Finds the job the scheduled work has to deal with first.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * Which job is current for the schedules: the unfinished job holding the
 * site; else a job of no schedule left past its point of no return (the site
 * waits for it); else the most recent unfinished scheduled job (its driver
 * went away). A scheduled job that is over (AbandonedRuns) is never current.
 * CronDriver drives what this finds; the schedule list and the due check name
 * the running schedule from it.
 */
final class CurrentJob {

	/**
	 * Tells scheduled jobs that are over from running ones.
	 *
	 * @var AbandonedRuns
	 */
	private AbandonedRuns $runs;

	/**
	 * @param JobStore   $jobs      Job store.
	 * @param SiteLock   $lock      Site lock.
	 * @param Repository $schedules Schedules.
	 * @param \Closure   $runner    fn( string $type ): Runner — tells a job past its point of no return.
	 * @param \Closure   $now       fn(): int.
	 */
	public function __construct(
		private JobStore $jobs,
		private SiteLock $lock,
		Repository $schedules,
		private \Closure $runner,
		private \Closure $now
	) {
		$this->runs = new AbandonedRuns( $schedules );
	}

	/**
	 * @param Services      $services Services.
	 * @param JobStore|null $jobs     Job store (default: the services' one).
	 */
	public static function fromServices( Services $services, ?JobStore $jobs = null ): self {
		return new self(
			$jobs ?? $services->jobs(),
			$services->lock(),
			$services->schedules(),
			static fn( string $type ): Runner => $services->runner( $type, $services->webSecrets() ),
			static fn(): int => time()
		);
	}

	public function find(): ?Job {
		$holder = $this->lock->holder( $this->now() );
		if ( null !== $holder ) {
			$job = $this->load( $holder );
			if ( null !== $job && ! $job->isFinished() && ! $this->runs->abandoned( $job, $this->now() ) ) {
				return $job;
			}
		}
		$best = null;
		foreach ( $this->jobs->ids() as $id ) {
			$job = $this->load( $id );
			if ( null === $job || $job->isFinished() || $this->runs->abandoned( $job, $this->now() ) ) {
				continue;
			}
			if ( ! self::isScheduled( $job ) ) {
				if ( $this->pastNoReturn( $job ) ) {
					return $job;
				}
				continue;
			}
			if ( null === $best || $job->updatedAt > $best->updatedAt ) {
				$best = $job;
			}
		}

		return $best;
	}

	/**
	 * The schedule whose job is the site's current unfinished job, if any: a
	 * due event of that schedule coalesces with the running run (Events::due()).
	 */
	public function runningSchedule(): ?string {
		return MainSite::isContext() ? AbandonedRuns::scheduleOf( $this->find() ) : null;
	}

	/**
	 * Whether the site's lock holder is a job of this schedule (its own run started meanwhile).
	 *
	 * @param string $scheduleId Schedule id.
	 */
	public function heldBy( string $scheduleId ): bool {
		$holder = $this->lock->holder( $this->now() );

		return AbandonedRuns::scheduleOf( null === $holder ? null : $this->load( $holder ) ) === $scheduleId;
	}

	/**
	 * @param string $id Job id.
	 * @return Job|null Null when it is gone or unreadable.
	 */
	public function load( string $id ): ?Job {
		try {
			return $this->jobs->load( $id );
		} catch ( JobException $e ) {
			return null;
		}
	}

	public static function isScheduled( Job $job ): bool {
		return is_array( $job->state['schedule'] ?? null );
	}

	/**
	 * Whether a cancel of this job would be deferred: an import whose swap may have started.
	 *
	 * @param Job $job Unfinished job of no schedule.
	 */
	private function pastNoReturn( Job $job ): bool {
		try {
			return ( $this->runner )( $job->type )->defersCancel( $job );
		} catch ( JobException $e ) {
			return false; // A job type no runner knows: nothing it could still change.
		}
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
