<?php
/**
 * Job secrets with the schedule's password as fallback.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\SecretStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the sealed job transient (TransientSecretStore). A long scheduled
 * backup can outlive its transient on a site with an object cache that
 * evicts it; then the password comes from the schedule again — only for an
 * encrypted export of that schedule, and only while the schedule still has
 * the sealed password the run started with (state.schedule.seal), so an
 * archive is never written with two passwords. Anything else reads as no
 * secret, as before.
 */
final class ScheduleSecrets implements SecretStore {

	/**
	 * @param SecretStore    $inner     The job secret store.
	 * @param JobStore       $jobs      Job store.
	 * @param Repository     $schedules Schedules.
	 * @param SealedPassword $passwords Opens a schedule's password.
	 */
	public function __construct( private SecretStore $inner, private JobStore $jobs, private Repository $schedules, private SealedPassword $passwords ) {
	}

	public function put( string $jobId, array $values ): void {
		$this->inner->put( $jobId, $values );
	}

	public function get( string $jobId ): array {
		$values = $this->inner->get( $jobId );
		if ( [] !== $values ) {
			return $values;
		}
		try {
			$job = $this->jobs->load( $jobId );
		} catch ( JobException $e ) {
			return [];
		}
		$id   = $job->state['schedule']['id'] ?? null;
		$seal = $job->state['schedule']['seal'] ?? null;
		if ( 'export' !== $job->type || empty( $job->options['encrypt'] ) || ! is_string( $id ) || ! is_string( $seal ) || '' === $seal || 1 !== preg_match( Schedule::ID_PATTERN, $id ) ) {
			return [];
		}
		$schedule = $this->schedules->find( $id );
		if ( null === $schedule || ! hash_equals( $seal, ScheduleJobs::seal( $schedule ) ) ) {
			return [];
		}
		$password = $this->passwords->open( $schedule->password );

		return null === $password || '' === $password ? [] : [ 'password' => $password ];
	}

	public function delete( string $jobId ): void {
		$this->inner->delete( $jobId );
	}
}
