<?php
/**
 * The backups the overview counts: the ones on disk, the ones the schedule
 * logs remember, the ones only a destination still holds.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;

defined( 'ABSPATH' ) || exit;

/**
 * Read from state that is already there, nothing is opened or listed twice:
 * the backups folder listing (one scan, Repository::all()), the record of
 * the uploads (CloudCopies, only for destinations that still exist) and the
 * run logs of the schedules (Schedules\Log). A name counts once, the local
 * file first; a run whose backup is gone (pruned, or kept only in the cloud)
 * still shows the day it was taken. A failed or skipped run of a schedule is
 * an item of its own with size 0. A backup only a destination holds (no
 * local file, no log entry) has no known size (null). `state`: cloud when a
 * destination holds it (or held it, by its run's log), local otherwise,
 * failed for a run that made none; `exists`: on disk or recorded as
 * uploaded now.
 */
final class OverviewBackups {

	public const CLOUD = 'cloud';

	public const LOCAL = 'local';

	public const FAILED = 'failed';

	/** Run statuses that made no backup. */
	private const NO_BACKUP = [ Job::FAILED, RunRecorder::SKIPPED ];

	/** Run statuses that made one. */
	private const MADE = [ Job::DONE, RunRecorder::PARTIAL ];

	/**
	 * @param array<int, Backup>                                           $local  The backups on disk, newest first.
	 * @param array<string, array<string, array{status: string, at: int}>> $copies CloudCopies::all().
	 * @param array<string, true>                                          $known  Ids of the stored destinations.
	 * @param array<int, array<string, mixed>>                             $runs   Every schedule's log entries.
	 * @return array<int, array{name: string, time: int, size: int|null, local: bool, cloud: array<int, array{id: string, status: string}>, manual: bool, state: string, partial: bool, exists: bool}> Newest first.
	 */
	public static function collect( array $local, array $copies, array $known, array $runs ): array {
		$items = [];
		$made  = [];
		foreach ( $runs as $run ) {
			$name = (string) ( $run['backup'] ?? '' );
			if ( in_array( $run['status'] ?? '', self::MADE, true ) && '' !== $name ) {
				$made[ $name ] = $run;
			}
		}
		foreach ( $local as $backup ) {
			if ( ! isset( $items[ $backup->name ] ) ) {
				$items[ $backup->name ] = self::item( $backup->name, $backup->mtime, $backup->size, true, $copies, $known, $made[ $backup->name ] ?? null );
			}
		}
		foreach ( $made as $name => $run ) {
			if ( ! isset( $items[ $name ] ) ) {
				$items[ $name ] = self::item( $name, (int) $run['endedAt'], (int) $run['size'], false, $copies, $known, $run );
			}
		}
		foreach ( $copies as $name => $where ) {
			$name = (string) $name;
			if ( ! isset( $items[ $name ] ) && [] !== self::uploaded( $where, $known ) ) {
				$items[ $name ] = self::item( $name, BackupName::timeOf( $name ) ?? max( array_column( $where, 'at' ) ), null, false, $copies, $known, null );
			}
		}
		$list = array_values( $items );
		foreach ( $runs as $run ) {
			if ( in_array( $run['status'] ?? '', self::NO_BACKUP, true ) ) {
				$list[] = [
					'name'    => '',
					'time'    => (int) ( $run['endedAt'] ?? 0 ),
					'size'    => 0,
					'local'   => false,
					'cloud'   => [],
					'manual'  => false,
					'state'   => self::FAILED,
					'partial' => false,
					'exists'  => false,
				];
			}
		}
		usort( $list, static fn( array $a, array $b ): int => [ $b['time'], $a['name'] ] <=> [ $a['time'], $b['name'] ] );

		return $list;
	}

	/**
	 * The newest backup that still exists: on disk, or recorded as uploaded
	 * to a stored destination (a run whose backup was pruned or deleted
	 * since is history only).
	 *
	 * @param array<int, array<string, mixed>> $items collect().
	 * @return array<string, mixed>|null
	 */
	public static function newest( array $items ): ?array {
		foreach ( $items as $item ) {
			if ( $item['exists'] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Whether a run failed after this time.
	 *
	 * @param array<int, array<string, mixed>> $items collect().
	 * @param int                              $time  Unix time.
	 */
	public static function failedSince( array $items, int $time ): bool {
		foreach ( $items as $item ) {
			if ( self::FAILED === $item['state'] && $item['time'] > $time ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string                                                       $name   Backup name.
	 * @param int                                                          $time   Unix time.
	 * @param int|null                                                     $size   Bytes (null = not known).
	 * @param bool                                                         $local  Whether the file is on disk.
	 * @param array<string, array<string, array{status: string, at: int}>> $copies CloudCopies::all().
	 * @param array<string, true>                                          $known  Stored destination ids.
	 * @param array<string, mixed>|null                                    $run    Its log entry, if a schedule made it.
	 * @return array{name: string, time: int, size: int|null, local: bool, cloud: array<int, array{id: string, status: string}>, manual: bool, state: string, partial: bool, exists: bool}
	 */
	private static function item( string $name, int $time, ?int $size, bool $local, array $copies, array $known, ?array $run ): array {
		$cloud = [];
		foreach ( $copies[ $name ] ?? [] as $id => $copy ) {
			if ( isset( $known[ (string) $id ] ) ) {
				$cloud[] = [
					'id'     => (string) $id, // An all-digit id became an integer key.
					'status' => $copy['status'],
				];
			}
		}
		$held = in_array( CloudCopies::UP, array_column( $cloud, 'status' ), true );

		return [
			'name'    => $name,
			'time'    => $time,
			'size'    => $size,
			'local'   => $local,
			'cloud'   => $cloud,
			'manual'  => null === BackupName::scheduleOf( $name ),
			'state'   => $held || ( ! $local && self::uploadedByRun( $run ) ) ? self::CLOUD : self::LOCAL,
			'partial' => RunRecorder::PARTIAL === ( $run['status'] ?? null ) || in_array( CloudCopies::FAILED, array_column( $cloud, 'status' ), true ),
			'exists'  => $local || $held,
		];
	}

	/**
	 * @param array<array-key, array{status: string, at: int}> $where  One name's CloudCopies row.
	 * @param array<string, true>                              $known  Stored destination ids.
	 * @return array<int, array{status: string, at: int}> Its uploads to a stored destination.
	 */
	private static function uploaded( array $where, array $known ): array {
		return array_values( array_filter( $where, static fn( array $copy, $id ): bool => isset( $known[ (string) $id ] ) && CloudCopies::UP === $copy['status'], ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * Whether the run's log says an upload succeeded (its record may be gone
	 * since: the destination's retention pruned it).
	 *
	 * @param array<string, mixed>|null $run Log entry.
	 */
	private static function uploadedByRun( ?array $run ): bool {
		foreach ( is_array( $run['cloud'] ?? null ) ? $run['cloud'] : [] as $row ) {
			if ( is_array( $row ) && 'ok' === ( $row['status'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}
}
