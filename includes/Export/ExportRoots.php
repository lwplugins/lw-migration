<?php
/**
 * Folders a backup walks, with what each one leaves out.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Multisite\NetworkRoots;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Archive order (spec 5.5): wp-content files, uploads/, plugins/, themes/.
 */
final class ExportRoots {

	/** Folder of a database plugin that must not travel to a MySQL site. */
	public const SQLITE_PLUGIN = 'sqlite-database-integration';

	/** Legacy name of our folder in wp-content. */
	public const LEGACY_DIR = 'lw-migration';

	/**
	 * @param Site                      $site        Site snapshot.
	 * @param ExportOptions             $options     Backup options.
	 * @param string                    $storageRoot Our storage root (never exported).
	 * @param array<string, mixed>|null $network     state.network of a network backup; null on a single site.
	 * @return array<int, array{dir: string, entry: string, filter: ExportFilter}>
	 */
	public static function build( Site $site, ExportOptions $options, string $storageRoot, ?array $network = null ): array {
		if ( $options->noFiles ) {
			return [];
		}
		$network = $network ?? [];
		$content = $site->contentDir;
		$shared  = array_merge(
			self::backupFolders( $site, $storageRoot ),
			[ $content . '/' . Format::DATABASE, $content . '/' . Format::PACKAGE, $content . '/' . Format::MULTISITE, $content . '/w3tc-config/master.php' ],
			self::siteFiles( $site ),
			self::optionalFolders( $site, $options ),
			array_map( static fn( string $rel ): string => $content . '/' . $rel, $options->excludeFiles ),
			self::pluginExclusions( $site, $options ),
			self::themeExclusions( $site, $options, [] === $network ? null : array_map( 'strval', (array) ( $network['activeThemes'] ?? [] ) ) )
		);
		// A backup of chosen sites leaves the other sites' folders out; the chosen ones are roots below.
		$walked = array_merge( $shared, NetworkRoots::exclusions( $site->uploadsDir, $content, $network ) );
		$sealed = FolderIdentity::sealable( self::sealedFolders( $site, $storageRoot ), array_merge( [ $content ], self::territories( $site, $network ) ) );
		$roots  = [ self::root( $content, '', array_merge( $walked, self::territories( $site, $network ) ), $sealed ) ];
		if ( ! $options->noMedia && ! NetworkRoots::skipsMainUploads( $network ) ) {
			$roots[] = self::root( $site->uploadsDir, 'uploads', $walked, $sealed );
		}
		if ( ! $options->noPlugins ) {
			$roots[] = self::root( $site->pluginsDir, 'plugins', $walked, $sealed );
		}
		if ( ! $options->noThemes ) {
			foreach ( $site->themeRoots() as $themeRoot ) {
				$roots[] = self::root( $themeRoot, 'themes', $walked, $sealed );
			}
		}
		if ( ! $options->noMedia ) {
			// R11: the filter matches by prefix, so these roots never get the exclusions above.
			foreach ( NetworkRoots::extraRoots( $site->uploadsDir, $content, $network ) as $extra ) {
				$roots[] = self::root( $extra['dir'], $extra['entry'], $shared, $sealed );
			}
		}

		return $roots;
	}

	/**
	 * Folders that belong to a root of their own (uploads, plugins, theme roots,
	 * the chosen subsites' folders), also when an option leaves that root out:
	 * a folder link into one of them is never archived under another root.
	 *
	 * @param Site                      $site    Site snapshot.
	 * @param array<string, mixed>|null $network state.network of a network backup.
	 * @return array<int, string>
	 */
	public static function territories( Site $site, ?array $network = null ): array {
		$extra = array_column( NetworkRoots::extraRoots( $site->uploadsDir, $site->contentDir, $network ?? [] ), 'dir' );

		return array_values( array_filter( array_merge( [ $site->uploadsDir, $site->pluginsDir ], $site->themeRoots(), $extra ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * @param string                $dir      Folder.
	 * @param string                $entry    Entry name prefix.
	 * @param array<int, string>    $excluded Excluded absolute paths.
	 * @param array<string, string> $sealed   Our storage and backups folders, by identity.
	 * @return array{dir: string, entry: string, filter: ExportFilter}
	 */
	private static function root( string $dir, string $entry, array $excluded, array $sealed ): array {
		return [
			'dir'    => rtrim( $dir, '/' ),
			'entry'  => $entry,
			'filter' => new ExportFilter( $excluded, $sealed ),
		];
	}

	/**
	 * Our storage (with its jobs and backups folders), every other storage folder of ours,
	 * our backups folders (the chosen one, the target of an unfinished move, and every
	 * suffixed one in wp-content, also one an earlier install left behind; FileEnumerator
	 * also knows them by identity), the legacy folders and a custom backups folder of
	 * existing .wpress tooling, each also
	 * by its real path: a folder deployed as a link elsewhere stays out when another link
	 * reaches its target. A real path that would swallow wp-content or a root is not added.
	 *
	 * @param Site   $site        Site snapshot.
	 * @param string $storageRoot Our storage root.
	 * @return array<int, string>
	 */
	private static function backupFolders( Site $site, string $storageRoot ): array {
		$content = $site->contentDir;
		$paths   = [ $content . '/' . Repository::EXTERNAL_DIR, $content . '/' . self::LEGACY_DIR ];
		foreach ( array_merge( [ $storageRoot ], self::storageFolders( $content, StorageDir::NAME_PATTERN ) ) as $storage ) {
			array_push( $paths, $storage, $storage . '/jobs', $storage . '/backups' );
		}
		$paths    = array_merge( $paths, $site->backupDirs, self::storageFolders( $content, BackupDirRules::SUFFIX_PATTERN ) );
		$external = self::externalBackups( $site );
		$paths    = array_merge( $paths, '' === $external ? [] : [ $external ] );
		$keep     = array_filter( array_map( 'realpath', array_merge( [ $content ], self::territories( $site ) ) ) );
		foreach ( array_merge( $paths, [ $site->externalBackupsPath ] ) as $path ) {
			$real = 1 === preg_match( '#^(/|[A-Za-z]:[/\\\\])#', $path ) ? realpath( $path ) : false;
			if ( false !== $real && ! self::swallows( $real, $keep ) ) {
				$paths[] = $real;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Every backups or storage folder that is never entered (export) nor
	 * written (import), compared by identity besides its spelling: our storage
	 * and its backups folder, our backups folders, the leftover storage and
	 * suffixed backups folders in wp-content, and the backups folders of
	 * existing .wpress tooling. The caller drops any at or above a folder that
	 * must stay reachable (FolderIdentity::sealable()).
	 *
	 * @param Site   $site        Site snapshot.
	 * @param string $storageRoot Our storage root.
	 * @return array<int, string>
	 */
	public static function sealedFolders( Site $site, string $storageRoot ): array {
		$content = $site->contentDir;
		$folders = array_merge(
			[ $storageRoot, $storageRoot . '/backups', $content . '/' . Repository::EXTERNAL_DIR, $content . '/' . self::LEGACY_DIR ],
			$site->backupDirs,
			self::storageFolders( $content, StorageDir::NAME_PATTERN ),
			self::storageFolders( $content, BackupDirRules::SUFFIX_PATTERN )
		);
		if ( 1 === preg_match( '#^/#', $site->externalBackupsPath ) ) {
			$folders[] = $site->externalBackupsPath;
		}

		return array_values( array_unique( array_filter( $folders, static fn( string $f ): bool => '' !== $f ) ) );
	}

	/**
	 * @param string             $real Real path.
	 * @param array<int, string> $keep Real paths that must stay walkable.
	 * @return bool True when $real is one of $keep or a folder above one.
	 */
	private static function swallows( string $real, array $keep ): bool {
		foreach ( $keep as $path ) {
			if ( $path === $real || str_starts_with( $path, rtrim( $real, '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every folder of ours with this name pattern in wp-content (storage
	 * folders, suffixed backups folders), also one an earlier install left behind.
	 *
	 * @param string $content wp-content folder.
	 * @param string $pattern Name pattern.
	 * @return array<int, string>
	 */
	private static function storageFolders( string $content, string $pattern ): array {
		$names = is_dir( $content ) && is_readable( $content ) ? scandir( $content ) : false;
		$paths = [];
		foreach ( false === $names ? [] : $names as $name ) {
			if ( 1 === preg_match( $pattern, $name ) ) {
				$paths[] = $content . '/' . $name;
			}
		}

		return $paths;
	}

	/**
	 * The wp-config.php candidates and the SQLite database file.
	 *
	 * @param Site $site Site snapshot.
	 * @return array<int, string>
	 */
	private static function siteFiles( Site $site ): array {
		return array_merge( $site->wpConfigPaths(), '' === $site->sqliteDb ? [] : [ $site->sqliteDb ] );
	}

	/**
	 * A custom backups folder of existing .wpress tooling, as configured (only when it
	 * lies below wp-content: never a folder above it).
	 *
	 * @param Site $site Site snapshot.
	 * @return string '' when there is none to leave out by name.
	 */
	private static function externalBackups( Site $site ): string {
		$external = rtrim( (string) preg_replace( '#/+#', '/', $site->externalBackupsPath ), '/' );

		return '' !== $site->contentDir && str_starts_with( $external, rtrim( $site->contentDir, '/' ) . '/' ) ? $external : '';
	}

	/**
	 * Folders in wp-content left out by options; shared by every root so a link
	 * from another root cannot bring them back.
	 *
	 * @param Site          $site    Site snapshot.
	 * @param ExportOptions $options Backup options.
	 * @return array<int, string>
	 */
	private static function optionalFolders( Site $site, ExportOptions $options ): array {
		$paths = [];
		if ( $options->noCache ) {
			$paths[] = $site->contentDir . '/cache';
		}
		if ( $options->noMustUsePlugins ) {
			$paths[] = $site->muPluginsDir;
		}
		if ( $options->noMedia ) {
			$paths[] = $site->contentDir . '/blogs.dir';
		}

		return $paths;
	}

	/**
	 * @param Site          $site    Site snapshot.
	 * @param ExportOptions $options Backup options.
	 * @return array<int, string>
	 */
	private static function pluginExclusions( Site $site, ExportOptions $options ): array {
		$paths = [ self::pluginPath( $site, $site->ownPlugin ), $site->pluginsDir . '/' . self::SQLITE_PLUGIN ];
		if ( $options->noInactivePlugins ) {
			foreach ( array_diff( $site->plugins, $site->activePlugins ) as $plugin ) {
				$paths[] = self::pluginPath( $site, $plugin );
			}
		}

		return $paths;
	}

	/**
	 * @param Site   $site     Site snapshot.
	 * @param string $basename Plugin basename.
	 * @return string Plugin folder or single file; '' (nothing) for an empty basename.
	 */
	private static function pluginPath( Site $site, string $basename ): string {
		if ( '' === $basename ) {
			return '';
		}
		$dir = dirname( $basename );

		return $site->pluginsDir . '/' . ( '.' === $dir ? $basename : $dir );
	}

	/**
	 * @param Site                    $site    Site snapshot.
	 * @param ExportOptions           $options Backup options.
	 * @param array<int, string>|null $active  A network backup: the themes of the exported sites only (as the
	 *                                         reference; the main site's own theme only when it is exported).
	 * @return array<int, string>
	 */
	private static function themeExclusions( Site $site, ExportOptions $options, ?array $active ): array {
		if ( ! $options->noInactiveThemes ) {
			return [];
		}
		$paths = [];
		foreach ( $site->themes as $slug => $root ) {
			$kept = null === $active ? ( $slug === $site->template || $slug === $site->stylesheet ) : in_array( (string) $slug, $active, true );
			if ( ! $kept ) {
				$paths[] = $root . '/' . $slug;
			}
		}

		return $paths;
	}
}
