<?php
/**
 * Plugins WordPress loads without the plugin list.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

use LightweightPlugins\Migration\Export\ExportRoots;

defined( 'ABSPATH' ) || exit;

/**
 * A drop-in (db.php of a database or cache plugin), a top-level must-use
 * loader or an auto_prepend_file firewall may require a plugin's files
 * directly: deleting that plugin would break the site, and the reset job
 * itself, on the next request. Such a plugin is kept: a loader whose first
 * MiB names "plugins/<folder>" or "WP_PLUGIN_DIR . '/<folder>" (a whole
 * folder name) keeps it, and the SQLite integration always stays.
 * WordPress-free.
 */
final class LoadedElsewhere {

	public const SQLITE = ExportRoots::SQLITE_PLUGIN;

	/** Bytes of each loader that are read. */
	public const READ_BYTES = 1048576;

	/**
	 * @param array<int, string>    $plugins Plugin basenames.
	 * @param array<string, string> $loaders Shown name => absolute file (drop-ins, top-level mu-plugins, auto_prepend_file).
	 * @return array<string, string> Kept basename => the shown name of what loads it.
	 */
	public static function find( array $plugins, array $loaders ): array {
		$texts = [];
		foreach ( $loaders as $name => $file ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file; a warning would carry the path.
			$text = is_file( $file ) && is_readable( $file ) ? (string) @file_get_contents( $file, false, null, 0, self::READ_BYTES ) : '';
			if ( '' !== $text ) {
				$texts[ $name ] = $text;
			}
		}
		$kept = [];
		foreach ( $plugins as $plugin ) {
			$folder = explode( '/', $plugin )[0];
			if ( self::SQLITE === $folder ) {
				$kept[ $plugin ] = 'db.php';
				continue;
			}
			foreach ( $texts as $name => $text ) {
				if ( self::names( $text, $folder ) ) {
					$kept[ $plugin ] = (string) $name;
					break;
				}
			}
		}

		return $kept;
	}

	/**
	 * @param string             $contentDir   The content folder (drop-ins).
	 * @param string             $muPluginsDir The must-use plugins folder.
	 * @param array<int, string> $dropIns      Drop-in names WordPress knows.
	 * @param string             $prepend      The auto_prepend_file setting ('' = none).
	 * @return array<string, string> Shown name => file: present drop-ins, top-level mu-plugins, auto_prepend_file.
	 */
	public static function loaders( string $contentDir, string $muPluginsDir, array $dropIns, string $prepend ): array {
		$out = [];
		foreach ( $dropIns as $name ) {
			if ( is_file( $contentDir . '/' . $name ) ) {
				$out[ $name ] = $contentDir . '/' . $name;
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$mu = @glob( rtrim( $muPluginsDir, '/' ) . '/*.php' );
		foreach ( is_array( $mu ) ? $mu : [] as $file ) {
			if ( is_file( $file ) ) {
				$out[ 'mu-plugins/' . basename( $file ) ] = $file;
			}
		}
		if ( '' !== $prepend && is_file( $prepend ) ) {
			$out['auto_prepend_file'] = $prepend;
		}

		return $out;
	}

	/**
	 * "plugins/<folder>" or "WP_PLUGIN_DIR . '/<folder>" as a whole folder name.
	 *
	 * @param string $text   Loader text.
	 * @param string $folder Plugin folder (or single file) name.
	 */
	private static function names( string $text, string $folder ): bool {
		$f = preg_quote( $folder, '#' );

		return 1 === preg_match( '#(?:plugins/|WP_PLUGIN_DIR\s*\.\s*[\'"]/)' . $f . '(?![A-Za-z0-9_.-])#', $text );
	}
}
