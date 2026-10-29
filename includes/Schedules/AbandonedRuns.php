<?php
/**
 * Tells a scheduled job that is over from one that still runs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * A scheduled job whose job.json can no longer be saved still reads as
 * unfinished. It is over when its schedule's run log already ended it
 * (CronDriver gave it up), or, when its schedule is gone (the log went with
 * it), when it has not been saved for SiteLock::TTL. A job of a deleted
 * schedule that still saves runs to its end.
 */
final class AbandonedRuns {

	/**
	 * @param Repository $schedules Schedules.
	 */
	public function __construct( private Repository $schedules ) {
	}

	/**
	 * @param Job $job Unfinished job.
	 * @param int $now Unix time.
	 */
	public function abandoned( Job $job, int $now ): bool {
		if ( ! is_array( $job->state['schedule'] ?? null ) ) {
			return false;
		}
		$id = self::scheduleOf( $job );
		if ( null !== $id && null !== $this->schedules->find( $id ) ) {
			return in_array( $job->id, array_column( Log::all( $id ), 'job' ), true );
		}

		return $job->updatedAt <= $now - SiteLock::TTL;
	}

	/**
	 * @param Job|null $job Job.
	 * @return string|null Id of the schedule the job runs for.
	 */
	public static function scheduleOf( ?Job $job ): ?string {
		$id = null === $job ? null : ( $job->state['schedule']['id'] ?? null );

		return is_string( $id ) && 1 === preg_match( Schedule::ID_PATTERN, $id ) ? $id : null;
	}
}
