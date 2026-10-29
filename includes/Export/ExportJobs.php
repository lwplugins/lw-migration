<?php
/**
 * Creates export jobs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * One entry point for every driver (CLI, REST, schedules).
 * A job that cannot be fully set up is removed and the lock released
 * (JobStarter), so nothing is left behind.
 */
final class ExportJobs {

	/**
	 * Throws JobStarter's JobException when another job holds the site lock,
	 * and any failure to write the lock, the job or its secret (the job is
	 * removed first).
	 *
	 * @param JobStore             $jobs     Job store.
	 * @param SiteLock             $lock     Site lock.
	 * @param Site                 $site     Site snapshot.
	 * @param ExportOptions        $options  Export options.
	 * @param string|null          $password Password of an encrypted backup.
	 * @param SecretStore          $secrets  Where the password is kept while the job runs.
	 * @param int                  $now      Unix time.
	 * @param array<string, mixed> $state    Extra job state (state.schedule of a scheduled run); `site` is always the snapshot.
	 * @param CloudPlan|null       $cloud    Destinations to upload to after the backup is stored (null: none).
	 * @return Job
	 * @throws \InvalidArgumentException When an encrypted backup has no password, the extra state holds
	 *                                   anything but a schedule with a valid id, sites are chosen on a
	 *                                   single site, or a chosen site does not exist (and ExportException
	 *                                   on a subsite of a network or on an install that hosts several networks).
	 */
	public static function create( JobStore $jobs, SiteLock $lock, Site $site, ExportOptions $options, ?string $password, SecretStore $secrets, int $now, array $state = [], ?CloudPlan $cloud = null ): Job {
		self::checkNetwork( $options );
		self::checkState( $state );
		if ( $options->encrypt && ( null === $password || '' === $password ) ) {
			throw new \InvalidArgumentException( __( 'An encrypted backup needs a password.', 'lw-migration' ) );
		}

		$steps = ExportPipeline::stepsFor( is_multisite(), false );
		if ( null !== $cloud ) {
			$steps = array_merge( $steps, CloudPipeline::EXPORT_STEPS );
		}

		return JobStarter::start(
			$jobs,
			$lock,
			'export',
			$steps,
			$options->toArray(),
			$now,
			static function ( Job $job ) use ( $jobs, $site, $options, $password, $secrets, $state, $cloud ): void {
				$job->state = array_merge( $job->state, $state, [ 'site' => $site->toArray() ] );
				if ( null !== $cloud ) {
					$job->state['cloud'] = $cloud->toArray();
				}
				$jobs->save( $job );
				if ( $options->encrypt ) {
					$secrets->put( $job->id, [ 'password' => (string) $password ] );
				}
			}
		);
	}

	/**
	 * The export a connected site asked for (pull): no backup is stored, the
	 * archive waits in the job folder for the other site's download.
	 *
	 * @param JobStore             $jobs Job store.
	 * @param SiteLock             $lock Site lock.
	 * @param Site                 $site Site snapshot.
	 * @param int                  $now  Unix time.
	 * @param array<string, mixed> $link {id, ref, name, address} of the connection.
	 * @return Job
	 * @throws ExportException On a network.
	 */
	public static function createForLink( JobStore $jobs, SiteLock $lock, Site $site, int $now, array $link ): Job {
		if ( is_multisite() ) {
			throw new ExportException( __( 'Connections work between single sites only.', 'lw-migration' ) );
		}

		return JobStarter::start(
			$jobs,
			$lock,
			'export',
			ExportPipeline::LINK_STEPS,
			ExportOptions::fromArray( [] )->toArray(),
			$now,
			static function ( Job $job ) use ( $jobs, $site, $link ): void {
				$job->state['site'] = $site->toArray();
				$job->state['link'] = $link;
				$jobs->save( $job );
			}
		);
	}

	/**
	 * On a network a backup runs on the main site of an install with one network,
	 * and every chosen site exists; on a single site no site can be chosen.
	 *
	 * @param ExportOptions $options Export options.
	 * @return void
	 * @throws ExportException On a subsite or on an install that hosts several networks.
	 * @throws \InvalidArgumentException For chosen sites on a single site, or a site that does not exist.
	 */
	private static function checkNetwork( ExportOptions $options ): void {
		if ( ! is_multisite() ) {
			if ( [] !== $options->sites ) {
				throw new \InvalidArgumentException( __( 'Choosing sites works only on a multisite network.', 'lw-migration' ) );
			}
			return;
		}
		if ( ! MainSite::isContext() ) {
			throw new ExportException( __( 'Backups of a network run on its main site.', 'lw-migration' ) );
		}
		$reader = new NetworkReader();
		if ( count( $reader->networks() ) > 1 ) {
			throw new ExportException( __( 'This network install hosts more than one network. Backing it up is not supported.', 'lw-migration' ) );
		}
		$existing = $reader->existing( $options->sites );
		foreach ( $options->sites as $id ) {
			if ( ! in_array( $id, $existing, true ) ) {
				/* translators: %d: site ID */
				throw new \InvalidArgumentException( sprintf( __( 'There is no site with ID %d in this network.', 'lw-migration' ), $id ) );
			}
		}
	}

	/**
	 * Extra state is only state.schedule, and only with a valid schedule id.
	 *
	 * @param array<string, mixed> $state Extra job state.
	 * @return void
	 * @throws \InvalidArgumentException Otherwise.
	 */
	private static function checkState( array $state ): void {
		if ( [] === $state ) {
			return;
		}
		$id = is_array( $state['schedule'] ?? null ) ? ( $state['schedule']['id'] ?? null ) : null;
		if ( [ 'schedule' ] !== array_keys( $state ) || ! is_string( $id ) || 1 !== preg_match( Schedule::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Invalid extra job state.' );
		}
	}
}
