<?php
/**
 * Creates import jobs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Multisite\TargetNetworkReader;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * One entry point for every driver (CLI now, REST in M2c). A job that cannot
 * be fully set up is removed and the lock released (JobStarter).
 */
final class ImportJobs {

	/**
	 * Throws JobStarter's JobException when another job holds the site lock,
	 * and any failure to write the lock, the job or its secret (the job is
	 * removed first).
	 *
	 * @param JobStore                           $jobs            Job store.
	 * @param SiteLock                           $lock            Site lock.
	 * @param Site                               $site            This site, now.
	 * @param ImportOptions                      $options         Source and safety backup.
	 * @param array{id: int, login: string}|null $admin           The importing admin, if any.
	 * @param bool                               $usingPermalinks This site uses pretty permalinks now.
	 * @param string|null                        $password        Archive password given up front.
	 * @param SecretStore                        $secrets         Where the driver keeps secrets.
	 * @param int                                $now             Unix time.
	 * @param array<string, mixed>               $state           More state for the new job (e.g. the declared upload size); the fixed keys win.
	 * @return Job
	 * @throws ImportException On a network: outside its main site, or one this format cannot restore into.
	 */
	public static function create( JobStore $jobs, SiteLock $lock, Site $site, ImportOptions $options, ?array $admin, bool $usingPermalinks, ?string $password, SecretStore $secrets, int $now, array $state = [] ): Job {
		$target = is_multisite() ? self::target() : null;

		return JobStarter::start(
			$jobs,
			$lock,
			'import',
			ImportPipeline::stepsFor( $options->source, null !== $target ),
			$options->toArray(),
			$now,
			static function ( Job $job ) use ( $jobs, $site, $admin, $usingPermalinks, $password, $secrets, $state, $target ): void {
				foreach ( $state as $key => $value ) {
					$job->state[ (string) $key ] = $value;
				}
				unset( $job->state['target'] );
				if ( null !== $target ) {
					$job->state['target'] = $target->toArray();
				}
				$job->state['site']            = $site->toArray();
				$job->state['admin']           = $admin;
				$job->state['usingPermalinks'] = $usingPermalinks;
				$jobs->save( $job );
				if ( null !== $password && '' !== $password ) {
					$secrets->put( $job->id, [ 'password' => $password ] );
				}
			}
		);
	}

	/**
	 * The network to restore into, read on its main site (decisions 3, 9, 17; U4).
	 *
	 * @throws ImportException Outside the main site, on a multi-network install, or when the main site's id is not 1.
	 */
	private static function target(): TargetNetwork {
		if ( ! MainSite::isContext() ) {
			throw new ImportException( __( 'Restores into a network run on its main site.', 'lw-migration' ) );
		}
		$target = TargetNetworkReader::read();
		if ( $target->networks > 1 ) {
			throw new ImportException( __( 'This network install hosts more than one network. Restoring into it is not supported.', 'lw-migration' ) );
		}
		if ( 1 !== $target->mainSiteId ) {
			throw new ImportException( __( 'This network\'s main site does not have ID 1, which this archive format needs.', 'lw-migration' ) );
		}

		return $target;
	}
}
