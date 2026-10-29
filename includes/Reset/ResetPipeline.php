<?php
/**
 * The steps of a reset job, wired to WordPress.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\PostImport\Fixes;
use LightweightPlugins\Migration\Reset\Steps\BackupStep;
use LightweightPlugins\Migration\Reset\Steps\DatabaseBuildStep;
use LightweightPlugins\Migration\Reset\Steps\DatabaseFinishStep;
use LightweightPlugins\Migration\Reset\Steps\DatabaseSwapStep;
use LightweightPlugins\Migration\Reset\Steps\Lazy;
use LightweightPlugins\Migration\Reset\Steps\MediaStep;
use LightweightPlugins\Migration\Reset\Steps\NotAsRoot;
use LightweightPlugins\Migration\Reset\Steps\PluginsStep;
use LightweightPlugins\Migration\Reset\Steps\ThemesStep;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Tasks add their steps here: one key of ResetType::STEP_KEYS each. The
 * plugins, themes and media steps are built the first time they run, so a
 * job of another type never reads the plugin or theme list. Every step is
 * wrapped in NotAsRoot: any request of a file reset made as root fails
 * before a step touches anything (a database reset alone may run as root).
 */
final class ResetPipeline {

	/**
	 * @param Services $services Services.
	 * @return array<string, StepInterface>
	 */
	public static function steps( Services $services ): array {
		$steps = [
			ResetType::BACKUP       => new BackupStep( ExportPipeline::steps( $services ) ),
			ResetType::PLUGINS_STEP => new Lazy( static fn(): StepInterface => PluginsStep::fromWordPress( $services ) ),
			ResetType::THEMES_STEP  => new Lazy( static fn(): StepInterface => ThemesStep::fromWordPress( $services, SiteReader::read() ) ),
			ResetType::MEDIA_STEP   => new Lazy( static fn(): StepInterface => MediaStep::fromWordPress( $services ) ),
			ResetType::DB_BUILD     => DatabaseBuildStep::fromWordPress( $services ),
			ResetType::DB_SWAP      => new DatabaseSwapStep(
				$services->jobs(),
				static fn() => $services->connection(),
				$services->lock(),
				static fn(): int => time(),
				static fn(): array => ResetTables::coreSuffixes(),
				static fn() => Fixes::flushCaches()
			),
			ResetType::DB_FINISH    => DatabaseFinishStep::fromWordPress( $services ),
		];

		return array_map( static fn( StepInterface $step ): StepInterface => new NotAsRoot( $step ), $steps );
	}
}
