<?php
/**
 * Creates push jobs.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link;

use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Steps\PushCleanStep;
use LightweightPlugins\Migration\Link\Steps\PushUploadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\WordPress\JobStarter;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the push of this site to a connected site: the export steps up to
 * the finished archive (kept in the job folder, no backup stored), then the
 * upload, the other site's restore and the clean-up. Single site only,
 * never encrypted (the other site's import must read it without a password),
 * never a choice of sites.
 */
final class PushJobs {

	public const TYPE = 'push';

	/**
	 * @return array<int, string>
	 */
	public static function steps(): array {
		return array_merge( ExportPipeline::LINK_STEPS, [ PushUploadStep::KEY, RemoteJobStep::PUSH_KEY, PushCleanStep::KEY ] );
	}

	/**
	 * @param JobStore                                         $jobs         Job store.
	 * @param SiteLock                                         $lock         Site lock.
	 * @param Site                                             $site         Site snapshot.
	 * @param ExportOptions                                    $options      What the archive leaves out.
	 * @param array{id: string, name: string, address: string} $link         The outgoing connection.
	 * @param bool                                             $safetyBackup Whether the other site backs up its database first.
	 * @param int                                              $now          Unix time.
	 * @throws \InvalidArgumentException For an encrypted or a sites export.
	 * @throws ExportException On a network (and JobStarter a SiteBusyException when another job holds this site).
	 */
	public static function create( JobStore $jobs, SiteLock $lock, Site $site, ExportOptions $options, array $link, bool $safetyBackup, int $now ): Job {
		if ( is_multisite() ) {
			throw new ExportException( __( 'Connections work between single sites only.', 'lw-migration' ) );
		}
		if ( $options->encrypt || [] !== $options->sites ) {
			throw new \InvalidArgumentException( self::plainOnly() );
		}

		return JobStarter::start(
			$jobs,
			$lock,
			self::TYPE,
			self::steps(),
			$options->toArray(),
			$now,
			static function ( Job $job ) use ( $jobs, $site, $link, $safetyBackup ): void {
				$job->state['site']   = $site->toArray();
				$job->state['link']   = [
					'id'      => (string) $link['id'],
					'name'    => (string) $link['name'],
					'address' => (string) $link['address'],
				];
				$job->state['remote'] = [ 'safetyBackup' => $safetyBackup ];
				$jobs->save( $job );
			}
		);
	}

	/**
	 * Why a push takes neither a password nor sites.
	 */
	public static function plainOnly(): string {
		return __( 'A push is never encrypted and never chooses sites: the other site restores the archive without a password, and a connection joins two single sites.', 'lw-migration' );
	}
}
