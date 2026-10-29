<?php
/**
 * What made a backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Told by the folder and the file name only (nothing is opened): anything
 * in the other .wpress tool's folder is OTHER; in ours, the backup taken
 * before a restore or a reset by its fixed name, a schedule's backup by
 * the schedule id at the end of its name, everything else (a backup made
 * by hand, a file copied in) MANUAL.
 */
final class BackupKind {

	public const SCHEDULED = 'scheduled';

	public const MANUAL = 'manual';

	public const SAFETY_RESTORE = 'safetyRestore';

	public const SAFETY_RESET = 'safetyReset';

	public const OTHER = 'other';

	public static function of( Backup $backup ): string {
		if ( Backup::OWN !== $backup->source ) {
			return self::OTHER;
		}
		if ( BackupName::isPreReset( $backup->name ) ) {
			return self::SAFETY_RESET;
		}
		if ( BackupName::isSafety( $backup->name ) ) {
			return self::SAFETY_RESTORE;
		}

		return null === BackupName::scheduleOf( $backup->name ) ? self::MANUAL : self::SCHEDULED;
	}
}
