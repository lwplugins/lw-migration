<?php
/**
 * Creates reset jobs and answers questions about them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Database\TmpRun;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\Steps\DatabaseFinishStep;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * A reset job holds the site lock from its creation (JobStarter: a busy site
 * leaves nothing behind) and keeps the site snapshot and the administrator
 * who started it: id and login, never the password. Its point of no return
 * is the swap marker of the database steps (the same file an import writes).
 */
final class ResetJobs {

	public const TYPE = 'reset';

	/**
	 * A busy site throws SiteBusyException through JobStarter (nothing is left behind).
	 *
	 * @param JobStore             $jobs   Job store.
	 * @param SiteLock             $lock   Site lock.
	 * @param Site                 $site   Site snapshot.
	 * @param string               $type   One of ResetType::ALL.
	 * @param bool                 $backup Whether a backup runs first.
	 * @param array<string, mixed> $admin  The administrator who starts it: {id: int, login: string}.
	 * @param int                  $now    Unix time.
	 * @return Job
	 * @throws \InvalidArgumentException For an unknown type or a missing administrator (before the lock).
	 */
	public static function create( JobStore $jobs, SiteLock $lock, Site $site, string $type, bool $backup, array $admin, int $now ): Job {
		if ( ! ResetType::isValid( $type ) ) {
			throw new \InvalidArgumentException( __( 'Choose what to reset.', 'lw-migration' ) );
		}
		$id    = (int) ( $admin['id'] ?? 0 );
		$login = (string) ( $admin['login'] ?? '' );
		if ( $id <= 0 || '' === $login ) {
			throw new \InvalidArgumentException( ResetTexts::refusal( ResetRefused::NO_USER ) );
		}

		return JobStarter::start(
			$jobs,
			$lock,
			self::TYPE,
			ResetType::steps( $type, $backup ),
			[
				'type'   => $type,
				'backup' => $backup,
			],
			$now,
			static function ( Job $job ) use ( $jobs, $site, $id, $login ): void {
				$job->state['site']  = $site->toArray();
				$job->state['admin'] = [
					'id'    => $id,
					'login' => $login,
				];
				$jobs->save( $job );
			}
		);
	}

	/**
	 * @param Job $job Reset job.
	 * @return string One of ResetType::ALL.
	 * @throws JobException When the job carries no valid type.
	 */
	public static function type( Job $job ): string {
		$type = $job->options['type'] ?? null;
		if ( ! is_string( $type ) || ! ResetType::isValid( $type ) ) {
			throw new JobException( 'The reset job has no valid type.' );
		}

		return $type;
	}

	/**
	 * @param Job $job Reset job.
	 * @return array{id: int, login: string}
	 */
	public static function admin( Job $job ): array {
		$admin = is_array( $job->state['admin'] ?? null ) ? $job->state['admin'] : [];

		return [
			'id'    => (int) ( $admin['id'] ?? 0 ),
			'login' => (string) ( $admin['login'] ?? '' ),
		];
	}

	/**
	 * Whether the database swap may have started: from then on the job is
	 * finished, never cancelled or rolled back.
	 *
	 * @param Job    $job Reset job.
	 * @param string $dir The job's folder.
	 */
	public static function pastNoReturn( Job $job, string $dir ): bool {
		return ! empty( $job->state['swapped'] ) || is_file( $dir . '/' . ImportFiles::SWAP_STARTED );
	}

	/**
	 * The Runner's cancel guard for a reset job.
	 *
	 * @param Services $services Services.
	 * @param Job      $job      Any job.
	 */
	public static function defersCancel( Services $services, Job $job ): bool {
		return self::TYPE === $job->type && self::pastNoReturn( $job, $services->jobs()->dir( $job->id ) );
	}

	/**
	 * The finish hook of a reset job (cleanup after a failure or cancel): the
	 * backup's leftovers, then the temporary tables of the database part;
	 * warnings the cleanup adds are saved.
	 *
	 * @param Services $services Services.
	 * @param Job      $job      Any ended job.
	 * @return void
	 */
	public static function finished( Services $services, Job $job ): void {
		if ( self::TYPE !== $job->type || Job::DONE === $job->status ) {
			return;
		}
		$warnings = count( $job->warnings );
		try {
			ResetCleanup::afterFailure(
				$job,
				$services->jobs(),
				$services->storage()->backupsDir(),
				static function ( Job $failed ) use ( $services ): void {
					if ( empty( $failed->state['tmpPrefix'] ) && null === TmpRun::candidate( $failed ) ) {
						return; // No database part ran: nothing to connect for.
					}
					ResetCleanup::tablesAfterFailure(
						$failed,
						$services->connection(),
						Site::fromArray( (array) ( $failed->state['site'] ?? [] ) )->tablePrefix,
						ResetTables::coreSuffixes(),
						static fn( string $run ): bool => ( new TmpRunRegistry() )->forget( $run ),
						self::pastNoReturn( $failed, $services->jobs()->dir( $failed->id ) ) ? static function () use ( $services ): void {
							DatabaseFinishStep::settle( $services );
						} : null
					);
				}
			);
		} finally {
			if ( count( $job->warnings ) !== $warnings ) {
				$services->jobs()->save( $job ); // The Runner saved the job before this hook.
			}
		}
	}
}
