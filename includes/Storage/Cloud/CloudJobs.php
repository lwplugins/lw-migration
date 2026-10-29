<?php
/**
 * Jobs that only move a backup to or from the cloud.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * Job type `cloud` (plan D9): "Feltöltés…" of an existing backup (spec 21.1,
 * one step, never prunes, never deletes the local copy) and "Letöltés ide"
 * of a remote backup (spec 21.6). Both hold the site lock like every job
 * (JobStarter). The finish hook of every job (Services::finished) calls
 * finished(): an upload left unfinished (also an export's, also one whose
 * local backup changed meanwhile) is aborted at its destination (best
 * effort) and its "pending" copy record turns "failed" (plan D12); a download
 * left unfinished loses its temp.
 */
final class CloudJobs {

	public const TYPE = 'cloud';

	public const UPLOAD = 'upload';

	public const FETCH = 'fetch';

	/**
	 * @param JobStore  $jobs   Job store.
	 * @param SiteLock  $lock   Site lock.
	 * @param Site      $site   Site snapshot.
	 * @param Backup    $backup The backup.
	 * @param CloudPlan $plan   Destinations (CloudPlan::upload()).
	 * @param int       $now    Unix time.
	 * @throws \LightweightPlugins\Migration\WordPress\SiteBusyException When another job holds the site.
	 */
	public static function upload( JobStore $jobs, SiteLock $lock, Site $site, Backup $backup, CloudPlan $plan, int $now ): Job {
		return JobStarter::start(
			$jobs,
			$lock,
			self::TYPE,
			[ CloudUploadStep::KEY ],
			[ 'action' => self::UPLOAD ],
			$now,
			static function ( Job $job ) use ( $jobs, $site, $backup, $plan ): void {
				$job->state['site']   = $site->toArray();
				$job->state['backup'] = [
					'name' => $backup->name,
					'path' => $backup->path,
					'size' => $backup->size,
				];
				$job->state['cloud']  = $plan->toArray();
				$jobs->save( $job );
			}
		);
	}

	/**
	 * @param JobStore   $jobs Job store.
	 * @param SiteLock   $lock Site lock.
	 * @param Site       $site Site snapshot.
	 * @param string     $id   Profile id.
	 * @param RemoteFile $file Remote backup.
	 * @param int        $now  Unix time.
	 * @throws \LightweightPlugins\Migration\WordPress\SiteBusyException When another job holds the site.
	 */
	public static function fetch( JobStore $jobs, SiteLock $lock, Site $site, string $id, RemoteFile $file, int $now ): Job {
		return JobStarter::start(
			$jobs,
			$lock,
			self::TYPE,
			[ CloudDownloadStep::KEY ],
			[ 'action' => self::FETCH ],
			$now,
			static function ( Job $job ) use ( $jobs, $site, $id, $file ): void {
				$job->state['site']        = $site->toArray();
				$job->state['cloudSource'] = self::source( $id, $file );
				$jobs->save( $job );
			}
		);
	}

	/**
	 * State.cloudSource of a fetch or a cloud restore.
	 *
	 * @param string     $id   Profile id.
	 * @param RemoteFile $file Remote backup.
	 * @return array{id: string, file: array{name: string, size: int, mtime: int, ref: string}}
	 */
	public static function source( string $id, RemoteFile $file ): array {
		return [
			'id'   => $id,
			'file' => $file->toArray(),
		];
	}

	/**
	 * The cloud part of the finish hook of every job type (export, import,
	 * cloud): nothing for a job that is done.
	 *
	 * @param Services $services WordPress wiring.
	 * @param Job      $job      Ended job.
	 */
	public static function finished( Services $services, Job $job ): void {
		if ( Job::DONE === $job->status ) {
			return;
		}
		if ( CloudUploadStep::KEY === $job->currentStep() ) {
			self::abortUpload( Destinations::fromWordPress(), $job );
		}
		if ( CloudDownloadStep::KEY === $job->currentStep() ) {
			( new BackupPublisher( $services->storage()->backupsDir() ) )->discard( (string) ( $job->cursor['temp'] ?? '' ), (string) ( $job->cursor['reserving'] ?? '' ) );
		}
	}

	/**
	 * The destination at cursor.index: its "pending" copy record of the backup
	 * turns "failed" (also when no upload was started yet), and a started
	 * upload (cursor.session) is aborted there, best effort.
	 *
	 * @param Destinations $destinations Profiles.
	 * @param Job          $job          Failed or cancelled job.
	 */
	public static function abortUpload( Destinations $destinations, Job $job ): void {
		$plan = CloudPlan::of( $job );
		$id   = null === $plan ? null : ( $plan->targets[ (int) ( $job->cursor['index'] ?? 0 ) ]['id'] ?? null );
		if ( null === $id ) {
			return;
		}
		$stored = UploadSession::fromArray( (array) ( $job->cursor['session'] ?? [] ) );
		$name   = null === $stored ? (string) ( $job->state['backup']['name'] ?? '' ) : $stored->name;
		if ( '' !== $name && CloudCopies::PENDING === ( CloudCopies::of( $name )[ $id ]['status'] ?? null ) ) {
			CloudCopies::mark( $name, $id, CloudCopies::FAILED, time() );
		}
		$profile = null === $stored ? null : $destinations->find( $id );
		$session = null === $profile ? null : UploadCursor::session( $job, $profile );
		if ( null === $profile || null === $session ) {
			return; // Gone, or started under another connection: nothing is sent to its address (final review I1).
		}
		try {
			$destinations->open( $profile )->abort( $session );
		} catch ( CloudFailure $e ) {
			unset( $e ); // Best effort: providers expire unfinished uploads (21.10).
		}
	}
}
