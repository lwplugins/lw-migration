<?php
/**
 * Creates a job that holds the site lock, or nothing at all.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by export and import job creation: a job that cannot be fully set up
 * (lock, snapshot, secret) is removed and the lock released, so nothing is
 * left behind, and a busy site is refused with the same message everywhere.
 */
final class JobStarter {

	/**
	 * @param JobStore             $jobs    Job store.
	 * @param SiteLock             $lock    Site lock.
	 * @param string               $type    Job type.
	 * @param array<int, string>   $steps   Step keys.
	 * @param array<string, mixed> $options Job options.
	 * @param int                  $now     Unix time.
	 * @param \Closure             $setup   fn( Job $job ): void — fills and saves the job, stores its secret.
	 * @return Job
	 * @throws SiteBusyException When another job holds the site lock.
	 * @throws \Throwable When the lock, the job or its secret cannot be written (the job is removed first).
	 */
	public static function start( JobStore $jobs, SiteLock $lock, string $type, array $steps, array $options, int $now, \Closure $setup ): Job {
		$job = $jobs->create( $type, $steps, $options, $now );
		try {
			if ( ! $lock->acquire( $job->id, $now ) ) {
				throw new SiteBusyException( $lock->expiresAt( $now ) );
			}
			$setup( $job );
		} catch ( \Throwable $e ) {
			self::rollBack( $jobs, $lock, $job->id );
			throw $e;
		}

		return $job;
	}

	/**
	 * Releases the lock if this job holds it and removes the job folder. Never
	 * throws: the caller rethrows the original failure, which is what the user
	 * needs to see; a lock that cannot be released expires after its TTL.
	 *
	 * @param JobStore $jobs  Job store.
	 * @param SiteLock $lock  Site lock.
	 * @param string   $jobId Job id.
	 * @return void
	 */
	private static function rollBack( JobStore $jobs, SiteLock $lock, string $jobId ): void {
		try {
			$lock->release( $jobId );
		} catch ( \Throwable $e ) {
			unset( $e ); // Secondary to the original failure; the lock expires on its own.
		}
		try {
			$jobs->delete( $jobId );
		} catch ( \Throwable $e ) {
			unset( $e ); // Secondary to the original failure; the daily sweep removes the folder.
		}
	}
}
