<?php
/**
 * Snapshot of the site's URLs, paths and active software.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Database\SiteTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data, stored in job state at job creation so every tick (and every
 * driver) sees the same values. Built from WordPress by SiteReader.
 */
final class Site {

	private const STRINGS = [ 'siteUrl', 'homeUrl', 'internalSiteUrl', 'internalHomeUrl', 'abspath', 'contentDir', 'pluginsDir', 'muPluginsDir', 'themeRoot', 'uploadsDir', 'uploadsUrl', 'uploadPath', 'uploadUrlPath', 'tablePrefix', 'wpVersion', 'dbCharset', 'dbCollate', 'template', 'stylesheet', 'ownPlugin', 'sqliteDb', 'externalBackupsPath' ];

	private const LISTS = [ 'plugins', 'activePlugins', 'backupDirs' ];

	/** @var string site_url(). */
	public string $siteUrl;

	/** @var string home_url(). */
	public string $homeUrl;

	/** @var string Raw siteurl option. */
	public string $internalSiteUrl;

	/** @var string Raw home option. */
	public string $internalHomeUrl;

	/** @var string ABSPATH (trailing slash). */
	public string $abspath;

	/** @var string WP_CONTENT_DIR. */
	public string $contentDir;

	/** @var string WP_PLUGIN_DIR. */
	public string $pluginsDir;

	/** @var string WPMU_PLUGIN_DIR. */
	public string $muPluginsDir;

	/** @var string Default theme root. */
	public string $themeRoot;

	/** @var string Uploads base folder. */
	public string $uploadsDir;

	/** @var string Uploads base URL (trailing slash). */
	public string $uploadsUrl;

	/** @var string upload_path option. */
	public string $uploadPath;

	/** @var string upload_url_path option. */
	public string $uploadUrlPath;

	/** @var string Base table prefix. */
	public string $tablePrefix;

	/** @var string WordPress version. */
	public string $wpVersion;

	/** @var string DB_CHARSET, or 'undefined' when the constant is missing: consumers treat 'undefined' as unknown. */
	public string $dbCharset;

	/** @var string DB_COLLATE, or 'undefined' when the constant is missing: consumers treat 'undefined' as unknown. */
	public string $dbCollate;

	/** @var string Parent theme slug. */
	public string $template;

	/** @var string Active theme slug. */
	public string $stylesheet;

	/** @var string This plugin's basename. */
	public string $ownPlugin;

	/** @var string SQLite database file of the database drop-in ('' on MySQL). */
	public string $sqliteDb;

	/** @var string Custom backups folder of existing .wpress tooling ('' when not set). */
	public string $externalBackupsPath;

	/** @var array<int, string> Installed plugin basenames. */
	public array $plugins;

	/** @var array<int, string> Active plugin basenames. */
	public array $activePlugins;

	/** @var array<int, string> Our backups folders (current and, during a move, the target); none in a snapshot taken before they existed. */
	public array $backupDirs;

	/** @var array<string, string> Theme slug => theme root. */
	public array $themes;

	/**
	 * @param array<string, mixed> $data Field values (missing = empty).
	 */
	public function __construct( array $data ) {
		foreach ( self::STRINGS as $key ) {
			$this->$key = (string) ( $data[ $key ] ?? '' );
		}
		foreach ( self::LISTS as $key ) {
			$this->$key = array_values( array_map( 'strval', (array) ( $data[ $key ] ?? [] ) ) );
		}
		$this->themes = [];
		foreach ( (array) ( $data['themes'] ?? [] ) as $slug => $root ) {
			$this->themes[ (string) $slug ] = (string) $root;
		}
	}

	/**
	 * @param array<string, mixed> $data Saved snapshot.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	/**
	 * @return array<int, string>
	 */
	public function themeRoots(): array {
		return array_values( array_unique( array_merge( [ $this->themeRoot ], array_values( $this->themes ) ) ) );
	}

	/**
	 * Where WordPress may keep wp-config.php: ABSPATH or one folder above it.
	 *
	 * @return array<int, string>
	 */
	public function wpConfigPaths(): array {
		$abspath = rtrim( $this->abspath, '/' );
		if ( '' === $abspath ) {
			return [];
		}

		return [ $abspath . '/wp-config.php', dirname( $abspath ) . '/wp-config.php' ];
	}

	/** Uploads folder relative to ABSPATH ('' when it lives elsewhere). */
	public function uploadsRelPath(): string {
		$abspath = rtrim( $this->abspath, '/' ) . '/';
		if ( ! str_starts_with( $this->uploadsDir . '/', $abspath ) ) {
			return '';
		}

		return trim( substr( $this->uploadsDir, strlen( $abspath ) ), '/' );
	}

	public function siteTarget(): SiteTarget {
		return new SiteTarget( $this->siteUrl, $this->homeUrl, $this->uploadsUrl, $this->uploadsRelPath(), $this->abspath, $this->contentDir );
	}
}
