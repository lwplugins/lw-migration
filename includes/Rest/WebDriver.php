<?php
/**
 * Drives a job from the browser, one short request at a time.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Support\RequestAbort;
use LightweightPlugins\Migration\Support\TimeDeadline;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * The REST twin of CLI\Drive. Before every tick (and every answer or upload
 * chunk, through claim()) the site lock is extended — or taken again when it
 * is free or expired, which is how a job a closed tab left behind resumes; a
 * live lock of another job is a 409 and this job stays as it is. A job left
 * behind before its point of no return is not resumed when another job
 * ran after it stopped (it would write data older than that job's): it
 * fails instead. A finished job never keeps the site lock. A tick keeps
 * running when the browser goes away (ignore_user_abort), and everything a
 * step prints (PHP notices with display_errors on) is thrown away: it would
 * break the JSON answer and could show server paths. A download without byte
 * ranges that cannot finish in one request fails with advice instead of dying
 * at a proxy timeout (WebDownloadGuard; checked here too before a tick).
 */
final class WebDriver {

	/**
	 * @param JobStore $jobs      Job store.
	 * @param SiteLock $lock      Site lock.
	 * @param \Closure $runnerFor fn( string $type ): Runner.
	 * @param \Closure $now       fn(): int.
	 * @param float    $seconds   Work per tick (WebLimits::tickSeconds()).
	 */
	public function __construct( private JobStore $jobs, private SiteLock $lock, private \Closure $runnerFor, private \Closure $now, private float $seconds ) {
	}

	/**
	 * @param Job $job The job as last loaded (only its id is trusted).
	 * @return Job The job after the tick.
	 * @throws RestException When another job holds the site lock.
	 * @throws JobBusyException When another request holds the job while it must be failed.
	 * @throws JobException When the job cannot be read (gone or unreadable).
	 */
	public function tick( Job $job ): Job {
		RequestAbort::ignore();

		return QuietOutput::run(
			function () use ( $job ): Job {
				[ $job, $runner ] = $this->take( $job->id );
				if ( $job->isFinished() ) {
					return $job;
				}
				$runner ??= $this->runner( $job->type );
				$reason   = WebLimits::tooLargeForOneRequest( $job ); // Fast path; WebDownloadGuard holds inside the tick.
				if ( null !== $reason ) {
					return $this->settle( $runner->fail( $job->id, $reason, $this->now() ) );
				}

				return $this->settle( $runner->tick( $job->id, new TimeDeadline( $this->seconds ), $this->now() ) );
			}
		);
	}

	/**
	 * Extends the site lock for this job, or takes it when it is free or
	 * expired, and returns the job as saved. A finished job comes back without
	 * the lock; so does a job left behind before its point of no return when
	 * another job ran after it stopped: that one is failed. Callers go on
	 * only with an unfinished job.
	 *
	 * @param string $id Job id.
	 * @return Job The saved job.
	 * @throws RestException When another job holds a live lock.
	 * @throws JobBusyException When another request holds the job while it must be failed.
	 * @throws JobException When the job cannot be read (gone or unreadable); a lock taken for it is let go.
	 */
	public function claim( string $id ): Job {
		return $this->take( $id )[0];
	}

	/**
	 * Frees the site of a finished job (its finish hook already ran; nothing
	 * else would release the lock before it expires) and returns the job.
	 * Every claim-guarded operation passes its result through here.
	 *
	 * @param Job $job Job after the operation.
	 */
	public function settle( Job $job ): Job {
		if ( $job->isFinished() ) {
			$this->lock->release( $job->id );
		}

		return $job;
	}

	/**
	 * The runner of a job type; a browser-driven runner wraps its steps with
	 * WebDownloadGuard (the factory's job).
	 *
	 * @param string $type Job type.
	 */
	public function runner( string $type ): Runner {
		return ( $this->runnerFor )( $type );
	}

	/**
	 * The work of claim(), also handing back the runner it had to build (null
	 * when none was needed).
	 *
	 * @param string $id Job id.
	 * @return array{0: Job, 1: Runner|null}
	 * @throws RestException When another job holds a live lock.
	 * @throws JobException When the job cannot be read, or another request holds it (JobBusyException).
	 */
	private function take( string $id ): array {
		$now      = $this->now();
		$resuming = ! $this->lock->refresh( $id, $now );
		if ( $resuming ) {
			$saved = $this->jobs->load( $id );
			if ( $saved->isFinished() ) {
				return [ $saved, null ];
			}
			$this->acquire( $id, $now );
		}
		$job = $this->reload( $id, $resuming );
		if ( ! $resuming || $job->isFinished() ) {
			return [ $job, null ];
		}
		$runner = $this->runner( $job->type );
		if ( ! $runner->defersCancel( $job ) && ActiveJob::ranAfter( $this->jobs, $job ) ) {
			$message = __( 'Another backup or restore ran on this site after this one stopped. Start this one again.', 'lw-migration' );

			return [ $this->settle( $runner->fail( $id, $message, $now ) ), $runner ];
		}

		return [ $job, $runner ];
	}

	/**
	 * Takes the free or expired site lock for this job.
	 *
	 * @param string $id  Job id.
	 * @param int    $now Unix time.
	 * @throws RestException When another job holds a live lock.
	 */
	private function acquire( string $id, int $now ): void {
		if ( ! $this->lock->acquire( $id, $now ) ) {
			throw new RestException( ErrorMap::SITE_BUSY, SiteBusyException::describe( $this->lock->expiresAt( $now ) ), 409 );
		}
	}

	/**
	 * The job loaded again under the site lock (a cancel or finish in between
	 * is seen here); a finished job lets go of the lock, and so does a job
	 * that cannot be read after this request took the lock for it.
	 *
	 * @param string $id    Job id.
	 * @param bool   $taken Whether this request took the lock (rather than extended it).
	 * @throws JobException When the job cannot be read.
	 */
	private function reload( string $id, bool $taken ): Job {
		try {
			return $this->settle( $this->jobs->load( $id ) );
		} catch ( JobException $e ) {
			if ( $taken ) {
				$this->lock->release( $id );
			}
			throw $e;
		}
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
