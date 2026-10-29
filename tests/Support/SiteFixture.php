<?php
/**
 * A site snapshot rooted in a temporary folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\WordPress\Site;

final class SiteFixture {

	/**
	 * @param string               $root      Folder standing for ABSPATH (no trailing slash).
	 * @param array<string, mixed> $overrides Site fields to change.
	 */
	public static function make( string $root, array $overrides = [] ): Site {
		$content = $root . '/wp-content';
		return Site::fromArray(
			array_merge(
				[
					'siteUrl'         => 'https://old.test',
					'homeUrl'         => 'https://old.test',
					'internalSiteUrl' => 'https://old.test',
					'internalHomeUrl' => 'https://old.test',
					'abspath'         => $root . '/',
					'contentDir'      => $content,
					'pluginsDir'      => $content . '/plugins',
					'muPluginsDir'    => $content . '/mu-plugins',
					'themeRoot'       => $content . '/themes',
					'themes'          => [
						'twentytwentyfive' => $content . '/themes',
						'old-theme'        => $content . '/themes',
					],
					'uploadsDir'      => $content . '/uploads',
					'uploadsUrl'      => 'https://old.test/wp-content/uploads/',
					'uploadPath'      => '',
					'uploadUrlPath'   => '',
					'tablePrefix'     => 'wp_',
					'wpVersion'       => '7.1',
					'dbCharset'       => 'utf8mb4',
					'dbCollate'       => '',
					'plugins'         => [ 'lw-migration/lw-migration.php', 'sample/sample.php', 'inactive/inactive.php', 'hello.php' ],
					'activePlugins'   => [ 'lw-migration/lw-migration.php', 'sample/sample.php' ],
					'template'        => 'twentytwentyfive',
					'stylesheet'      => 'twentytwentyfive',
					'ownPlugin'       => 'lw-migration/lw-migration.php',
				],
				$overrides
			)
		);
	}
}
