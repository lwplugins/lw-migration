<?php
/**
 * The schedule the overview shows.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * The switched-on schedule that runs next (its WP-Cron event, like
 * ScheduleView), else the first stored one with no next run; null when
 * there is none (the stored list is read, the default schedule is never
 * created here). Whether it is switched on (an enabled schedule can still
 * have no planned event) and the longest regular gap between two of its runs,
 * so the page can tell a late backup from a recent one. Its destinations by name (the ones that still exist), its
 * retention as a short text, and its run log (Schedules\Log, newest first):
 * a finished run is ok, a backup with a failed upload partial, a run that
 * failed or never started failed; a cancelled run is left out. The reason is
 * the log's error text, already stripped of paths and addresses by the
 * recorder.
 */
final class OverviewSchedule {

	/**
	 * @param array<int, Schedule>                            $schedules Stored schedules.
	 * @param array<string, array<int, array<string, mixed>>> $logs     Log entries per schedule id.
	 * @param array<string, string>                           $names     Destination names by id.
	 * @param \Closure                                        $nextRun   fn( string $id ): ?int — its WP-Cron event.
	 * @return array{next: int|null, enabled: bool, interval: int, name: string, destinations: array<int, string>, keep: string, runs: array<int, array{time: int, outcome: string, reason: string|null}>}|null
	 */
	public static function of( array $schedules, array $logs, array $names, \Closure $nextRun ): ?array {
		$shown = null;
		$next  = null;
		foreach ( $schedules as $schedule ) {
			$at = $schedule->enabled ? $nextRun( $schedule->id ) : null;
			if ( null === $shown || ( null !== $at && ( null === $next || $at < $next ) ) ) {
				$shown = $schedule;
				$next  = $at;
			}
		}
		if ( null === $shown ) {
			return null;
		}
		$runs = [];
		foreach ( $logs[ $shown->id ] ?? [] as $entry ) {
			$run = self::run( $entry );
			if ( null !== $run ) {
				$runs[] = $run;
			}
		}

		return [
			'next'         => $next,
			'enabled'      => $shown->enabled,
			'interval'     => self::interval( $shown ),
			'name'         => Repository::shownTitle( $shown ),
			'destinations' => array_values( array_filter( array_map( static fn( array $d ): ?string => $names[ $d['id'] ] ?? null, $shown->destinations ), 'is_string' ) ),
			'keep'         => self::keep( $shown->retention ),
			'runs'         => $runs,
		];
	}

	/**
	 * Seconds between two runs at most, in the regular rhythm: every N hours
	 * or days as set, a week, and 31 days for a monthly run (the longest
	 * month). An unknown frequency counts as daily, like NextRun.
	 *
	 * @param Schedule $schedule Schedule.
	 */
	public static function interval( Schedule $schedule ): int {
		$every = max( 1, $schedule->every );

		return match ( $schedule->frequency ) {
			Schedule::HOURLY  => HOUR_IN_SECONDS,
			Schedule::HOURS   => $every * HOUR_IN_SECONDS,
			Schedule::DAYS    => $every * DAY_IN_SECONDS,
			Schedule::WEEKLY  => 7 * DAY_IN_SECONDS,
			Schedule::MONTHLY => 31 * DAY_IN_SECONDS,
			default           => DAY_IN_SECONDS,
		};
	}

	/**
	 * The retention as a short phrase ("7 days", "the last 7 backups").
	 *
	 * @param array{count: int, bytes: int, days: int} $retention Retention.
	 */
	public static function keep( array $retention ): string {
		$parts = [];
		if ( $retention['days'] > 0 ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( _n( '%d day', '%d days', $retention['days'], 'lw-migration' ), $retention['days'] );
		}
		if ( $retention['count'] > 0 ) {
			/* translators: %d: number of backups */
			$parts[] = sprintf( _n( 'the last %d backup', 'the last %d backups', $retention['count'], 'lw-migration' ), $retention['count'] );
		}
		if ( $retention['bytes'] > 0 ) {
			/* translators: %s: a size, e.g. 20 GB */
			$parts[] = sprintf( __( '%s in total', 'lw-migration' ), (string) size_format( $retention['bytes'] ) );
		}

		return [] === $parts ? __( 'every backup', 'lw-migration' ) : implode( ', ', $parts );
	}

	/**
	 * @param array<string, mixed> $entry A log entry.
	 * @return array{time: int, outcome: string, reason: string|null}|null Null for a cancelled run.
	 */
	private static function run( array $entry ): ?array {
		$status  = (string) ( $entry['status'] ?? '' );
		$outcome = [
			Job::DONE            => 'ok',
			RunRecorder::PARTIAL => 'partial',
			Job::FAILED          => 'failed',
			RunRecorder::SKIPPED => 'failed',
		][ $status ] ?? null;
		if ( null === $outcome ) {
			return null;
		}
		$reason = trim( (string) ( $entry['error'] ?? '' ) );
		if ( 'partial' === $outcome && '' === $reason ) {
			$failed = array_column( array_filter( is_array( $entry['cloud'] ?? null ) ? $entry['cloud'] : [], static fn( $row ): bool => is_array( $row ) && 'failed' === ( $row['status'] ?? null ) ), 'name' );
			/* translators: %s: names of the destinations, comma separated */
			$reason = [] === $failed ? '' : sprintf( __( 'Upload failed: %s', 'lw-migration' ), implode( ', ', $failed ) );
		}

		return [
			'time'    => (int) ( $entry['endedAt'] ?? 0 ),
			'outcome' => $outcome,
			'reason'  => 'ok' === $outcome || '' === $reason ? null : $reason,
		];
	}
}
