<?php
/**
 * Import jobs for step tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;

final class ImportJobFixture {

	/**
	 * An import job targeting https://new.test, with the site files under $root.
	 *
	 * @param JobStore             $store   Store in a temp folder.
	 * @param string               $root    Site root (SiteFixture/SiteTree).
	 * @param string               $archive Archive path saved in state.archive (and the default location).
	 * @param array<string, mixed> $options ImportOptions data.
	 * @param array<string, mixed> $secrets Job secrets.
	 */
	public static function make( JobStore $store, string $root, string $archive, array $options = [], array $secrets = [] ): Job {
		$data                  = ImportOptions::fromArray( $options + [ 'source' => 'file', 'location' => $archive ] )->toArray();
		$job                   = $store->create( 'import', [ 'test' ], $data, 1 );
		$job->state['site']    = SiteFixture::make(
			$root,
			[
				'siteUrl'         => 'https://new.test',
				'homeUrl'         => 'https://new.test',
				'internalSiteUrl' => 'https://new.test',
				'internalHomeUrl' => 'https://new.test',
				'uploadsUrl'      => 'https://new.test/wp-content/uploads/',
			]
		)->toArray();
		$job->state['archive'] = $archive;
		$job->secrets          = $secrets;

		return $job;
	}
}
