<?php
/**
 * This site's backups among a destination's files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Only names BackupName wrote for this site (isOfSite(), plan D7) are
 * candidates; Retention::prune() then applies its own rules (time-stamped,
 * not a safety backup, the scope: manual or one schedule, the newest and
 * $keep stay). Foreign files are never returned.
 */
final class RemoteBackups {

	/**
	 * @param array<int, RemoteFile> $files   A destination's files.
	 * @param string                 $homeUrl This site's address.
	 * @return array<string, RemoteFile> Name => file.
	 */
	public static function ours( array $files, string $homeUrl ): array {
		$ours = [];
		foreach ( $files as $file ) {
			if ( BackupName::isOfSite( $file->name, $homeUrl ) ) {
				$ours[ $file->name ] = $file;
			}
		}

		return $ours;
	}

	/**
	 * @param array<int, RemoteFile> $files     A destination's files.
	 * @param string                 $homeUrl   This site's address.
	 * @param Retention              $retention Rules.
	 * @param int                    $now       Unix time.
	 * @param string                 $keep      The backup just uploaded.
	 * @param string|null            $schedule  Scope: null = manual backups, an id = that schedule's.
	 * @return array<int, RemoteFile> Files to delete, newest first.
	 */
	public static function toPrune( array $files, string $homeUrl, Retention $retention, int $now, string $keep, ?string $schedule ): array {
		$ours    = self::ours( $files, $homeUrl );
		$backups = array_map( static fn( RemoteFile $f ): Backup => new Backup( $f->name, $f->ref, max( 1, $f->size ), $f->mtime, Backup::OWN ), array_values( $ours ) );
		$delete  = [];
		foreach ( $retention->prune( $backups, $now, $keep, $schedule ) as $name ) {
			if ( $name !== $keep && isset( $ours[ $name ] ) ) {
				$delete[] = $ours[ $name ];
			}
		}

		return $delete;
	}
}
