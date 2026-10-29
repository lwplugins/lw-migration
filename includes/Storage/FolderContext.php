<?php
/**
 * The folder rules, wired to this WordPress site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Backups\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Web roots (typed and real path): ABSPATH, wp-content, the server's document
 * root and the document root derived from the site address, so a WordPress in
 * a subfolder is covered in WP-CLI too, where DOCUMENT_ROOT is empty. The
 * site's own folders are never used; inside wp-content only wp-content itself
 * may be the parent, and the folders WordPress empties by itself are refused.
 * Our storage, the backups folders this site uses now (the current one, the
 * target of an unfinished move, the default one) and the external tool's
 * folders never overlap a chosen one.
 */
final class FolderContext {

	/**
	 * @param StorageDir              $storage Storage root with the current setting.
	 * @param array<int, string>|null $trees   System trees (tests); null = BackupDirRules::SYSTEM_TREES.
	 * @param array<int, string>|null $exact   Shared folders never used themselves (tests); null = BackupDirRules::SYSTEM_EXACT.
	 */
	public static function rules( StorageDir $storage, ?array $trees = null, ?array $exact = null ): BackupDirRules {
		$abspath  = rtrim( ABSPATH, '/' );
		$content  = rtrim( WP_CONTENT_DIR, '/' );
		$uploads  = wp_upload_dir( null, false );
		$webRoots = [ $abspath, $content, self::serverRoot(), self::documentRoot( ABSPATH, (string) site_url() ) ];
		$plugins  = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : $content . '/plugins';
		$mu       = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : $content . '/mu-plugins';
		$themes   = array_values( array_map( 'strval', (array) ( $GLOBALS['wp_theme_directories'] ?? [] ) ) );
		$external = get_option( 'ai1wm_backups_path', '' );
		$overlaps = array_merge(
			[ $storage->root(), $storage->defaultBackupsDir(), $content . '/' . Repository::EXTERNAL_DIR, is_string( $external ) ? $external : '' ],
			$storage->backupDirs()
		);

		return new BackupDirRules(
			self::withReal( $webRoots ),
			self::withReal( array_merge( [ $plugins, $mu, (string) $uploads['basedir'], $abspath . '/wp-admin', $abspath . '/wp-includes' ], $themes ) ),
			self::withReal( $overlaps ),
			(string) ini_get( 'open_basedir' ),
			$storage->setting()->marker,
			$trees ?? BackupDirRules::SYSTEM_TREES,
			$exact ?? BackupDirRules::SYSTEM_EXACT,
			null,
			self::withReal( [ $content ] ),
			self::withReal( [ $content . '/upgrade', $content . '/upgrade-temp-backup', defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : $content . '/languages' ] ),
			$storage->owners()
		);
	}

	/**
	 * ABSPATH minus the path of the site address, when ABSPATH ends with it.
	 *
	 * @param string $abspath ABSPATH.
	 * @param string $siteUrl The WordPress address (site_url()).
	 * @return string '' when it cannot be derived.
	 */
	public static function documentRoot( string $abspath, string $siteUrl ): string {
		$abs  = rtrim( $abspath, '/' );
		$path = trim( (string) wp_parse_url( $siteUrl, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return $abs;
		}

		return str_ends_with( $abs, '/' . $path ) ? substr( $abs, 0, -strlen( $path ) - 1 ) : '';
	}

	/**
	 * Whether the folder may be changed here (filter lw_migration_backup_dir_editable).
	 */
	public static function editable(): bool {
		/**
		 * Whether admins may change the backups folder on this site.
		 *
		 * @param bool $editable Default true.
		 */
		return (bool) apply_filters( 'lw_migration_backup_dir_editable', true );
	}

	/** A folder of one's own needs POSIX paths. */
	public static function supported(): bool {
		return '/' === DIRECTORY_SEPARATOR;
	}

	/**
	 * The server's document root. Not run through sanitize_text_field(): it
	 * would change a path with "%" octets or repeated spaces, and a web root
	 * missed here means a guessable folder name. A value with control
	 * characters or a relative one is ignored.
	 */
	private static function serverRoot(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path compared, never printed or stored; validated below.
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';

		return str_starts_with( $root, '/' ) && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $root ) ? $root : '';
	}

	/**
	 * @param array<int, string> $paths Folders.
	 * @return array<int, string> Each non-empty absolute folder, also by its real path.
	 */
	private static function withReal( array $paths ): array {
		$all = [];
		foreach ( $paths as $path ) {
			$path = rtrim( (string) $path, '/' );
			if ( '' === $path || '/' !== $path[0] ) {
				continue;
			}
			$all[] = $path;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			$real = @realpath( $path );
			if ( false !== $real ) {
				$all[] = $real;
			}
		}

		return array_values( array_unique( $all ) );
	}
}
