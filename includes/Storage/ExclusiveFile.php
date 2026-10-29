<?php
/**
 * A file created under a name nobody else used.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;
use LightweightPlugins\Migration\Support\HardLink;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * The content goes into a hidden temp ('x' mode: a new name, never a link),
 * which is then hard-linked to the final name: link() never replaces an
 * existing name and never follows one, and the final name appears complete or
 * not at all. Where the file system has no hard links, the final name itself
 * is created in 'x' mode. Afterwards the folder must still be the folder it
 * was; otherwise only the file this call made is removed. WordPress-free.
 */
final class ExclusiveFile {

	/**
	 * @param string $dir      Folder (canonical).
	 * @param string $name     File name.
	 * @param string $contents Content.
	 * @param string $dirId    The folder's identity (FolderIdentity::of()) from before.
	 * @return string|null The created file's identity, or null when the name is taken.
	 * @throws Refused NOT_WRITABLE, or CHANGED when the folder was replaced meanwhile.
	 */
	public static function create( string $dir, string $name, string $contents, string $dirId ): ?string {
		clearstatcache( true ); // The realpath cache too: another process may have swapped the folder.
		$final = $dir . '/' . $name;
		if ( self::taken( $final ) ) {
			return null;
		}
		$temp   = $dir . '/' . TempName::name();
		$tempId = self::write( $temp, $contents );
		if ( null === $tempId ) {
			throw new Refused( Refused::NOT_WRITABLE );
		}
		$linked = HardLink::make( $temp, $final ); // A failure (name taken, no hard links) is decided below.
		self::removeTemp( $temp );
		$id = $linked ? $tempId : ( self::taken( $final ) ? null : self::write( $final, $contents ) );
		if ( null === $id ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		if ( @realpath( $dir ) !== $dir || FolderIdentity::of( $dir ) !== $dirId ) {
			self::removeOwn( $final, $id );
			throw new Refused( Refused::CHANGED );
		}

		return $id;
	}

	/**
	 * Removes $path only while it is still the entry with identity $id.
	 *
	 * @param string $path File.
	 * @param string $id   FolderIdentity::entry() of the file this code made.
	 */
	public static function removeOwn( string $path, string $id ): void {
		if ( FolderIdentity::entry( $path ) === $id ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure leaves the file; never a warning with the path.
			@unlink( $path );
		}
	}

	/**
	 * Removes our temp by its name: 16 random hex nobody else can have used.
	 *
	 * @param string $temp Temp from TempName.
	 */
	private static function removeTemp( string $temp ): void {
		if ( TempName::is( basename( $temp ) ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure leaves a hidden temp the cleanup sweeps; never a warning with the path.
			@unlink( $temp );
		}
	}

	/**
	 * @param string $path File.
	 */
	private static function taken( string $path ): bool {
		clearstatcache( true, $path );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		return @file_exists( $path ) || @is_link( $path );
	}

	/**
	 * @param string $path     New file.
	 * @param string $contents Content.
	 * @return string|null Its identity, or null when the name is taken.
	 * @throws Refused NOT_WRITABLE.
	 */
	private static function write( string $path, string $contents ): ?string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' mode; a failure becomes a reason code.
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			if ( self::taken( $path ) ) {
				return null;
			}
			throw new Refused( Refused::NOT_WRITABLE );
		}
		$written = '' === $contents ? 0 : fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$flushed = fflush( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$id = (string) FolderIdentity::entry( $path ); // After closing: some file systems (FAT) number a file only then.
		if ( '' === $id || strlen( $contents ) !== $written || ! $flushed ) {
			self::removeOwn( $path, $id );
			self::removeTemp( $path );
			throw new Refused( Refused::NOT_WRITABLE );
		}

		return $id;
	}
}
