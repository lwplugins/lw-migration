<?php
/**
 * A chosen backups folder on disk.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * The folder itself, once BackupDirRules chose its place: created one level
 * deep (0750, never through a link), marked as this site's with a marker file,
 * protected by deny files; the marker and the deny files are only ever created
 * (ExclusiveFile), never rewritten. Only the allowed owners (the PHP user and
 * the owner of the storage root, the web server's user) may own it; nobody
 * else may write it. When a step fails, only what this call created is
 * removed. inUse() is the cheap check before every write. WordPress-free:
 * refusals carry reason codes.
 */
final class MarkedFolder {

	public const MARKER = '.lw-migration-backups';

	public const MODE = 0750;

	private const MARKER_TEXT = "lw-migration-backups\n%s\n";

	private const MARKER_VALUE = '/^[a-f0-9]{16}\z/';

	/**
	 * Creates the folder when missing (one level, never through a link), checks
	 * again that it is new, empty or ours, writes the marker and the deny files
	 * (never over an existing name), then checks it like a folder in use.
	 *
	 * @param string          $path   Canonical folder from check().
	 * @param string          $marker This site's marker.
	 * @param array<int, int> $owners Allowed owners, the storage root's first ([] = the PHP user).
	 * @param int|null        $euid   The process's user (tests); null = posix_geteuid().
	 * @return array<int, string> Deny files left alone: a name that already held something else.
	 * @throws Refused When the folder cannot be used after all (RUN_AS_OWNER: root would have to create something).
	 */
	public static function prepare( string $path, string $marker, array $owners = [], ?int $euid = null ): array {
		if ( 1 !== preg_match( self::MARKER_VALUE, $marker ) ) {
			throw new Refused( Refused::INVALID );
		}
		clearstatcache( true ); // The realpath cache too: another process may have swapped a folder.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @is_link( $path ) ) {
			throw new Refused( Refused::LINK );
		}
		$parentId = FolderIdentity::of( dirname( $path ) );
		$asRoot   = self::asRoot( $owners, $euid );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$missing = ! @file_exists( $path );
		if ( $missing && $asRoot ) {
			throw new Refused( Refused::RUN_AS_OWNER ); // Root never creates: whatever it makes, the web server's user could not use.
		}
		$made = $missing && self::makeFolder( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$id = @realpath( $path ) === $path ? FolderIdentity::of( $path ) : null;
		if ( null === $id ) {
			throw new Refused( Refused::LINK ); // Not a folder of its own name: a link, a file, or gone.
		}
		$created = [];
		try {
			self::refuseLoose( $path, $owners );
			self::refuseLooseAncestors( FolderIdentity::chain( dirname( $path ) ) );
			$ours = self::contents( $path, $marker );
			if ( $asRoot && ( ! $ours || [] !== self::missingDenyFiles( $path ) ) ) {
				throw new Refused( Refused::RUN_AS_OWNER );
			}
			if ( ! $ours ) {
				$created[ self::MARKER ] = ExclusiveFile::create( $path, self::MARKER, sprintf( self::MARKER_TEXT, $marker ), $id ) ?? throw new Refused( Refused::FOREIGN );
			}
			$left    = self::protect( $path, $id, $created );
			$problem = self::inUse( $path, $marker, $owners );
			if ( null !== $problem ) {
				throw new Refused( $problem );
			}
		} catch ( Refused $e ) {
			self::undo( $path, $created, $made ? $id : null, $parentId );
			throw $e;
		}

		return $left;
	}

	/**
	 * Creates every missing deny file of a chosen folder (robots.txt included).
	 *
	 * @param string                $path    Folder (canonical).
	 * @param string                $id      Its identity.
	 * @param array<string, string> $created Filled with each file made (name => identity), also when a later one fails.
	 * @return array<int, string> Names left alone: something else is there.
	 * @throws Refused NOT_WRITABLE or CHANGED.
	 */
	public static function protect( string $path, string $id, array &$created = [] ): array {
		$left = [];
		foreach ( StorageDir::chosenDenyFiles() as $name => $contents ) {
			if ( StorageDir::isOurFile( $path . '/' . $name, $contents ) ) {
				continue;
			}
			$fileId = ExclusiveFile::create( $path, $name, $contents, $id );
			if ( null === $fileId ) {
				$left[] = $name;
			} else {
				$created[ $name ] = $fileId;
			}
		}

		return $left;
	}

	/**
	 * Removes what a failed prepare() made: its files (by identity) and its new
	 * folder, only while the folder above is still the one it was made in.
	 *
	 * @param string                $path     Folder.
	 * @param array<string, string> $created  Files made (name => identity).
	 * @param string|null           $folderId The new folder's identity, or null when it was there before.
	 * @param string|null           $parentId The identity of the folder above, from before.
	 */
	private static function undo( string $path, array $created, ?string $folderId, ?string $parentId ): void {
		foreach ( $created as $name => $fileId ) {
			ExclusiveFile::removeOwn( $path . '/' . $name, $fileId );
		}
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$parentSame = null !== $parentId && @realpath( dirname( $path ) ) === dirname( $path ) && FolderIdentity::of( dirname( $path ) ) === $parentId;
		if ( null !== $folderId && $parentSame && FolderIdentity::of( $path ) === $folderId ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only our new folder; it stays when something else is in it.
			@rmdir( $path );
		}
	}

	/**
	 * @param string          $path   Stored folder.
	 * @param string          $marker This site's marker.
	 * @param array<int, int> $owners Allowed owners ([] = the PHP user).
	 * @return string|null A reason code, or null when the folder may be written.
	 */
	public static function inUse( string $path, string $marker, array $owners = [] ): ?string {
		clearstatcache( true ); // The realpath cache too: another process may have swapped a folder.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @is_link( $path ) ) {
			return Refused::LINK;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( ! @is_dir( $path ) ) {
			return Refused::MISSING;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( @realpath( $path ) !== $path ) {
			return Refused::CHANGED;
		}
		$owner = self::markerOf( $path );
		if ( null === $owner || '' === $marker || ! hash_equals( $owner, $marker ) ) {
			return Refused::FOREIGN;
		}
		try {
			self::refuseLoose( $path, $owners );
			self::refuseLooseAncestors( FolderIdentity::chain( dirname( $path ) ) );
		} catch ( Refused $e ) {
			return $e->reason;
		}
		if ( ! self::protectedNow( $path ) ) {
			return Refused::UNPROTECTED;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		return @is_writable( $path ) ? null : Refused::NOT_WRITABLE;
	}

	/**
	 * @param string $dir Folder.
	 * @return string|null The marker written into it, or null.
	 */
	public static function markerOf( string $dir ): ?string {
		$file = rtrim( $dir, '/' ) . '/' . self::MARKER;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @is_link( $file ) || ! @is_file( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file; unreadable reads as no marker.
		$text = (string) @file_get_contents( $file, false, null, 0, 256 );

		return 1 === preg_match( '/^lw-migration-backups\n([a-f0-9]{16})\n\z/', $text, $match ) ? $match[1] : null;
	}

	/**
	 * Our hidden temps (a create that died) do not make a folder non-empty.
	 *
	 * @param string $path   Existing folder.
	 * @param string $marker This site's marker.
	 * @return bool True when the folder is ours, false when it is empty.
	 * @throws Refused NOT_WRITABLE, NOT_EMPTY or FOREIGN.
	 */
	public static function contents( string $path, string $marker ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure becomes a reason code without the path.
		$items = @scandir( $path );
		if ( false === $items ) {
			throw new Refused( Refused::NOT_WRITABLE );
		}
		$items = array_filter( $items, static fn( string $item ): bool => '.' !== $item && '..' !== $item && ! TempName::is( $item ) );
		if ( [] === $items ) {
			return false;
		}
		if ( ! in_array( self::MARKER, $items, true ) ) {
			throw new Refused( Refused::NOT_EMPTY );
		}
		$owner = self::markerOf( $path );
		if ( null === $owner || '' === $marker || ! hash_equals( $owner, $marker ) ) {
			throw new Refused( Refused::FOREIGN );
		}

		return true;
	}

	/**
	 * Only the allowed owners may own the folder, and neither its group nor others may write it.
	 *
	 * @param string          $path   Existing folder.
	 * @param array<int, int> $owners Allowed owners ([] = the PHP user; nothing to compare without POSIX).
	 * @throws Refused WORLD_WRITABLE.
	 */
	public static function refuseLoose( string $path, array $owners = [] ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$perms = @fileperms( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$owner   = @fileowner( $path );
		$allowed = self::allowedOwners( $owners );
		if ( false === $perms || 0 !== ( $perms & 0022 ) || ( [] !== $allowed && ! in_array( $owner, $allowed, true ) ) ) {
			throw new Refused( Refused::WORLD_WRITABLE );
		}
	}

	/**
	 * The folder above a new one may be written by its owner only (sticky or
	 * not: another user could swap the new folder for a link), and by us.
	 *
	 * @param string $path New canonical folder.
	 * @throws Refused WORLD_WRITABLE or NOT_WRITABLE.
	 */
	public static function checkNew( string $path ): void {
		$parent = dirname( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$perms = @fileperms( $parent );
		if ( false === $perms || 0 !== ( $perms & 0022 ) ) {
			throw new Refused( Refused::WORLD_WRITABLE );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( ! @is_writable( $parent ) ) {
			throw new Refused( Refused::NOT_WRITABLE );
		}
	}

	/**
	 * Every folder above may be writable by all only with the sticky bit (like /tmp).
	 *
	 * @param array<int, array{path: string, id: string, mode: int}> $chain From FolderIdentity::chain().
	 * @throws Refused WORLD_WRITABLE.
	 */
	public static function refuseLooseAncestors( array $chain ): void {
		foreach ( $chain as $link ) {
			if ( 0 !== ( $link['mode'] & 0002 ) && 0 === ( $link['mode'] & 01000 ) ) {
				throw new Refused( Refused::WORLD_WRITABLE );
			}
		}
	}

	/**
	 * Who may own the folder: the given owners ([] = the PHP user); as root
	 * only the storage root's owner (a folder root owns is no use to the site).
	 *
	 * @param array<int, int> $owners Allowed owners, the storage root's first.
	 * @return array<int, int> Empty when nothing can be compared (no POSIX functions).
	 */
	private static function allowedOwners( array $owners ): array {
		if ( isset( $owners[0] ) && self::asRoot( $owners ) ) {
			return [ $owners[0] ];
		}

		return [] !== $owners ? $owners : ( function_exists( 'posix_geteuid' ) ? [ posix_geteuid() ] : [] );
	}

	/**
	 * Whether this process is root while the storage root belongs to another
	 * user (or is unknown). Ownership is never changed (chown follows links),
	 * so root may use a prepared folder but never create anything in it.
	 *
	 * @param array<int, int> $owners Allowed owners, the storage root's first.
	 * @param int|null        $euid   The process's user; null = posix_geteuid().
	 */
	public static function asRoot( array $owners, ?int $euid = null ): bool {
		$euid = $euid ?? ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : null );

		return 0 === $euid && ( ! isset( $owners[0] ) || 0 !== $owners[0] );
	}

	/**
	 * @param string $path Folder.
	 * @return array<int, string> Deny files prepare() would have to create.
	 */
	private static function missingDenyFiles( string $path ): array {
		$missing = [];
		foreach ( array_keys( StorageDir::chosenDenyFiles() ) as $name ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			if ( ! @file_exists( $path . '/' . $name ) && ! @is_link( $path . '/' . $name ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Every deny file is in place (ours, or something left alone under its name),
	 * and, unless the folder has our random name, no folder above serves pages.
	 *
	 * @param string $path Folder.
	 */
	private static function protectedNow( string $path ): bool {
		foreach ( array_keys( StorageDir::chosenDenyFiles() ) as $name ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			if ( ! @file_exists( $path . '/' . $name ) && ! @is_link( $path . '/' . $name ) ) {
				return false;
			}
		}
		if ( 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, basename( $path ) ) ) {
			return true;
		}
		$skip = static fn( string $dir ): bool => in_array( $dir, BackupDirRules::SYSTEM_EXACT, true );

		return ! FolderIdentity::servesPages( FolderIdentity::chain( dirname( $path ) ), $skip );
	}

	/**
	 * One level, mode 0750, never through a link; the direct parent may be
	 * written by its owner only, and must still be itself after the mkdir.
	 *
	 * @param string $path New folder.
	 * @return bool True when this call created it.
	 * @throws Refused NOT_WRITABLE, WORLD_WRITABLE or LINK.
	 */
	public static function makeFolder( string $path ): bool {
		clearstatcache( true );
		$parent = dirname( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$real = @realpath( $parent );
		if ( $real !== $parent ) {
			throw new Refused( false === $real ? Refused::NOT_WRITABLE : Refused::LINK );
		}
		self::checkNew( $path );
		$parentId = FolderIdentity::of( $parent );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure becomes a reason code without the path.
		if ( ! @mkdir( $path, self::MODE ) ) {
			clearstatcache();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			if ( @is_dir( $path ) && ! @is_link( $path ) ) {
				return false; // Made meanwhile by someone else: checked like any existing folder.
			}
			throw new Refused( Refused::NOT_WRITABLE );
		}
		$made = FolderIdentity::entry( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- our new folder only (identity checked).
		$same = null !== $made && FolderIdentity::of( $parent ) === $parentId && @realpath( $path ) === $path && FolderIdentity::of( $path ) === $made;
		if ( ! $same ) {
			if ( null !== $made && FolderIdentity::entry( $path ) === $made ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only the folder this call made.
				@rmdir( $path );
			}
			throw new Refused( Refused::LINK );
		}

		return true;
	}
}
