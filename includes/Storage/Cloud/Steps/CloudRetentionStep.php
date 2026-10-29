<?php
/**
 * Removes old backups from each destination that took the new one.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud\Steps;

use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\RemoteBackups;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.1/21.8. One destination per call. Only a destination whose upload
 * succeeded is pruned, and only when the plan prunes (not for an upload of an
 * existing backup). The rules: the schedule's retention for that destination
 * in a scheduled run (scope: that schedule's backups), else the profile's
 * (scope: manual backups); all limits 0 = nothing to do (no listing). The
 * candidates are this site's generated names only (RemoteBackups, plan D7);
 * the backup just uploaded always stays. A failure is a warning, never the
 * job's: the backup itself is safe. A call that starts again from the same
 * cursor (a request died while deleting) skips that destination with a
 * warning, like StoreStep's prune.
 */
final class CloudRetentionStep implements StepInterface {

	public const KEY = 'cloud_retention';

	/**
	 * @param Destinations  $destinations   Profiles and types.
	 * @param \Closure      $now            fn(): int.
	 * @param \Closure|null $scheduleExists fn( string $id ): bool, the one ExportPipeline gives StoreStep.
	 */
	public function __construct( private Destinations $destinations, private \Closure $now, private ?\Closure $scheduleExists = null ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$plan  = CloudPlan::of( $job );
		$index = (int) ( $job->cursor['index'] ?? 0 );
		if ( null === $plan || ! $plan->prune || ! isset( $plan->targets[ $index ] ) ) {
			return StepResult::done();
		}
		$target   = $plan->targets[ $index ];
		$result   = CloudOutcome::get( $job, $target['id'] );
		$name     = (string) ( $result['name'] ?? $target['id'] );
		$progress = (int) floor( 100 * ( $index + 1 ) / count( $plan->targets ) );
		if ( CloudOutcome::OK === ( $result['status'] ?? null ) && $this->prune( $job, $deadline, $target, $name ) ) {
			// Deleted some and the tick is over: the next call lists again and goes on (idempotent).
			$job->cursor = [
				'index' => $index,
				'round' => (int) ( $job->cursor['round'] ?? 0 ) + 1,
			];

			return StepResult::more( $progress );
		}
		$job->cursor = [ 'index' => $index + 1 ];

		return isset( $plan->targets[ $index + 1 ] ) ? StepResult::more( $progress ) : StepResult::done( __( 'Old cloud backups checked.', 'lw-migration' ) );
	}

	/**
	 * @param Job                                                                         $job      Job.
	 * @param Deadline                                                                    $deadline Deadline.
	 * @param array{id: string, retention: array{count: int, bytes: int, days: int}|null} $target   Destination.
	 * @param string                                                                      $name     Its name for messages.
	 * @return bool True when the tick ran out with more to delete.
	 */
	private function prune( Job $job, Deadline $deadline, array $target, string $name ): bool {
		$profile = $this->destinations->find( $target['id'] );
		if ( null === $profile ) {
			/* translators: %s: destination name */
			$job->addWarning( sprintf( __( 'Old backups at %s were not removed: the destination no longer exists.', 'lw-migration' ), $name ) );
			return false;
		}
		$retention = Retention::fromOption( $target['retention'] ?? $profile->retention );
		if ( 0 === $retention->maxCount && 0 === $retention->maxBytes && 0 === $retention->maxDays ) {
			return false;
		}
		$home = Site::fromArray( (array) ( $job->state['site'] ?? [] ) )->homeUrl;
		if ( '' === $home ) {
			/* translators: %s: destination name */
			$job->addWarning( sprintf( __( 'Old backups at %s were not removed: this site\'s address is unknown.', 'lw-migration' ), $name ) );
			return false;
		}
		if ( ! $this->scheduleAllows( $job, $name ) ) {
			return false;
		}
		if ( (int) ( $job->attempt['count'] ?? 1 ) > 1 ) {
			/* translators: %s: destination name */
			$job->addWarning( sprintf( __( 'Old backups at %s were not removed: the previous attempt stopped before it finished.', 'lw-migration' ), $name ) );
			return false;
		}
		try {
			$destination = $this->destinations->open( $profile );
			$now         = (int) ( $this->now )();
			$uploaded    = (string) ( $job->state['backup']['name'] ?? '' );
			$files       = RemoteBackups::toPrune( self::listed( $destination, $profile->id, $uploaded, $now ), $home, $retention, $now, $uploaded, self::schedule( $job ) );
			foreach ( $files as $i => $file ) {
				$destination->delete( $file );
				CloudOutcome::addPruned( $job, $profile->id, [ $file->name ] );
				CloudCopies::forget( $file->name, $profile->id );
				if ( $deadline->reached() && isset( $files[ $i + 1 ] ) ) {
					return true;
				}
			}
		} catch ( CloudFailure $e ) {
			/* translators: 1: destination name, 2: reason */
			$job->addWarning( sprintf( __( 'Old backups at %1$s could not all be removed: %2$s', 'lw-migration' ), $name, $e->getMessage() ) );
		}

		return false;
	}

	/**
	 * The destination's whole listing (list() never answers a cut one), which
	 * also corrects its copy records: a backup removed there by hand stops
	 * showing as held by it. The backup just uploaded is there whatever the
	 * listing says.
	 *
	 * @param DestinationInterface $destination Destination.
	 * @param string               $id          Profile id.
	 * @param string               $uploaded    The backup just uploaded.
	 * @param int                  $listedAt    Unix time just before the listing.
	 * @return array<int, RemoteFile>
	 * @throws CloudFailure When the folder cannot be listed.
	 */
	private static function listed( DestinationInterface $destination, string $id, string $uploaded, int $listedAt ): array {
		$files = $destination->list();
		CloudCopies::reconcile( $id, array_merge( array_map( static fn( RemoteFile $f ): string => $f->name, $files ), [ $uploaded ] ), $listedAt );

		return $files;
	}

	/**
	 * StoreStep's rules for a scheduled backup: an unreadable id, no lookup or a
	 * schedule that is gone prune nothing (deleting a schedule keeps its backups).
	 *
	 * @param Job    $job  Job.
	 * @param string $name Destination name for messages.
	 * @return bool
	 */
	private function scheduleAllows( Job $job, string $name ): bool {
		if ( ! array_key_exists( 'schedule', $job->state ) ) {
			return true;
		}
		$id = self::schedule( $job );
		if ( null === $id || null === $this->scheduleExists ) {
			return false;
		}
		if ( true !== ( $this->scheduleExists )( $id ) ) {
			/* translators: %s: destination name */
			$job->addWarning( sprintf( __( 'Old backups at %s were kept: the schedule of this backup no longer exists.', 'lw-migration' ), $name ) );
			return false;
		}

		return true;
	}

	private static function schedule( Job $job ): ?string {
		$id = $job->state['schedule']['id'] ?? null;

		return is_string( $id ) && 1 === preg_match( Schedule::ID_PATTERN, $id ) ? $id : null;
	}
}
