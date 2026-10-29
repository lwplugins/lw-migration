<?php
/**
 * Deletes the local backup once it is safe in every destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud\Steps;

use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.1: only when "keep the local copy" is off AND every destination
 * of the plan ended ok and the file is still the one that was uploaded (not a
 * link, same size); a failed or skipped one keeps the local copy, with a
 * warning that says so (Review Focus 4). Deletes through the backups
 * repository (our own folder only, both folders during a move) and forgets
 * the label. state.backup.local = false tells the views the backup now lives
 * only in the cloud.
 */
final class LocalCleanupStep implements StepInterface {

	public const KEY = 'local_cleanup';

	/**
	 * @param \Closure $backups fn(): \LightweightPlugins\Migration\Backups\Repository.
	 */
	public function __construct( private \Closure $backups ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$plan = CloudPlan::of( $job );
		$name = (string) ( $job->state['backup']['name'] ?? '' );
		if ( null === $plan || $plan->keepLocal || '' === $name ) {
			return StepResult::done();
		}
		if ( ! CloudOutcome::allOk( $job, $plan ) ) {
			$job->addWarning( __( 'The local copy of the backup was kept, because not every upload succeeded.', 'lw-migration' ) );
			return StepResult::done();
		}
		$path       = (string) ( $job->state['backup']['path'] ?? '' );
		$repository = ( $this->backups )();
		$own        = $repository->findIn( $name, Backup::OWN );
		$uploaded   = (int) ( $job->state['backup']['size'] ?? 0 );
		clearstatcache( true, $path );
		if ( null === $own && '' !== $path && ! is_link( $path ) && ! file_exists( $path ) ) {
			// A replay after the file was already removed: nothing left to delete.
			$job->state['backup']['local'] = false;
			return StepResult::done();
		}
		$same = null !== $own && $uploaded === $own->size && realpath( dirname( $own->path ) ) === realpath( dirname( $path ) );
		if ( ! $same || is_link( $path ) || ! is_file( $path ) ) {
			$job->addWarning( __( 'The local copy of the backup was kept, because it is not the file that was uploaded.', 'lw-migration' ) );
			return StepResult::done();
		}
		if ( ! $repository->deleteIn( $name, Backup::OWN ) ) {
			$job->addWarning( __( 'The local copy of the backup could not be deleted; it is still in the backups folder.', 'lw-migration' ) );
			return StepResult::done();
		}
		Labels::remove( $name );
		$job->state['backup']['local'] = false;

		return StepResult::done( __( 'The backup is in the cloud; the local copy was deleted.', 'lw-migration' ) );
	}
}
