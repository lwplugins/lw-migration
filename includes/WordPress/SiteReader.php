<?php
/**
 * Builds a Site snapshot from WordPress.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Storage\StorageDir;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that asks WordPress for URLs, folders and active software.
 */
final class SiteReader {

	/** Option of existing .wpress tooling that holds its custom backups folder. */
	private const EXTERNAL_BACKUPS_OPTION = 'ai1wm_backups_path';

	public static function read(): Site {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$uploads   = wp_upload_dir( null, false );
		$all       = wp_load_alloptions();
		$theme_dir = untrailingslashit( get_theme_root() );
		$external  = get_option( self::EXTERNAL_BACKUPS_OPTION, '' );

		return new Site(
			[
				'siteUrl'             => site_url(),
				'homeUrl'             => home_url(),
				'internalSiteUrl'     => self::rawOption( $all, 'siteurl' ),
				'internalHomeUrl'     => self::rawOption( $all, 'home' ),
				'abspath'             => ABSPATH,
				'contentDir'          => untrailingslashit( WP_CONTENT_DIR ),
				'pluginsDir'          => untrailingslashit( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ),
				'muPluginsDir'        => untrailingslashit( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ),
				'themeRoot'           => $theme_dir,
				'themes'              => self::themes( $theme_dir ),
				'uploadsDir'          => untrailingslashit( (string) $uploads['basedir'] ),
				'uploadsUrl'          => trailingslashit( (string) $uploads['baseurl'] ),
				'uploadPath'          => (string) get_option( 'upload_path', '' ),
				'uploadUrlPath'       => (string) get_option( 'upload_url_path', '' ),
				'tablePrefix'         => (string) $GLOBALS['wpdb']->base_prefix,
				'wpVersion'           => (string) ( $GLOBALS['wp_version'] ?? '' ),
				'dbCharset'           => defined( 'DB_CHARSET' ) ? (string) DB_CHARSET : 'undefined',
				'dbCollate'           => defined( 'DB_COLLATE' ) ? (string) DB_COLLATE : 'undefined',
				'plugins'             => array_keys( get_plugins() ),
				'activePlugins'       => (array) get_option( 'active_plugins', [] ),
				'template'            => (string) get_option( 'template', '' ),
				'stylesheet'          => (string) get_option( 'stylesheet', '' ),
				'ownPlugin'           => plugin_basename( LW_MIGRATION_FILE ),
				'sqliteDb'            => defined( 'FQDB' ) ? (string) constant( 'FQDB' ) : '',
				'externalBackupsPath' => is_string( $external ) ? $external : '',
				'backupDirs'          => self::backupDirs(),
			]
		);
	}

	/**
	 * Our backups folders, read only (never names the storage folder).
	 *
	 * @return array<int, string>
	 */
	private static function backupDirs(): array {
		$storage = StorageDir::stored();

		return null === $storage ? [] : $storage->backupDirs();
	}

	/**
	 * The stored option value; read from the table when the autoload cache lacks it
	 * (for example when it is not autoloaded).
	 *
	 * @param array<string, mixed> $all  Result of wp_load_alloptions().
	 * @param string               $name Option name.
	 */
	private static function rawOption( array $all, string $name ): string {
		if ( isset( $all[ $name ] ) ) {
			return (string) $all[ $name ];
		}

		return (string) ( new OptionRow( $name ) )->read();
	}

	/**
	 * @param string $fallbackRoot Root for themes WordPress reports without one.
	 * @return array<string, string> Slug => theme root.
	 */
	private static function themes( string $fallbackRoot ): array {
		$themes = [];
		foreach ( (array) search_theme_directories() as $slug => $info ) {
			$root                     = is_array( $info ) && isset( $info['theme_root'] ) ? (string) $info['theme_root'] : $fallbackRoot;
			$themes[ (string) $slug ] = untrailingslashit( $root );
		}

		return $themes;
	}
}
