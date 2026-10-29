<?php
/**
 * Patience with a destination that does not answer for a moment.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Steps\Retry;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.8: the Retry rules of the connections (Retry::TOLERATED failures
 * in a row that also span Retry::SECONDS, then the failure stands), counted
 * in the step's cursor, so Job\AttemptGuard sees each counted failure as
 * progress. A pause grows with the count (2 s per failure, at most 10 s) or
 * is the server's Retry-After; cursor.notBefore keeps the next request back
 * also across ticks. The Link code is not changed (plan D15).
 */
final class CloudRetry {

	private const MAX_PAUSE = 10;

	/**
	 * @param Job              $job Job.
	 * @param TransientFailure $e   What went wrong.
	 * @param int              $now Unix time.
	 * @return int Failures in a row, this one included.
	 * @throws TransientFailure The failure, when patience is spent.
	 */
	public static function note( Job $job, TransientFailure $e, int $now ): int {
		$since = (int) ( $job->cursor['failingSince'] ?? $now );
		$count = (int) ( $job->cursor['failures'] ?? 0 ) + 1;
		if ( $count >= Retry::TOLERATED && $now - $since >= Retry::SECONDS ) {
			throw $e;
		}
		$job->cursor['failingSince'] = $since;
		$job->cursor['failures']     = $count;

		return $count;
	}

	/**
	 * @param TransientFailure $e     What went wrong.
	 * @param int              $count Failures in a row.
	 * @return int Seconds to wait.
	 */
	public static function pause( TransientFailure $e, int $count ): int {
		return max( (int) ( $e->retryAfter ?? 0 ), min( self::MAX_PAUSE, 2 * $count ) );
	}

	public static function notBefore( Job $job ): int {
		return (int) ( $job->cursor['notBefore'] ?? 0 );
	}

	public static function hold( Job $job, int $until ): void {
		$job->cursor['notBefore'] = $until;
	}

	/**
	 * The wait-while-held block of the upload and the download step: while a
	 * hold (Retry-After, a pause after a failure) runs, wait (never past the
	 * tick) and count the waiting call in the cursor, so Job\AttemptGuard
	 * never takes a wait for a stuck step.
	 *
	 * @param Job      $job      Job.
	 * @param Deadline $deadline The tick's deadline.
	 * @param \Closure $pause    fn( float $seconds ): void.
	 * @param int      $now      Unix time.
	 * @return bool True: the caller returns "more" (it waited); false: go on with the work.
	 */
	public static function waitIfHeld( Job $job, Deadline $deadline, \Closure $pause, int $now ): bool {
		$wait = self::notBefore( $job ) - $now;
		if ( $wait <= 0 ) {
			return false;
		}
		Retry::wait( $pause, $deadline, (float) $wait );
		$job->cursor['waits'] = (int) ( $job->cursor['waits'] ?? 0 ) + 1;

		return true;
	}

	/**
	 * The note/hold block of the upload and the download step: count a
	 * transient failure and hold the next request back.
	 *
	 * @param Job              $job Job.
	 * @param TransientFailure $e   What went wrong.
	 * @param int              $now Unix time.
	 * @throws TransientFailure The failure, when patience is spent.
	 */
	public static function defer( Job $job, TransientFailure $e, int $now ): void {
		$count = self::note( $job, $e, $now );
		self::hold( $job, $now + self::pause( $e, $count ) );
	}

	public static function clear( Job $job ): void {
		unset( $job->cursor['failingSince'], $job->cursor['failures'], $job->cursor['notBefore'], $job->cursor['waits'] );
	}
}
