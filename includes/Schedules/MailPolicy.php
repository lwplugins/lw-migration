<?php
/**
 * Whether a run's result is mailed.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

defined( 'ABSPATH' ) || exit;

/**
 * Only runs started by the schedule itself mail (spec 17: a manual run does
 * not); a cancelled run never does (a person stopped it). "failure" covers
 * failed and skipped runs. While a schedule keeps failing, one failure mail
 * per THROTTLE_SECONDS: the time of the last failure mail (kept beside the
 * log, cleared by a success) throttles however many entries came since; the
 * walk back through the log (stopping at the last success) also counts a
 * mail still pending as sent. The first failure after a success always mails.
 * A partial run (the backup is ready, an upload failed) is mailed like a
 * failure under "failure" (throttled with the failures) and like a success
 * under "success" and "always" (never throttled).
 */
final class MailPolicy {

	public const SEND = 'send';

	public const SKIP = 'skip';

	public const THROTTLE = 'throttle';

	public const THROTTLE_SECONDS = 86400;

	/**
	 * @param string                           $when          Schedule::NOTIFY_* value.
	 * @param string                           $trigger       cron | manual.
	 * @param string                           $status        done | partial | failed | cancelled | skipped.
	 * @param array<int, array<string, mixed>> $previous      Earlier log entries, newest first.
	 * @param int                              $now           Unix time.
	 * @param int                              $failureMailAt When the last failure mail went (0 = none since the last success).
	 */
	public static function decide( string $when, string $trigger, string $status, array $previous, int $now, int $failureMailAt = 0 ): string {
		if ( 'cron' !== $trigger || 'cancelled' === $status || Schedule::NOTIFY_OFF === $when ) {
			return self::SKIP;
		}
		if ( 'done' === $status ) {
			return in_array( $when, [ Schedule::NOTIFY_SUCCESS, Schedule::NOTIFY_ALWAYS ], true ) ? self::SEND : self::SKIP;
		}
		if ( 'partial' === $status && in_array( $when, [ Schedule::NOTIFY_SUCCESS, Schedule::NOTIFY_ALWAYS ], true ) ) {
			return self::SEND; // The backup is ready, so a "success" mail is due; it tells what failed.
		}
		if ( ! in_array( $when, [ Schedule::NOTIFY_FAILURE, Schedule::NOTIFY_ALWAYS ], true ) ) {
			return self::SKIP;
		}
		if ( $failureMailAt > $now - self::THROTTLE_SECONDS ) {
			return self::THROTTLE;
		}
		foreach ( $previous as $entry ) {
			if ( 'done' === ( $entry['status'] ?? '' ) ) {
				break;
			}
			if ( in_array( $entry['mail'] ?? '', [ 'sent', 'pending' ], true ) && (int) ( $entry['endedAt'] ?? 0 ) > $now - self::THROTTLE_SECONDS ) {
				return self::THROTTLE;
			}
		}

		return self::SEND;
	}
}
