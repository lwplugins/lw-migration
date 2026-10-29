<?php
/**
 * Steps of the cloud work.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Schedules\Repository as Schedules;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudRetentionStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\LocalCleanupStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;

defined( 'ABSPATH' ) || exit;

/**
 * Registered for export, import and cloud jobs (Services::steps()); only the
 * step lists decide which run. An export with destinations appends
 * EXPORT_STEPS after `clean` (spec 21.1: upload, retention, then the local
 * cleanup). Retention gets the same schedule lookup as the local store step
 * (ExportPipeline::steps()), so a scheduled run prunes only while its
 * schedule exists.
 */
final class CloudPipeline {

	public const EXPORT_STEPS = [ CloudUploadStep::KEY, CloudRetentionStep::KEY, LocalCleanupStep::KEY ];

	/**
	 * @param Services $services WordPress wiring.
	 * @return array<string, StepInterface>
	 */
	public static function steps( Services $services ): array {
		$destinations = Destinations::fromWordPress();
		$now          = static fn(): int => time();

		return [
			CloudUploadStep::KEY    => new CloudUploadStep( $destinations, $now, null, new ReauthNotice() ),
			CloudRetentionStep::KEY => new CloudRetentionStep( $destinations, $now, static fn( string $id ): bool => null !== ( new Schedules() )->find( $id ) ),
			LocalCleanupStep::KEY   => new LocalCleanupStep( static fn() => $services->backups() ),
			CloudDownloadStep::KEY  => new CloudDownloadStep(
				$destinations,
				static function () use ( $services ): string {
					try {
						$services->storage()->ensureBackups();
					} catch ( FolderRefusedException $e ) {
						throw new CloudJobException( FolderMessages::of( $e->reason ) );
					}
					return $services->storage()->backupsDir();
				},
				$now
			),
		];
	}
}
