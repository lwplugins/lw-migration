<?php
/**
 * Temporary files of an import whose request died.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Maintenance;

use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\SiteFactory;

defined( 'ABSPATH' ) || exit;

/**
 * The finish hook of an unfinished import never ran: before the daily sweep
 * deletes its folder (and the extraction journal in it), its temporary files
 * outside the folder go (journaled extraction folders, the safety backup's
 * temp), and on a network the subsites it created, unless it may have swapped
 * (rulings R2, R4).
 */
final class AbandonedImport {

	/**
	 * @param JobStore      $store       Job store.
	 * @param string        $id          Job id whose folder is about to go.
	 * @param string        $backupsDir  Backups folder.
	 * @param string        $storageRoot Storage root.
	 * @param \Closure|null $log         fn( string $line ): void, the maintenance log (sites that could not be removed).
	 * @return bool False when some temporary file or created site could not be removed.
	 */
	public static function discard( JobStore $store, string $id, string $backupsDir, string $storageRoot, ?\Closure $log = null ): bool {
		$dir = $store->dir( $id );
		if ( ! is_file( $dir . '/job.json' ) ) {
			return true;
		}
		try {
			$job = $store->load( $id );
		} catch ( JobException $e ) {
			return true;
		}
		if ( 'import' !== $job->type || $job->isFinished() ) {
			return true; // Ended jobs were cleaned by their finish hook.
		}
		$staging = $dir . '/' . ImportFiles::STAGING;
		$bases   = static fn( Job $j ): array => ImportPipeline::rules( $j, $staging, $storageRoot )->bases();
		$sites   = self::undoCreatedSites( $store, $job, $dir, $log );

		return ImportCleanup::discardQuietly( $job, $dir, $backupsDir, $bases ) && $sites;
	}

	/**
	 * Removes the sites the import created (unless it may have swapped), saves
	 * what is left of the record and names the sites that stay in the log.
	 *
	 * @param JobStore      $store Job store.
	 * @param Job           $job   Unfinished import.
	 * @param string        $dir   Its work folder (still holding the swap marker).
	 * @param \Closure|null $log   fn( string $line ): void.
	 * @return bool False when a created site could not be removed.
	 */
	private static function undoCreatedSites( JobStore $store, Job $job, string $dir, ?\Closure $log ): bool {
		if ( ! ImportState::onNetwork( $job ) || ImportCleanup::maySwapped( $job, $dir ) ) {
			return true;
		}
		try {
			( new SiteFactory() )->undoCreated( $job, $dir );
		} catch ( \Throwable $e ) {
			unset( $e ); // Reported below through what stays recorded.
		}
		try {
			$store->save( $job );
		} catch ( \Throwable $e ) {
			unset( $e ); // The folder goes with the sweep anyway.
		}
		$left = SiteFactory::created( $job );
		if ( [] !== $left && null !== $log ) {
			$log( sprintf( 'the sites %s an abandoned import created could not be removed: delete them under Network Admin > Sites', implode( ', ', $left ) ) );
		}

		return [] === $left;
	}
}
