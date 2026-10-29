<?php
/**
 * What a reset that did not finish leaves behind.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\PrefixRules;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\Steps\BackupStep;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * After a failed or cancelled reset: the hidden temp copy and the empty name
 * reservation of an interrupted backup store go, and so does the backup's
 * work folder. A stored backup is never touched (BackupPublisher::discard()
 * removes only our temp names and empty reservations). The temporary
 * tables of a database reset go too (tablesAfterFailure()).
 */
final class ResetCleanup {

	/**
	 * @param Job           $job        Failed or cancelled reset.
	 * @param JobStore      $jobs       Job store.
	 * @param string        $backupsDir Backups folder.
	 * @param \Closure|null $tables     fn( Job $job ): void — the temporary tables of an unswapped database build.
	 */
	public static function afterFailure( Job $job, JobStore $jobs, string $backupsDir, ?\Closure $tables = null ): void {
		$dir = $jobs->dir( $job->id );
		try {
			( new BackupPublisher( $backupsDir ) )->discard( (string) NestedExport::storeTemp( $job->cursor, BackupStep::CURSOR ), NestedExport::storeReserving( $job->cursor, BackupStep::CURSOR ) );
		} finally {
			Files::removeTree( $dir . '/' . BackupStep::WORK_SUB );
			if ( null !== $tables ) {
				$tables( $job );
			}
		}
	}

	/**
	 * Ruling I8: decided by the fresh tables the build recorded (state.built,
	 * else the core tables), never by the marker. While one still waits the
	 * RENAME never ran (it renames all of them at once): the fresh tables go
	 * and the live ones stay. Otherwise the replaced and retired tables go, so
	 * does anything left under the run's temporary prefix, and the fresh site
	 * is settled (the finish step did not get to it). Then the run is forgotten and its marker freed
	 * once nothing of it is left; a reservation whose prefix was never saved
	 * is freed when its marker carries this job's token. A database error
	 * leaves one warning; the daily sweep removes what is left.
	 *
	 * @param Job                $job          Failed or cancelled reset.
	 * @param Connection         $db           Site database.
	 * @param string             $base         Site table prefix.
	 * @param array<int, string> $coreSuffixes WordPress' core table names without prefix.
	 * @param \Closure           $forget       fn( string $runId ): bool.
	 * @param \Closure|null      $settle       fn(): void, the fresh site's settling; given only past the point of no return, run when the RENAME ran.
	 */
	public static function tablesAfterFailure( Job $job, Connection $db, string $base, array $coreSuffixes, \Closure $forget, ?\Closure $settle = null ): void {
		$tmp  = $job->state['tmpPrefix'] ?? null;
		$kept = false;
		try {
			if ( ! is_string( $tmp ) || ! PrefixRules::isValidTempPrefix( $tmp ) ) {
				$candidate = TmpRun::candidate( $job );
				if ( null !== $candidate && RunMarker::heldWith( $db, $candidate['prefix'], $candidate['token'] ) ) {
					RunMarker::releaseIfUnused( $db, $candidate['prefix'], $forget );
				}
				return;
			}
			$swap    = new ResetSwap( $db, $tmp, $base );
			$built   = is_array( $job->state['built'] ?? null ) ? array_values( array_map( 'strval', $job->state['built'] ) ) : $coreSuffixes;
			$swapped = [] === $swap->pending( $built );
			$kept    = $swapped && [] !== $swap->dropOld();
			$swap->dropFresh();
			if ( $swapped && null !== $settle ) {
				$settle();
			}
			RunMarker::releaseIfUnused( $db, $tmp, $forget );
		} catch ( DatabaseException $e ) {
			$kept = true;
		}
		if ( $kept ) {
			$job->addWarning( __( 'Temporary tables of this reset could not all be removed; the daily cleanup removes them.', 'lw-migration' ) );
		}
	}
}
