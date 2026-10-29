<?php
/**
 * URLs and paths of the site an archive is restored into.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Filled from WordPress by the import step (M2b); plain data here.
 */
final class SiteTarget {

	/**
	 * @param string $siteUrl        site_url().
	 * @param string $homeUrl        home_url().
	 * @param string $uploadsUrl     Uploads base URL with trailing slash.
	 * @param string $uploadsRelPath Uploads dir relative to ABSPATH (e.g. wp-content/uploads).
	 * @param string $abspath        ABSPATH.
	 * @param string $contentDir     WP_CONTENT_DIR.
	 */
	public function __construct(
		public string $siteUrl,
		public string $homeUrl,
		public string $uploadsUrl,
		public string $uploadsRelPath,
		public string $abspath,
		public string $contentDir
	) {
	}
}
