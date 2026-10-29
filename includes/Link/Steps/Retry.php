<?php
/**
 * Patience with a connected site that does not answer for a moment.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\TimeDeadline;

defined( 'ABSPATH' ) || exit;

/**
 * A passing failure (LinkException::isPassing()) is counted in the step's
 * cursor and the step tries again on its next run; after TOLERATED failures
 * in a row that also span SECONDS, or at once for a lasting failure, the
 * failure is thrown and fails the job. A success clears the count. The count
 * changes the cursor, so Job\AttemptGuard sees each counted failure as
 * progress.
 */
final class Retry {

	public const TOLERATED = 10;

	public const SECONDS = 900;

	/**
	 * @param Job           $job Job.
	 * @param LinkException $e   What went wrong.
	 * @param int           $now Unix time.
	 * @return int Failures in a row, this one included (for the pause).
	 * @throws LinkException The failure, when it is lasting or patience is spent.
	 */
	public static function note( Job $job, LinkException $e, int $now ): int {
		if ( ! $e->isPassing() ) {
			throw $e;
		}
		$since = (int) ( $job->cursor['failingSince'] ?? $now );
		$count = (int) ( $job->cursor['failures'] ?? 0 ) + 1;
		if ( $count >= self::TOLERATED && $now - $since >= self::SECONDS ) {
			throw $e;
		}
		$job->cursor['failingSince'] = $since;
		$job->cursor['failures']     = $count;

		return $count;
	}

	/**
	 * Waits before the next try, never past the tick: nothing once the
	 * deadline is reached, at most the time a TimeDeadline has left.
	 *
	 * @param \Closure $pause    fn( float $seconds ): void.
	 * @param Deadline $deadline The tick's deadline.
	 * @param float    $seconds  Wanted pause.
	 */
	public static function wait( \Closure $pause, Deadline $deadline, float $seconds ): void {
		if ( $deadline->reached() ) {
			return;
		}
		if ( $deadline instanceof TimeDeadline ) {
			$seconds = min( $seconds, $deadline->remaining() );
		}
		if ( $seconds > 0 ) {
			$pause( $seconds );
		}
	}

	/**
	 * @param Job $job Job.
	 */
	public static function clear( Job $job ): void {
		unset( $job->cursor['failingSince'], $job->cursor['failures'] );
	}
}
