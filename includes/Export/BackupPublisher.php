<?php
/**
 * Puts a finished archive into the backups folder under its final name.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\ExclusiveFile;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FolderIdentity;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\HardLink;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Inside the backups folder a copied temp (FileMover) gets its final name
 * without ever replacing a file, safe to repeat after a request died in it.
 * publish() always tries a hard link first (then the temp is unlinked). Where
 * the folder's file system has no hard links it answers RESERVE: the caller
 * saves the name it is about to reserve (cursor.reserving) before
 * publishReserved() creates the name in 'x' mode (never replacing or
 * following anything) and renames the temp onto it. A replay of that call
 * accepts an empty regular file under the saved name as its own reservation,
 * so a request that died in between leaves nothing behind for good (and the
 * listing never shows an empty file). Link and rename keep the temp's inode.
 * A valid backup name therefore never holds a partial file, and the listing
 * skips the dotted temp.
 */
final class BackupPublisher {

	/** The temp holds the final name now. */
	public const DONE = 'done';

	/** Another file holds the name: pick a new one. */
	public const TAKEN = 'taken';

	/** No hard links here: save the name as reserving, then call publishReserved(). */
	public const RESERVE = 'reserve';

	/**
	 * Hard link: fn( string $from, string $to ): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $link;

	/**
	 * @param string        $dir  Backups folder.
	 * @param \Closure|null $link fn( string $from, string $to ): bool; default link() (tests: a file system without links).
	 */
	public function __construct( private string $dir, ?\Closure $link = null ) {
		$this->link = $link ?? static fn( string $from, string $to ): bool => HardLink::make( $from, $to );
	}

	public function isStaged( string $temp ): bool {
		return self::isRegular( $this->path( $temp ) );
	}

	public function isPublished( string $name ): bool {
		return self::isRegular( $this->path( $name ) );
	}

	/**
	 * @param string $temp Staged temp name.
	 * @param string $name Final backup name.
	 * @return string DONE, TAKEN or RESERVE.
	 */
	public function publish( string $temp, string $name ): string {
		$from = $this->path( $temp );
		$to   = $this->path( $name );
		clearstatcache( true );
		if ( ! self::sameFile( $from, $to ) ) {
			if ( self::taken( $to ) ) {
				return self::TAKEN;
			}
			if ( ! ( $this->link )( $from, $to ) ) {
				clearstatcache( true );
				if ( ! self::sameFile( $from, $to ) ) {
					return self::taken( $to ) ? self::TAKEN : self::RESERVE;
				}
			}
		}
		Files::removeFile( $from );

		return self::DONE;
	}

	/**
	 * Without hard links: the name saved as reserving is created in 'x' mode
	 * (or is our empty reservation from a request that died), then the temp is
	 * renamed onto it — one folder, one file system, only our own empty file
	 * is replaced.
	 *
	 * @param string $temp Staged temp name.
	 * @param string $name The name saved as reserving.
	 * @return string DONE or TAKEN.
	 * @throws ExportException When the temp cannot get its final name.
	 */
	public function publishReserved( string $temp, string $name ): string {
		$from = $this->path( $temp );
		$to   = $this->path( $name );
		clearstatcache( true );
		if ( self::sameFile( $from, $to ) ) {
			Files::removeFile( $from );
			return self::DONE;
		}
		$reserved = self::isEmpty( $to ) ? FolderIdentity::entry( $to ) : $this->reserve( $to );
		if ( null === $reserved ) {
			return self::TAKEN;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- one folder, one file system: rename() replaces only our empty reservation; a failure is reported without the path.
		if ( FolderIdentity::entry( $to ) !== $reserved || ! @rename( $from, $to ) ) {
			throw self::failed();
		}

		return self::DONE;
	}

	/**
	 * @param string $to Final name.
	 * @return string|null The reservation's entry identity, or null when the name is taken.
	 * @throws ExportException When the name cannot be created.
	 */
	private function reserve( string $to ): ?string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' never replaces or follows a name; a failure is decided below.
		$handle = @fopen( $to, 'xb' );
		if ( false === $handle ) {
			if ( self::taken( $to ) ) {
				return null;
			}
			throw self::failed();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return FolderIdentity::entry( $to ); // After closing: some file systems number a file only then.
	}

	private static function failed(): ExportException {
		return new ExportException( __( 'Could not move the finished backup into the backups folder.', 'lw-migration' ) );
	}

	/**
	 * Deletes what a failed or cancelled store left: its temp, and an empty
	 * reservation under the name it saved as reserving (a name holding bytes
	 * is a published backup and stays; anything else is refused).
	 *
	 * @param string $temp      Temp name.
	 * @param string $reserving The store's cursor.reserving ('' = none).
	 * @return void
	 */
	public function discard( string $temp, string $reserving = '' ): void {
		$path = $this->path( $temp );
		if ( TempName::is( $temp ) && self::taken( $path ) ) {
			Files::removeFile( $path );
		}
		$reserved = $this->path( $reserving );
		if ( BackupName::isValid( $reserving ) && self::isEmpty( $reserved ) ) {
			ExclusiveFile::removeOwn( $reserved, (string) FolderIdentity::entry( $reserved ) );
		}
	}

	private function path( string $name ): string {
		return rtrim( $this->dir, '/' ) . '/' . $name;
	}

	private static function taken( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		return @file_exists( $path ) || @is_link( $path );
	}

	private static function isRegular( string $path ): bool {
		return null !== FileIdentity::of( $path );
	}

	private static function isEmpty( string $path ): bool {
		return 0 === ( FileIdentity::of( $path )['size'] ?? -1 );
	}

	/**
	 * Both names are regular files with one identity tuple (a link made before
	 * the request died). The whole tuple: FAT gives every empty file one inode.
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @phpstan-impure Reads the file system, which link() changes between calls.
	 */
	private static function sameFile( string $a, string $b ): bool {
		$x = FileIdentity::of( $a );

		return null !== $x && FileIdentity::of( $b ) === $x;
	}
}
