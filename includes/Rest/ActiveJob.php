<?php
/**
 * Finds the job the admin screen should show.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;

defined( 'ABSPATH' ) || exit;

/**
 * The job holding the site lock when it is unfinished; otherwise the most
 * recently updated unfinished one (a tab was closed, a CLI process was killed
 * at a prompt). Finished jobs are never offered: their finish hook already ran.
 * The daily sweep removes job folders older than a day, so the scan stays short.
 */
final class ActiveJob {

	/**
	 * @param JobStore    $jobs   Job store.
	 * @param string|null $holder Job id holding the site lock (SiteLock::holder()).
	 */
	public static function find( JobStore $jobs, ?string $holder ): ?Job {
		if ( null !== $holder ) {
			$job = self::load( $jobs, $holder );
			if ( null !== $job && ! $job->isFinished() ) {
				return $job;
			}
		}
		$best = null;
		foreach ( $jobs->ids() as $id ) {
			$job = self::load( $jobs, $id );
			if ( null !== $job && ! $job->isFinished() && ( null === $best || $job->updatedAt > $best->updatedAt ) ) {
				$best = $job;
			}
		}

		return $best;
	}

	/**
	 * Whether an unfinished restore reads this backup (it must not be deleted
	 * under it): a restore of a local backup, or a cloud restore of that name
	 * (its download lands in the backups folder).
	 *
	 * @param JobStore $jobs   Job store.
	 * @param string   $name   Backup name.
	 * @param string   $folder Backup::OWN or Backup::EXTERNAL.
	 */
	public static function readsBackup( JobStore $jobs, string $name, string $folder ): bool {
		foreach ( $jobs->ids() as $id ) {
			$job = self::load( $jobs, $id );
			if ( null === $job || $job->isFinished() || 'import' !== $job->type ) {
				continue;
			}
			$source   = (string) ( $job->options['source'] ?? '' );
			$theirs   = (string) ( $job->options['folder'] ?? '' );
			$location = (string) ( $job->options['location'] ?? '' );
			if ( ImportOptions::BACKUP === $source && $location === $name && ( '' === $theirs || $folder === $theirs ) ) {
				return true;
			}
			// A cloud restore downloads into the backups folder and then reads its download.
			$downloaded = (string) ( ( (array) ( $job->state['backup'] ?? [] ) )['name'] ?? '' );
			if ( ImportOptions::CLOUD === $source && Backup::OWN === $folder && in_array( $name, [ $location, $downloaded ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether another job was started after this one was last saved and has
	 * run since (it is no longer pending, or it was saved after its creation):
	 * resuming this one would write data older than what that job saw or wrote.
	 *
	 * @param JobStore $jobs Job store.
	 * @param Job      $job  The job to resume.
	 */
	public static function ranAfter( JobStore $jobs, Job $job ): bool {
		foreach ( $jobs->ids() as $id ) {
			$other = $id === $job->id ? null : self::load( $jobs, $id );
			if ( null !== $other && $other->createdAt > $job->updatedAt && ( Job::PENDING !== $other->status || $other->updatedAt > $other->createdAt ) ) {
				return true;
			}
		}

		return false;
	}

	private static function load( JobStore $jobs, string $id ): ?Job {
		try {
			return $jobs->load( $id );
		} catch ( JobException $e ) {
			return null;
		}
	}
}
