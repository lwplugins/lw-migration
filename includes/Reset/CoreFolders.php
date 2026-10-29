<?php
/**
 * The folders of the site itself, by identity.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's own folder, the folder that may hold wp-config.php above it,
 * the content, plugins and mu-plugins folders and every theme root. A reset
 * never enters one of them from inside the tree it empties: a bind mount (or
 * any second name on the same file system) shows the same device:inode, which
 * neither the path nor the device rule would catch. WordPress-free.
 */
final class CoreFolders {

	/**
	 * @param Site $site Site.
	 * @return array<string, string> "device:inode" => path.
	 */
	public static function ids( Site $site ): array {
		$abspath = rtrim( $site->abspath, '/' );
		$folders = array_merge(
			[ $abspath, $site->contentDir, $site->pluginsDir, $site->muPluginsDir ],
			array_map( 'dirname', $site->wpConfigPaths() ),
			$site->themeRoots()
		);

		// A missing folder is left out before it is asked (a silenced stat still reaches an error handler with its path).
		return FolderIdentity::ids( array_values( array_filter( $folders, static fn( string $dir ): bool => '' !== $dir && is_dir( $dir ) ) ) );
	}
}
