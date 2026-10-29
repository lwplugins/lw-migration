<?php
/**
 * Steps of push and pull jobs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Link\Steps\PullDownloadStep;
use LightweightPlugins\Migration\Link\Steps\PushCleanStep;
use LightweightPlugins\Migration\Link\Steps\PushUploadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * The steps of a push (the export steps with this module's own) and of a
 * pull (the two before the import's), and the best-effort release of the
 * other site's job after a run that did not finish.
 */
final class LinkPipeline {

	/** Seconds the release of the other site's job may take. */
	public const RELEASE_TIMEOUT = 10;

	/** Seconds the other site's job may show the same state before a push or pull gives up on it. */
	public const STALL_SECONDS = 2 * SiteLock::TTL;

	/**
	 * @param Services $services Services.
	 * @return array<string, StepInterface>
	 */
	public static function pushSteps( Services $services ): array {
		$clients = LinkClients::fromWordPress();
		$now     = static fn(): int => time();

		return array_merge(
			array_intersect_key( ExportPipeline::steps( $services ), array_flip( ExportPipeline::LINK_STEPS ) ),
			[
				PushUploadStep::KEY     => new PushUploadStep( $services->jobs(), $clients, $now ),
				RemoteJobStep::PUSH_KEY => new RemoteJobStep( $clients, false, $now ),
				PushCleanStep::KEY      => new PushCleanStep( $services->jobs() ),
			]
		);
	}

	/**
	 * The two steps a pull runs before the import's own: the other site's
	 * export, then the signed download of its archive.
	 *
	 * @param Services $services  Services.
	 * @param \Closure $freeSpace fn( string $dir ): ?int.
	 * @return array<string, StepInterface>
	 */
	public static function pullSteps( Services $services, \Closure $freeSpace ): array {
		$clients = LinkClients::fromWordPress();
		$now     = static fn(): int => time();

		return [
			RemoteJobStep::PULL_KEY => new RemoteJobStep( $clients, true, $now ),
			PullDownloadStep::KEY   => new PullDownloadStep( $services->jobs(), $clients, $freeSpace, $now, null, PushUploadStep::MAX_CHUNK ), // Parts as large as a push sends (8 MiB).
		];
	}

	/**
	 * Whether a cancel of this push must be deferred: only from the
	 * confirmation this site itself forwarded (the other site may start
	 * replacing its content any moment) until the other site reports its end
	 * (ruling I4). The other site's own claims never decide it: an answer may
	 * be forged on an http connection, so its pastNoReturn is only shown, and
	 * once the other site has not changed for STALL_SECONDS the cancel is
	 * honoured (RemoteJobStep fails such a push on its next call anyway).
	 *
	 * @param Job      $job Job.
	 * @param int|null $now Unix time (default now).
	 */
	public static function pastNoReturn( Job $job, ?int $now = null ): bool {
		if ( PushJobs::TYPE !== $job->type ) {
			return false;
		}
		$remote  = (array) ( $job->state['remote'] ?? [] );
		$ended   = in_array( $remote['status'] ?? null, [ Job::DONE, Job::FAILED, Job::CANCELLED ], true );
		$changed = $remote['changedAt'] ?? null;
		$stalled = is_int( $changed ) && ( $now ?? time() ) - $changed >= self::STALL_SECONDS;

		return ! $ended && ! $stalled && true === ( $remote['confirmed'] ?? null );
	}

	/**
	 * After a push or pull that failed or was cancelled: the other site
	 * cancels its job or drops its archive. Best effort, RELEASE_TIMEOUT
	 * seconds; a cancel the other site deferred becomes a warning here (the
	 * finish hook saves it).
	 *
	 * @param Job $job Ended job.
	 */
	public static function release( Job $job ): void {
		$remote = (string) ( $job->state['remote']['job'] ?? '' );
		if ( '' === $remote ) {
			return;
		}
		try {
			$answer = LinkClients::fromWordPress()->forJob( $job )->json( 'DELETE', '/jobs/' . $remote, null, self::RELEASE_TIMEOUT )['json'];
			if ( true === ( $answer['cancelDeferred'] ?? null ) ) {
				$job->addWarning( __( 'The other site had already started replacing its content; open its LW Migration screen to finish it.', 'lw-migration' ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The other site's lock expires on its own; its daily sweep removes the job folder.
		}
	}
}
