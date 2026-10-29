<?php
/**
 * Absolute paths an import never writes to.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * The extraction bases may be nested (uploads inside wp-content or ABSPATH), so
 * an entry routed to one base can reach a folder of another: the must-use
 * plugins folder, our storage, backup folders, drop-ins, wp-config.php or this
 * plugin's own folder. Paths compare lower case and normalised (case-insensitive
 * file systems would alias them), each also by its real path when it exists.
 * Our backups folders are also compared by identity (device and inode), so a
 * link above them, another normalisation or a bind mount never reaches them.
 */
final class ProtectedTargets {

	/**
	 * Normalised protected path => skip reason, most specific first.
	 *
	 * @var array<string, string>
	 */
	private array $paths = [];

	/**
	 * Normalised folders a file never replaces (nor a folder above one).
	 *
	 * @var array<int, string>
	 */
	private array $roots = [];

	/**
	 * Normalised wp-content folder (and its real path), where every storage folder of ours lives.
	 *
	 * @var array<int, string>
	 */
	private array $content;

	/**
	 * Our backups folders by identity ("device:inode" => path).
	 *
	 * @var array<string, string>
	 */
	private array $sealed;

	/**
	 * Folder => whether it lies in a sealed folder (one stat chain per folder).
	 *
	 * @var array<string, bool>
	 */
	private array $inSealed = [];

	/**
	 * @param string                $contentDir wp-content folder.
	 * @param array<string, string> $paths      Absolute path => skip reason.
	 * @param array<int, string>    $roots      Absolute folders (the extraction bases).
	 * @param array<int, string>    $sealed     Our backups folders, compared by identity as well (never one above a base).
	 * @throws \InvalidArgumentException When a path is not absolute or has no reason.
	 */
	public function __construct( string $contentDir, array $paths, array $roots, array $sealed = [] ) {
		$this->content = self::variants( $contentDir );
		$this->add( $paths, $roots );
		$this->sealed = FolderIdentity::sealable( $sealed, $roots );
	}

	/**
	 * Everything the live site must keep.
	 *
	 * @param Site   $site        Site snapshot.
	 * @param string $storageRoot Our storage root.
	 * @throws \InvalidArgumentException When this plugin's folder name is unsafe.
	 */
	public static function forSite( Site $site, string $storageRoot ): self {
		$content = rtrim( $site->contentDir, '/' ) . '/';
		$paths   = [
			$site->muPluginsDir                 => ContentRules::PROTECTED_TARGET,
			$storageRoot                        => ContentRules::STORAGE,
			$site->externalBackupsPath          => ContentRules::STORAGE,
			$content . Repository::EXTERNAL_DIR => ContentRules::STORAGE,
			$content . ExportRoots::LEGACY_DIR  => ContentRules::STORAGE,
		];
		foreach ( $site->wpConfigPaths() as $config ) {
			$paths[ $config ] = ContentRules::CONFIG;
		}
		if ( '' !== $site->ownPlugin ) {
			$own = explode( '/', $site->ownPlugin )[0];
			if ( ! PathGuard::isSafeEntryName( $own ) ) {
				throw new \InvalidArgumentException( 'The plugin folder must be one plain folder name.' );
			}
			$paths[ rtrim( $site->pluginsDir, '/' ) . '/' . $own ] = ContentRules::OWN;
		}
		foreach ( $site->backupDirs as $dir ) {
			$paths[ $dir ] = ContentRules::STORAGE;
		}
		unset( $paths[''] );
		$roots = array_merge( [ $site->contentDir, $site->pluginsDir, $site->uploadsDir ], $site->themeRoots() );

		return new self( $site->contentDir, $paths, array_values( array_filter( $roots, static fn( string $r ): bool => '' !== $r ) ), ExportRoots::sealedFolders( $site, $storageRoot ) );
	}

	/**
	 * A copy with more protected paths and roots.
	 *
	 * @param array<string, string> $paths Absolute path => skip reason.
	 * @param array<int, string>    $roots Absolute folders.
	 */
	public function with( array $paths, array $roots ): self {
		$copy = clone $this;
		$copy->add( $paths, $roots );

		return $copy;
	}

	/**
	 * @param string $target Absolute target path.
	 * @return string|null Skip reason ('unsafe' for a base or a folder above one), or null when it may be written.
	 */
	public function covers( string $target ): ?string {
		$typed  = $target;
		$target = self::normalise( $target );
		foreach ( $this->roots as $root ) {
			if ( $root === $target || str_starts_with( $root, $target . '/' ) ) {
				return ContentRules::UNSAFE;
			}
		}
		foreach ( $this->paths as $path => $reason ) {
			if ( $target === $path || str_starts_with( $target, $path . '/' ) ) {
				return $reason;
			}
		}
		if ( $this->sealedTarget( $typed ) ) {
			return ContentRules::STORAGE;
		}
		foreach ( $this->content as $content ) {
			$content .= '/';
			$first    = explode( '/', substr( $target, strlen( $content ) ) )[0];
			if ( str_starts_with( $target, $content ) && ( 1 === preg_match( StorageDir::NAME_PATTERN, $first ) || 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, $first ) ) ) {
				return ContentRules::STORAGE;
			}
		}

		return null;
	}

	/**
	 * Whether the target is one of our backups folders or lies inside one, by
	 * identity: the target itself, then the nearest existing folder above it
	 * and every folder above that.
	 *
	 * @param string $target Absolute target path as given.
	 */
	private function sealedTarget( string $target ): bool {
		if ( [] === $this->sealed ) {
			return false;
		}
		$id = FolderIdentity::of( $target );
		if ( null !== $id && isset( $this->sealed[ $id ] ) ) {
			return true;
		}
		$folder = dirname( $target );
		if ( ! isset( $this->inSealed[ $folder ] ) ) {
			$this->inSealed[ $folder ] = FolderIdentity::passes( FolderIdentity::chain( $folder ), $this->sealed );
		}

		return $this->inSealed[ $folder ];
	}

	/**
	 * @param array<string, string> $paths Absolute path => skip reason.
	 * @param array<int, string>    $roots Absolute folders.
	 * @throws \InvalidArgumentException When a path is not absolute or has no reason.
	 */
	private function add( array $paths, array $roots ): void {
		foreach ( $paths as $path => $reason ) {
			if ( ! str_starts_with( (string) $path, '/' ) || '' === $reason ) {
				throw new \InvalidArgumentException( 'A protected path must be absolute and have a reason.' );
			}
			foreach ( self::variants( (string) $path ) as $variant ) {
				$this->paths[ $variant ] = $reason;
			}
		}
		uksort( $this->paths, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		foreach ( $roots as $root ) {
			array_push( $this->roots, ...self::variants( $root ) );
		}
	}

	/**
	 * The normalised path and, when it exists and differs, its normalised real
	 * path (a target resolved through a link compares against both).
	 *
	 * @param string $path Absolute path.
	 * @return array<int, string>
	 */
	private static function variants( string $path ): array {
		$real = realpath( $path );

		return array_values( array_unique( false === $real ? [ self::normalise( $path ) ] : [ self::normalise( $path ), self::normalise( $real ) ] ) );
	}

	/**
	 * Lower case, single separators, "." and ".." resolved, no trailing separator.
	 *
	 * @param string $path Absolute path.
	 */
	private static function normalise( string $path ): string {
		$parts = [];
		foreach ( explode( '/', strtolower( $path ) ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $parts );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$parts[] = $segment;
			}
		}

		return '/' . implode( '/', $parts );
	}
}
