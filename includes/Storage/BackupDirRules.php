<?php
/**
 * Which folder may hold the backups.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * A chosen backups folder is an absolute, canonical folder outside the site's
 * own folders (plugins, themes, uploads, core) and the system trees, apart from
 * every other backup folder, inside open_basedir, not a link, changeable by the
 * site's user only. The typed folder is only the parent (ParentFolder): the
 * folder gets an unguessable lw-migration-backups-<12 hex> name (the protection
 * where .htaccess is ignored); a new parent is made with it (NewFolder), except
 * in a web root. Only a folder this site marked before is used as it is; inside
 * wp-content only wp-content itself may be the parent. Every place test runs on
 * the typed name before the file system is asked, then on the real path and by
 * folder identity (FolderIdentity), so another spelling of a folder is the same folder.
 * prepare() creates and marks it without replacing anything; inUse() is the
 * cheap check before every write. WordPress-free: refusals carry reason codes.
 */
final class BackupDirRules {

	public const MAX_BYTES = 1024;

	public const MARKER = MarkedFolder::MARKER;

	/** Name of a folder the plugin creates inside a web root. */
	public const SUFFIX_PATTERN = '/^lw-migration-backups-[a-f0-9]{12}\z/';

	public const MODE = MarkedFolder::MODE;

	public const SYSTEM_TREES = [ '/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32', '/proc', '/run', '/sbin', '/sys', '/tmp', '/var/lock', '/var/run', '/var/tmp', '/usr/bin', '/usr/include', '/usr/lib', '/usr/lib64', '/usr/libexec', '/usr/sbin', '/usr/share', '/usr/src', '/usr/local/bin', '/usr/local/etc', '/usr/local/include', '/usr/local/lib', '/usr/local/sbin', '/usr/local/share', '/private/etc', '/private/tmp', '/private/var/run', '/private/var/tmp' ];

	public const SYSTEM_EXACT = [ '/', '/home', '/usr', '/usr/local', '/var', '/opt', '/srv', '/mnt', '/media', '/private', '/private/var' ];

	/** A folder that directly holds one of these serves pages: it counts as a web root. */
	public const PAGE_FILES = FolderIdentity::PAGE_FILES;

	/**
	 * Makes the 12 hex of a new folder inside a web root: fn(): string.
	 *
	 * @var \Closure
	 */
	private \Closure $suffix;

	/**
	 * @param array<int, string> $webRoots    Web roots (typed and real paths): inside one, the folder gets a random name.
	 * @param array<int, string> $forbidden   The site's own folders (plugins, mu-plugins, themes, uploads, wp-admin, wp-includes): never inside one.
	 * @param array<int, string> $overlaps    Other backup folders and our storage: never inside, never above.
	 * @param string             $openBasedir The open_basedir setting ('' = none).
	 * @param string             $marker      This site's marker (16 hex; '' = none yet).
	 * @param array<int, string> $trees       System trees, never used.
	 * @param array<int, string> $exact       Shared folders never used themselves.
	 * @param \Closure|null      $suffix      fn(): string (tests); default 6 random bytes as hex.
	 * @param array<int, string> $contentDirs wp-content: its subfolders belong to WordPress and plugins; only the folder itself may be the parent.
	 * @param array<int, string> $emptied     Folders WordPress empties by itself (upgrade, upgrade-temp-backup, languages): never inside one.
	 * @param array<int, int>    $owners      Who may own an existing folder (StorageDir::owners(); [] = the PHP user).
	 */
	public function __construct( private array $webRoots, private array $forbidden, private array $overlaps, private string $openBasedir, private string $marker, private array $trees = self::SYSTEM_TREES, private array $exact = self::SYSTEM_EXACT, ?\Closure $suffix = null, private array $contentDirs = [], private array $emptied = [], private array $owners = [] ) {
		$this->suffix = $suffix ?? static fn(): string => bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Control and invisible format characters are refused on the raw value; only
	 * spaces around it are trimmed.
	 *
	 * @param string $raw What the admin entered.
	 * @throws Refused INVALID or NOT_ABSOLUTE.
	 */
	public static function normalise( string $raw ): string {
		if ( strlen( $raw ) > self::MAX_BYTES || 1 !== preg_match( '//u', $raw ) || 1 === preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\\\\]|(?! )\p{Zs}/u', $raw ) ) {
			throw new Refused( Refused::INVALID );
		}
		$raw = trim( $raw, ' ' );
		if ( '' === $raw ) {
			throw new Refused( Refused::INVALID );
		}
		if ( '/' !== $raw[0] ) {
			throw new Refused( Refused::NOT_ABSOLUTE );
		}
		$parts = array_values( array_filter( explode( '/', $raw ), static fn( string $part ): bool => '' !== $part ) );
		foreach ( $parts as $part ) {
			// "." and "..", and names some file systems shorten to another folder's name.
			if ( '.' === $part || '..' === $part || 1 === preg_match( '/(^ |[. ]\z)/', $part ) ) {
				throw new Refused( Refused::INVALID );
			}
		}

		return '/' . implode( '/', $parts );
	}

	/**
	 * @param string $raw What the admin entered.
	 * @return string The canonical folder to store.
	 * @throws Refused With the first reason that applies.
	 */
	public function check( string $raw ): string {
		clearstatcache( true ); // The realpath cache too: another process may have swapped a folder.
		$typed = self::normalise( $raw );
		$this->refuseByName( $typed );
		[ $path, $linked ] = FolderIdentity::canonical( $typed, $this->openBasedir );
		$this->refuseByName( $path );
		$chain = FolderIdentity::chain( $path );
		$this->refuseByIdentity( $path, $chain );
		$ours      = $this->isOurSuffixFolder( $path );
		$container = '';
		$webRoot   = ! $ours && $this->inWebRoot( $typed, $path, $chain );
		$this->refuseContentSubfolder( $ours ? dirname( $path ) : $path );
		if ( $linked && ! $webRoot ) {
			throw new Refused( Refused::LINK );
		}
		if ( ! $ours && ( $webRoot || ! ParentFolder::isOurs( $path, $this->marker ) ) ) {
			// The typed folder is only the parent; the folder itself gets a name nobody can guess.
			ParentFolder::refuseForeign( $path, $this->marker );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
			$missing = ! @is_dir( $path );
			if ( $missing && $webRoot ) {
				throw new Refused( Refused::NO_PARENT );
			}
			$container = $missing ? $path : '';
			$path      = FolderIdentity::respell( $path, $chain, $this->webRoots ) . '/lw-migration-backups-' . ( $this->suffix )();
		}
		$this->refuseAbove( $path );
		MarkedFolder::refuseLooseAncestors( $chain );
		if ( '' !== $container ) {
			MarkedFolder::checkNew( $container );
		} elseif ( @is_dir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			$this->checkExisting( $path );
		} else {
			MarkedFolder::checkNew( $path );
		}

		return $path;
	}

	/**
	 * Whether typing this existing folder makes a random-named folder in it
	 * (any but one already marked as ours outside a web root).
	 *
	 * @param string $folder An existing folder.
	 */
	public function makesRandomChild( string $folder ): bool {
		try {
			[ $path ] = FolderIdentity::canonical( $folder, $this->openBasedir );
		} catch ( Refused $e ) {
			return false;
		}

		return $this->inWebRoot( $folder, $path, FolderIdentity::chain( $path ) ) || ParentFolder::isOther( $path, $this->marker );
	}

	/**
	 * @see MarkedFolder::prepare()
	 * @param string          $path   Canonical folder from check().
	 * @param string          $marker This site's marker.
	 * @param array<int, int> $owners Allowed owners (StorageDir::owners()).
	 * @return array<int, string> Deny files left alone.
	 * @throws Refused When the folder cannot be used after all.
	 */
	public static function prepare( string $path, string $marker, array $owners = [] ): array {
		return NewFolder::prepare( $path, $marker, $owners );
	}

	/**
	 * @see MarkedFolder::inUse()
	 * @param string          $path   Stored folder.
	 * @param string          $marker This site's marker.
	 * @param array<int, int> $owners Allowed owners (StorageDir::owners()).
	 */
	public static function inUse( string $path, string $marker, array $owners = [] ): ?string {
		return MarkedFolder::inUse( $path, $marker, $owners );
	}

	/**
	 * @param string $dir Folder.
	 */
	public static function markerOf( string $dir ): ?string {
		return MarkedFolder::markerOf( $dir );
	}

	/**
	 * String tests only: no file-system call, so a refused path is never probed.
	 *
	 * @param string $path Typed or canonical path.
	 * @throws Refused SYSTEM, SITE_FOLDER, OVERLAP, CONTENT_SUBFOLDER or OPEN_BASEDIR.
	 */
	private function refuseByName( string $path ): void {
		if ( in_array( $path, array_map( [ self::class, 'trimmed' ], $this->exact ), true ) || self::inAny( $path, $this->trees ) ) {
			throw new Refused( Refused::SYSTEM );
		}
		if ( self::inAny( $path, $this->forbidden ) ) {
			throw new Refused( Refused::SITE_FOLDER );
		}
		if ( self::inAny( $path, $this->overlaps ) ) {
			throw new Refused( Refused::OVERLAP );
		}
		foreach ( $this->contentDirs as $content ) {
			$content = self::trimmed( $content );
			$direct  = dirname( $path ) === $content && 1 === preg_match( self::SUFFIX_PATTERN, basename( $path ) );
			if ( self::within( $path, $content ) && $path !== $content && ! $direct ) {
				throw new Refused( Refused::CONTENT_SUBFOLDER ); // Our own suffix folder is decided on the real folder.
			}
		}
		if ( self::inAny( $path, $this->emptied ) ) {
			throw new Refused( Refused::CONTENT_SUBFOLDER );
		}
		OpenBasedir::refuse( $path, $this->openBasedir );
	}

	/**
	 * @param string                                                 $path  Canonical path.
	 * @param array<int, array{path: string, id: string, mode: int}> $chain Its folder (or parent) and everything above.
	 * @throws Refused SYSTEM, SITE_FOLDER, CONTENT_SUBFOLDER or OVERLAP.
	 */
	private function refuseByIdentity( string $path, array $chain ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$self = @is_dir( $path ) && [] !== $chain;
		if ( ( $self && isset( FolderIdentity::ids( $this->exact )[ $chain[0]['id'] ] ) ) || FolderIdentity::passes( $chain, FolderIdentity::ids( $this->trees ) ) ) {
			throw new Refused( Refused::SYSTEM );
		}
		if ( FolderIdentity::passes( $chain, FolderIdentity::ids( $this->forbidden ) ) ) {
			throw new Refused( Refused::SITE_FOLDER );
		}
		if ( FolderIdentity::passes( $chain, FolderIdentity::ids( $this->emptied ) ) ) {
			throw new Refused( Refused::CONTENT_SUBFOLDER );
		}
		if ( FolderIdentity::passes( $chain, FolderIdentity::ids( $this->overlaps ) ) ) {
			throw new Refused( Refused::OVERLAP );
		}
	}

	/**
	 * Below wp-content only wp-content itself may hold the folder (as its parent).
	 *
	 * @param string $location The folder's parent in suffix mode, else the folder.
	 * @throws Refused CONTENT_SUBFOLDER.
	 */
	private function refuseContentSubfolder( string $location ): void {
		$ids = FolderIdentity::ids( $this->contentDirs );
		foreach ( $this->contentDirs as $content ) {
			if ( self::within( $location, $content ) && self::trimmed( $content ) !== $location ) {
				throw new Refused( Refused::CONTENT_SUBFOLDER );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( FolderIdentity::passes( FolderIdentity::chain( $location ), $ids, ! @is_dir( $location ) ) ) {
			throw new Refused( Refused::CONTENT_SUBFOLDER );
		}
	}

	/**
	 * A web root by name or identity, or below a folder that serves pages.
	 *
	 * @param string                                                 $typed Typed path.
	 * @param string                                                 $path  Canonical path.
	 * @param array<int, array{path: string, id: string, mode: int}> $chain Its folder (or parent) and everything above.
	 */
	private function inWebRoot( string $typed, string $path, array $chain ): bool {
		if ( self::inAny( $typed, $this->webRoots ) || self::inAny( $path, $this->webRoots ) || FolderIdentity::passes( $chain, FolderIdentity::ids( $this->webRoots ) ) ) {
			return true;
		}
		$exact = array_map( [ self::class, 'trimmed' ], $this->exact );

		return FolderIdentity::servesPages( $chain, fn( string $dir ): bool => in_array( $dir, $exact, true ) || self::inAny( $dir, $this->trees ) );
	}

	/**
	 * An existing folder with the random name pattern that already carries our marker (never a typed look-alike).
	 *
	 * @param string $path Canonical path.
	 */
	private function isOurSuffixFolder( string $path ): bool {
		return 1 === preg_match( self::SUFFIX_PATTERN, basename( $path ) ) && @is_dir( $path ) && // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- never a warning with the path.
			'' !== $this->marker && MarkedFolder::markerOf( $path ) === $this->marker;
	}

	/**
	 * The final folder never holds another backup folder (by name or identity).
	 *
	 * @param string $path The final folder.
	 * @throws Refused OVERLAP.
	 */
	private function refuseAbove( string $path ): void {
		$self = null === FolderIdentity::of( $path ) ? [] : FolderIdentity::ids( [ $path ] );
		foreach ( $this->overlaps as $other ) {
			if ( '' !== $other && ( self::within( $other, $path ) || FolderIdentity::passes( FolderIdentity::chain( $other ), $self ) ) ) {
				throw new Refused( Refused::OVERLAP );
			}
		}
	}

	/**
	 * @param string $path Existing canonical folder.
	 * @throws Refused WORLD_WRITABLE, NOT_WRITABLE, NOT_EMPTY or FOREIGN.
	 */
	private function checkExisting( string $path ): void {
		MarkedFolder::refuseLoose( $path, $this->owners );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( ! @is_readable( $path ) || ! @is_writable( $path ) ) {
			throw new Refused( Refused::NOT_WRITABLE );
		}
		MarkedFolder::contents( $path, $this->marker );
	}

	/**
	 * @param string             $path    Path.
	 * @param array<int, string> $folders Folders.
	 */
	private static function inAny( string $path, array $folders ): bool {
		foreach ( $folders as $folder ) {
			if ( '' !== $folder && self::within( $path, $folder ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $path is $folder or below it (directory boundary).
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 */
	public static function within( string $path, string $folder ): bool {
		$folder = self::trimmed( $folder );
		if ( '/' === $folder ) {
			return true;
		}

		return $path === $folder || str_starts_with( $path, $folder . '/' );
	}

	/**
	 * @param string $folder Folder.
	 */
	public static function trimmed( string $folder ): string {
		$trimmed = rtrim( $folder, '/' );

		return '' === $trimmed ? '/' : $trimmed;
	}
}
