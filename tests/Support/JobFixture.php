<?php
/**
 * Export jobs for step tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;

final class JobFixture {

	/**
	 * @param JobStore             $store   Store in a temp folder.
	 * @param string               $root    Site root (SiteFixture/SiteTree).
	 * @param array<string, mixed> $options ExportOptions data.
	 * @param array<string, mixed> $secrets Job secrets.
	 */
	public static function export( JobStore $store, string $root, array $options = [], array $secrets = [] ): Job {
		$job                = $store->create( 'export', [ 'test' ], ExportOptions::fromArray( $options )->toArray(), 1 );
		$job->state['site'] = SiteFixture::make( $root )->toArray();
		$job->secrets       = $secrets;

		return $job;
	}
}
