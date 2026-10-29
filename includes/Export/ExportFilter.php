<?php
/**
 * Absolute paths left out of a backup.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Storage\FolderIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * A path is excluded when it equals an excluded path or lies below it. An
 * excluded path is also kept with its parent folder resolved (realpath), so
 * it stays excluded when reached through a symlinked parent or root. The
 * excluded item itself is never resolved: excluding a symlink must not
 * exclude the folder it points to. Sealed folders (our backups folders) are
 * compared by identity (device and inode) instead: a link, a case variant on
 * a case-insensitive file system or a bind mount never reaches them.
 */
final class ExportFilter {

	/** @var array<int, string> */
	private array $paths;

	/** @var array<string, string> Sealed folders: "device:inode" => path. */
	private array $sealed;

	/** @var array<string, true>|null "device:inode" of the files directly in the sealed folders, read once. */
	private ?array $sealedFiles = null;

	/**
	 * @param array<int, string>    $paths  Absolute paths.
	 * @param array<string, string> $sealed Folders never entered, from FolderIdentity::sealable().
	 */
	public function __construct( array $paths, array $sealed = [] ) {
		$this->sealed = $sealed;
		$normal       = [];
		foreach ( $paths as $path ) {
			$path     = self::normalize( $path );
			$normal[] = $path;
			$parent   = '' === $path ? false : realpath( dirname( $path ) );
			if ( false !== $parent ) {
				$normal[] = self::normalize( $parent . '/' . basename( $path ) );
			}
		}
		$this->paths = array_values( array_unique( array_filter( $normal, static fn( string $p ): bool => '' !== $p ) ) );
	}

	public function excludes( string $path ): bool {
		$path = self::normalize( $path );
		foreach ( $this->paths as $excluded ) {
			if ( $path === $excluded || str_starts_with( $path, $excluded . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $real Resolved folder path.
	 * @return bool True when the folder is a sealed one.
	 */
	public function seals( string $real ): bool {
		$id = [] === $this->sealed ? null : FolderIdentity::of( $real );

		return null !== $id && isset( $this->sealed[ $id ] );
	}

	/**
	 * @param string $real Resolved folder path.
	 * @return bool True when the folder is a sealed one or lies inside one.
	 */
	public function sealsTree( string $real ): bool {
		return [] !== $this->sealed && FolderIdentity::passes( FolderIdentity::chain( $real ), $this->sealed );
	}

	/**
	 * A file with more than one name may be a hard link to a backup: it is
	 * left out when it is one of the files of a sealed folder.
	 *
	 * @param string $path Regular file.
	 */
	public function sealsFile( string $path ): bool {
		if ( [] === $this->sealed ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file is unknown, never a warning with the path.
		$stat = @stat( $path );
		if ( false === $stat || (int) $stat['nlink'] < 2 ) {
			return false;
		}

		return isset( $this->sealedFiles()[ $stat['dev'] . ':' . $stat['ino'] ] );
	}

	/**
	 * @return array<string, true>
	 */
	private function sealedFiles(): array {
		if ( null === $this->sealedFiles ) {
			$this->sealedFiles = [];
			foreach ( $this->sealed as $dir ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is unknown, never a warning with the path.
				foreach ( (array) @scandir( $dir ) as $name ) {
					$id = '.' === $name || '..' === $name ? null : FolderIdentity::entry( $dir . '/' . $name );
					if ( null !== $id ) {
						$this->sealedFiles[ $id ] = true;
					}
				}
			}
		}

		return $this->sealedFiles;
	}

	private static function normalize( string $path ): string {
		return rtrim( (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', trim( $path ) ) ), '/' );
	}
}
