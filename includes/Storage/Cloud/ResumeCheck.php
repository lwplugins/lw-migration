<?php
/**
 * When the upload step asks a destination how much it has, and when it gives up on a part.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (M7c plan E9 with preflight P-I7). The Runner counts starts from one
 * cursor ($job->attempt): a second start means the call before died. M7b's
 * cut-off (D27) ends a destination after two deaths on one part; a status
 * query changes the cursor (so the start count begins again), therefore
 * the deaths are also counted in the cursor (`deaths`, cleared by a part or
 * a start that arrived). A destination is asked once per death and once
 * after a passing failure (`verify`) before the part is sent again; a call
 * that dies while asking ends in the cut-off like one that dies sending.
 */
final class ResumeCheck {

	/** Starts from one cursor that mean two requests died on it (M7b D27). */
	public const CUT_OFF_STARTS = 3;

	/** Deaths on one part that end the destination. */
	public const MAX_DEATHS = 2;

	public const GO = 'go';

	/** Cut off; the unfinished upload is not touched (a call before tried that already). */
	public const CUT = 'cut';

	/** Cut off, and try to abort the unfinished upload (the first start that cuts off). */
	public const CUT_ABORT = 'cut-abort';

	/**
	 * Counts this start's death, if the call before died, and decides.
	 *
	 * @param Job $job Job (its cursor gains `deaths`).
	 * @return string GO, CUT or CUT_ABORT.
	 */
	public static function start( Job $job ): string {
		$count  = (int) ( $job->attempt['count'] ?? 0 );
		$before = (int) ( $job->cursor['deaths'] ?? 0 );
		if ( $count <= 1 ) {
			return self::GO;
		}
		if ( $count < self::CUT_OFF_STARTS ) {
			$job->cursor['deaths'] = $before + 1;
			if ( $before + 1 < self::MAX_DEATHS ) {
				return self::GO;
			}
		}
		// The start that first cut this cursor off: the second (deaths in the cursor) or the third.
		$first = $before + 1 >= self::MAX_DEATHS ? 2 : self::CUT_OFF_STARTS;

		return $count === $first ? self::CUT_ABORT : self::CUT;
	}

	/**
	 * @param Job $job Job.
	 */
	public static function askFirst( Job $job ): bool {
		return ! empty( $job->cursor['verify'] ) || (int) ( $job->attempt['count'] ?? 0 ) > 1;
	}

	/**
	 * A passing failure: the next call asks before it sends.
	 *
	 * @param Job $job Job.
	 */
	public static function passing( Job $job ): void {
		$job->cursor['verify'] = true;
	}

	/**
	 * @param Job $job Job.
	 */
	public static function asked( Job $job ): void {
		unset( $job->cursor['verify'] );
	}

	/**
	 * A start or a part arrived.
	 *
	 * @param Job $job Job.
	 */
	public static function progressed( Job $job ): void {
		unset( $job->cursor['deaths'], $job->cursor['verify'] );
	}

	/**
	 * Whether a status answer belongs to the saved upload and lies between
	 * 0 and what was ever sent (the part in flight included).
	 *
	 * @param UploadSession $sent   The saved session.
	 * @param UploadSession $answer The destination's answer.
	 */
	public static function fits( UploadSession $sent, UploadSession $answer ): bool {
		$moving = [
			'offset' => 0,
			'extra'  => 0,
		];
		$same   = array_diff_key( $answer->toArray(), $moving ) === array_diff_key( $sent->toArray(), $moving );

		return $same && $answer->offset >= 0 && $answer->offset <= $sent->offset + $sent->nextLength();
	}

	/**
	 * Whether a fitting status answer is below what the destination had
	 * already confirmed: it lost part of the upload, and sending again from
	 * there could go round in circles, so the upload starts over
	 * (SESSION_GONE, once per job; M7c Task 6 m2).
	 *
	 * @param UploadSession $sent   The saved session.
	 * @param UploadSession $answer The destination's answer.
	 */
	public static function lost( UploadSession $sent, UploadSession $answer ): bool {
		return $answer->offset < $sent->offset;
	}
}
