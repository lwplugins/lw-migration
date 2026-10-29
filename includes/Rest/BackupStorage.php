<?php
/**
 * How much room the backups take, and which of them get deleted by themselves.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Schedules\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * The storage strip of the Backups tab, from the listing already made (no
 * second scan): the bytes of our folder and of the other .wpress tool's
 * folder, the free space where ours are kept (null when the host does not
 * tell), and the retention rules that delete backups by themselves: first
 * the manual backups' rules (Settings, `schedule` null; all zero = never
 * deleted), then each switched-on schedule's own rules (a schedule deletes
 * only its own backups, and only when it runs). Backups taken before a
 * restore or a reset and the other tool's folder are never deleted.
 */
final class BackupStorage {

	/**
	 * @param array<int, Backup>   $backups   The listed backups.
	 * @param int|null             $free      Free bytes where our backups are kept.
	 * @param Retention            $manual    Rules of the manual backups.
	 * @param array<int, Schedule> $schedules The stored schedules.
	 * @return array{ownBytes: int, otherBytes: int, free: int|null, keep: array<int, array{schedule: string|null, name: string, count: int, bytes: int, days: int}>}
	 */
	public static function of( array $backups, ?int $free, Retention $manual, array $schedules ): array {
		$own   = 0;
		$other = 0;
		foreach ( $backups as $backup ) {
			if ( Backup::OWN === $backup->source ) {
				$own += $backup->size;
			} else {
				$other += $backup->size;
			}
		}
		$keep = [
			[
				'schedule' => null,
				'name'     => '',
			] + $manual->toArray(),
		];
		foreach ( $schedules as $schedule ) {
			if ( $schedule->enabled ) {
				$keep[] = [
					'schedule' => $schedule->id,
					'name'     => Schedules::shownTitle( $schedule ),
				] + Retention::fromOption( $schedule->retention )->toArray();
			}
		}

		return [
			'ownBytes'   => $own,
			'otherBytes' => $other,
			'free'       => $free,
			'keep'       => $keep,
		];
	}
}
