<?php
/**
 * Folders compared the way the file system compares them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * A path string is not a boundary: a case-insensitive or normalising file
 * system, a bind mount or a link gives one folder many names. Two names are the
 * same folder when stat() reports the same device and inode. Every stat() here
 * is silenced, so a folder outside open_basedir is simply unknown (never a PHP
 * warning that would carry the path). WordPress-free.
 */
final class FolderIdentity {

	/** A folder that directly holds one of these serves pages: it counts as a web root. */
	public const PAGE_FILES = [ 'index.php', 'index.html', 'index.htm', 'index.shtml', 'default.php', 'default.htm', 'default.html', '.htaccess', '.user.ini', 'wp-config.php' ];

	/**
	 * @param string $path Folder (links followed).
	 * @return string|null "device:inode", or null when it is no readable folder.
	 */
	public static function of( string $path ): ?string {
		$stat = self::stat( $path );

		return null === $stat ? null : $stat['id'];
	}

	/**
	 * The nearest existing folder at or above $path, by its real path, then
	 * every folder above it up to /.
	 *
	 * @param string $path Folder, existing or not.
	 * @return array<int, array{path: string, id: string, mode: int}> Nearest first.
	 */
	public static function chain( string $path ): array {
		clearstatcache();
		$path = self::nearest( $path );
		if ( null === $path ) {
			return [];
		}
		$chain = [];
		while ( true ) {
			$stat = self::stat( $path );
			if ( null !== $stat ) {
				$chain[] = [ 'path' => $path ] + $stat;
			}
			$up = dirname( $path );
			if ( $up === $path ) {
				return $chain;
			}
			$path = $up;
		}
	}

	/**
	 * The real path of a typed folder, or of its parent plus its name when it
	 * is new. A name its folder lists but PHP cannot reach (a link out of
	 * open_basedir) is never taken for a new folder.
	 *
	 * @param string $typed    Normalised typed path.
	 * @param string $basedir  The open_basedir setting ('' = none).
	 * @return array{0: string, 1: bool} The canonical path and whether the typed path itself is a link.
	 * @throws Refused LINK, NOT_FOLDER, OPEN_BASEDIR or NO_PARENT.
	 */
	public static function canonical( string $typed, string $basedir ): array {
		clearstatcache();
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$linked = @is_link( $typed );
		if ( @file_exists( $typed ) ) {
			$real = @is_dir( $typed ) ? @realpath( $typed ) : false;
			if ( false === $real ) {
				throw new Refused( $linked ? Refused::LINK : Refused::NOT_FOLDER );
			}
			return [ $real, $linked ];
		}
		$parent = @realpath( dirname( $typed ) );
		$folder = false !== $parent && @is_dir( $parent );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $linked || self::unreachable( $typed ) ) {
			throw new Refused( '' !== trim( $basedir ) && ! $linked ? Refused::OPEN_BASEDIR : Refused::LINK );
		}
		if ( ! $folder ) {
			throw new Refused( Refused::NO_PARENT );
		}

		return [ rtrim( (string) $parent, '/' ) . '/' . basename( $typed ), false ];
	}

	/**
	 * Whether $path or a folder above it is listed by its parent although PHP
	 * cannot resolve it (a link out of open_basedir, or a link to nothing).
	 *
	 * @param string $path Path.
	 */
	private static function unreachable( string $path ): bool {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$item = $path;
		while ( dirname( $item ) !== $item && false === @realpath( $item ) ) {
			$listed = @scandir( dirname( $item ) );
			if ( is_array( $listed ) && in_array( basename( $item ), $listed, true ) ) {
				return true;
			}
			$item = dirname( $item );
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		return false;
	}

	/**
	 * @param array<int, string> $paths Folders; missing ones are left out.
	 * @return array<string, string> "device:inode" => path.
	 */
	public static function ids( array $paths ): array {
		$ids = [];
		foreach ( $paths as $path ) {
			$id = '' === $path ? null : self::of( $path );
			if ( null !== $id && ! isset( $ids[ $id ] ) ) {
				$ids[ $id ] = $path;
			}
		}

		return $ids;
	}

	/**
	 * Folders never to enter, by identity, leaving out any that is one of the
	 * kept folders or lies above one (sealing it would seal what must stay).
	 *
	 * @param array<int, string> $folders Folders; missing ones are left out.
	 * @param array<int, string> $keep    Folders that must stay reachable.
	 * @return array<string, string> "device:inode" => path.
	 */
	public static function sealable( array $folders, array $keep ): array {
		$ids = self::ids( $folders );
		foreach ( $keep as $path ) {
			foreach ( '' === $path || [] === $ids ? [] : self::chain( $path ) as $link ) {
				unset( $ids[ $link['id'] ] );
			}
		}

		return $ids;
	}

	/**
	 * Whether a chain passes through one of the folders.
	 *
	 * @param array<int, array{path: string, id: string, mode: int}> $chain   From chain().
	 * @param array<string, string>                                  $folders From ids().
	 * @param bool                                                   $orSelf  Whether the chain's first folder counts too.
	 */
	public static function passes( array $chain, array $folders, bool $orSelf = true ): bool {
		foreach ( $chain as $i => $link ) {
			if ( ( $orSelf || 0 !== $i ) && isset( $folders[ $link['id'] ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a folder of the chain directly holds a file that makes a web
	 * server serve it (PAGE_FILES). A marked folder's own byte-identical deny
	 * files do not count: they are what protects it.
	 *
	 * @param array<int, array{path: string, id: string, mode: int}> $chain From chain().
	 * @param \Closure                                               $skip  fn( string $path ): bool, folders never asked (system folders).
	 */
	public static function servesPages( array $chain, \Closure $skip ): bool {
		$deny = StorageDir::chosenDenyFiles();
		foreach ( $chain as $link ) {
			if ( $skip( $link['path'] ) ) {
				continue;
			}
			$marked = null !== MarkedFolder::markerOf( $link['path'] );
			foreach ( self::PAGE_FILES as $file ) {
				$path = $link['path'] . '/' . $file;
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
				if ( ( @file_exists( $path ) || @is_link( $path ) ) && ! ( $marked && isset( $deny[ $file ] ) && StorageDir::isOurFile( $path, $deny[ $file ] ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * $path spelled through the nearest folder of the list it is in (by
	 * identity), using a spelling of that folder that is its own real path.
	 *
	 * @param string                                                 $path    Canonical path below or at $chain[0].
	 * @param array<int, array{path: string, id: string, mode: int}> $chain   From chain( $path ).
	 * @param array<int, string>                                     $folders Configured folders.
	 */
	public static function respell( string $path, array $chain, array $folders ): string {
		foreach ( $chain as $link ) {
			foreach ( $folders as $folder ) {
				$folder = '/' === $folder ? '/' : rtrim( $folder, '/' );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown.
				if ( '' !== $folder && @realpath( $folder ) === $folder && self::of( $folder ) === $link['id'] && str_starts_with( $path, $link['path'] ) ) {
					return rtrim( $folder, '/' ) . substr( $path, strlen( rtrim( $link['path'], '/' ) ) );
				}
			}
		}

		return $path;
	}

	/**
	 * @param string $path File, link or folder (links not followed).
	 * @return string|null "device:inode" of the entry itself.
	 */
	public static function entry( string $path ): ?string {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$stat = @lstat( $path );

		return false === $stat || 0 === (int) $stat['ino'] ? null : $stat['dev'] . ':' . $stat['ino'];
	}

	/**
	 * @param string $path Folder, existing or not.
	 * @return string|null The real path of the nearest existing folder at or above $path.
	 */
	private static function nearest( string $path ): ?string {
		while ( true ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			$real = @realpath( $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			if ( false !== $real && @is_dir( $real ) ) {
				return $real;
			}
			$up = dirname( $path );
			if ( $up === $path ) {
				return null;
			}
			$path = $up;
		}
	}

	/**
	 * @param string $path Folder.
	 * @return array{id: string, mode: int}|null
	 */
	private static function stat( string $path ): ?array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$stat = @stat( $path );
		if ( false === $stat || 0040000 !== ( $stat['mode'] & 0170000 ) || 0 === (int) $stat['ino'] ) {
			return null; // No folder, or a platform without inode numbers: no identity.
		}

		return [
			'id'   => $stat['dev'] . ':' . $stat['ino'],
			'mode' => (int) $stat['mode'],
		];
	}
}
