<?php
/**
 * What a schedule looks like over REST.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Schedules\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Whitelisted fields only: never the sealed password (hasPassword instead),
 * never the anchor or the timestamps of the row. The export options come
 * back complete with their defaults, in the shape the Backup section's form
 * sends (JobInput::exportOptions()). A disabled schedule has no next run.
 * The cloud part: the destinations with their retention, keepLocal, and the
 * last run's per-destination results. The last run's `error` is the log's
 * own text (RunRecorder redacts it). `week`: the run times of the next 7
 * days (NextRun::within(); empty when off). `history`: the last HISTORY log
 * entries, oldest first, as {status, endedAt, trigger}.
 */
final class ScheduleView {

	/** Entries in `history`. */
	public const HISTORY = 14;

	private const LISTS = [ 'excludeFiles', 'excludeTables', 'includeTables' ];

	/**
	 * @param Schedule                  $schedule Schedule.
	 * @param int|null                  $nextRun  Time of its WP-Cron event, or null.
	 * @param array<int, int>           $upcoming Next run times (NextRun::upcoming()).
	 * @param array<string, mixed>|null $last     Newest log entry.
	 * @param bool                      $running  Whether its job is running now.
	 * @param array<int, int>           $week     Run times of the next 7 days.
	 * @param array<int, array>         $log      Log entries, newest first (Log::all()).
	 * @return array<string, mixed>
	 */
	public static function of( Schedule $schedule, ?int $nextRun, array $upcoming, ?array $last, bool $running, array $week = [], array $log = [] ): array {
		return [
			'id'           => $schedule->id,
			'title'        => $schedule->title,
			'enabled'      => $schedule->enabled,
			'frequency'    => $schedule->frequency,
			'every'        => $schedule->every,
			'minute'       => $schedule->minute,
			'hour'         => $schedule->hour,
			'weekday'      => $schedule->weekday,
			'monthday'     => $schedule->monthday,
			'export'       => self::export( $schedule->export ),
			'hasPassword'  => '' !== $schedule->password,
			'retention'    => $schedule->retention,
			'destinations' => $schedule->destinations,
			'keepLocal'    => $schedule->keepLocal,
			'notify'       => [
				'when'  => $schedule->notifyWhen,
				'email' => $schedule->notifyEmail,
			],
			'nextRun'      => $schedule->enabled ? $nextRun : null,
			'upcoming'     => $schedule->enabled ? array_values( $upcoming ) : [],
			'lastRun'      => null === $last ? null : [
				'status'  => (string) $last['status'],
				'endedAt' => (int) $last['endedAt'],
				'backup'  => (string) $last['backup'],
				'size'    => (int) $last['size'],
				'trigger' => (string) $last['trigger'],
				'error'   => (string) ( $last['error'] ?? '' ), // Already redacted by RunRecorder.
				'cloud'   => self::cloud( $last['cloud'] ?? null ),
			],
			'running'      => $running,
			'week'         => $schedule->enabled ? array_values( $week ) : [],
			'history'      => self::history( $log ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $log Entries, newest first.
	 * @return array<int, array{status: string, endedAt: int, trigger: string}> Oldest first.
	 */
	private static function history( array $log ): array {
		$rows = [];
		foreach ( array_reverse( array_slice( array_values( $log ), 0, self::HISTORY ) ) as $entry ) {
			$rows[] = [
				'status'  => (string) $entry['status'],
				'endedAt' => (int) $entry['endedAt'],
				'trigger' => (string) $entry['trigger'],
			];
		}

		return $rows;
	}

	/**
	 * The last run's destinations (RunRecorder): name, status and error only.
	 *
	 * @param mixed $stored Stored log entries.
	 * @return array<int, array{name: string, status: string, error: string}>
	 */
	private static function cloud( $stored ): array {
		$rows = [];
		foreach ( is_array( $stored ) ? $stored : [] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = [
					'name'   => (string) ( $row['name'] ?? '' ),
					'status' => (string) ( $row['status'] ?? '' ),
					'error'  => (string) ( $row['error'] ?? '' ),
				];
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $stored Stored export options.
	 * @return array<string, mixed>
	 */
	private static function export( array $stored ): array {
		$view = [];
		foreach ( ExportOptions::FLAGS as $prop ) {
			$view[ $prop ] = true === ( $stored[ $prop ] ?? false );
		}
		$view['encrypt'] = true === ( $stored['encrypt'] ?? false );
		foreach ( self::LISTS as $key ) {
			$view[ $key ] = array_values( array_filter( (array) ( $stored[ $key ] ?? [] ), 'is_string' ) );
		}
		$view['replace'] = [];
		foreach ( (array) ( $stored['replace'] ?? [] ) as $pair ) {
			if ( is_array( $pair ) && is_string( $pair[0] ?? null ) && is_string( $pair[1] ?? null ) ) {
				$view['replace'][] = [ $pair[0], $pair[1] ];
			}
		}
		$view['compression'] = is_string( $stored['compression'] ?? null ) ? $stored['compression'] : '';
		$view['sites']       = array_values( array_filter( (array) ( $stored['sites'] ?? [] ), static fn( $id ): bool => is_int( $id ) && $id > 0 ) ); // [] = the whole network.

		return $view;
	}
}
