<?php
/**
 * The user's texts for a refused backups folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the reason codes of FolderRefusedException (and of a failed copy)
 * into translated sentences. No text names a path.
 */
final class FolderMessages {

	/**
	 * @param string $reason A FolderRefusedException reason.
	 * @param string $owner  For RUN_AS_OWNER: the web server's user name (StorageDir::ownerName()), '' = unknown.
	 */
	public static function of( string $reason, string $owner = '' ): string {
		if ( Refused::RUN_AS_OWNER === $reason && '' !== $owner ) {
			/* translators: %s: user name of the web server, for example www-data. */
			return sprintf( __( 'As root the plugin creates nothing in the backups folder. Run this as the web server\'s user, for example: sudo -u %s wp …', 'lw-migration' ), $owner );
		}

		return match ( $reason ) {
			Refused::INVALID           => __( 'Enter a full folder path without "." or ".." parts, backslashes or special characters.', 'lw-migration' ),
			Refused::NOT_ABSOLUTE      => __( 'Enter a full path that starts with /.', 'lw-migration' ),
			Refused::SYSTEM            => __( 'This is a system folder. Choose a folder of your own, for example in your home folder.', 'lw-migration' ),
			Refused::SITE_FOLDER       => __( "Backups cannot be kept in the site's own folders (plugins, themes, uploads, WordPress itself). Choose another folder.", 'lw-migration' ),
			Refused::CONTENT_SUBFOLDER => __( 'Inside wp-content only wp-content itself can hold the backups folder: WordPress and other plugins empty their own folders there.', 'lw-migration' ),
			Refused::OVERLAP           => __( 'This folder is part of another backups folder. Choose a separate folder.', 'lw-migration' ),
			Refused::OPEN_BASEDIR      => __( 'PHP may not use this folder on this server (open_basedir). Choose a folder it may use, or ask your host.', 'lw-migration' ),
			Refused::NO_PARENT         => __( 'The folder you entered, or the folder above it, does not exist. Create it first, or choose another folder.', 'lw-migration' ),
			Refused::NOT_WRITABLE      => __( 'The site cannot write into this folder. Check its permissions.', 'lw-migration' ),
			Refused::WORLD_WRITABLE    => __( 'Other users of the server may change this folder. Choose a folder only your site may change.', 'lw-migration' ),
			Refused::LINK              => __( 'This folder is a link to another place. Enter the folder the link points to.', 'lw-migration' ),
			Refused::NOT_FOLDER        => __( 'A file has this name. Choose a folder.', 'lw-migration' ),
			Refused::NOT_EMPTY         => __( 'This folder already holds other files. Choose an empty or a new folder.', 'lw-migration' ),
			Refused::FOREIGN           => __( 'This folder holds the backups of another site or of an earlier install. Choose an empty or a new folder.', 'lw-migration' ),
			Refused::MISSING           => __( 'The backups folder is missing. If it is on a disk that is not attached, attach it, or move the backups back to the default folder.', 'lw-migration' ),
			Refused::CHANGED           => __( 'The backups folder was replaced by a link or moved. Choose the folder again in Settings.', 'lw-migration' ),
			Refused::SAME              => __( 'The backups are already kept in this folder.', 'lw-migration' ),
			Refused::PENDING           => __( 'Finish or undo the unfinished move first.', 'lw-migration' ),
			Refused::UNSUPPORTED       => __( 'A folder of your own is not available on this server; the backups stay in the default folder.', 'lw-migration' ),
			Refused::LOCKED            => __( 'The backups folder cannot be changed on this site.', 'lw-migration' ),
			Refused::RUN_AS_OWNER      => __( 'As root the plugin creates nothing in the backups folder. Run this as the web server\'s user, for example: sudo -u <web server user> wp …', 'lw-migration' ),
			Refused::NOTHING_PENDING   => __( 'No move is waiting to be finished.', 'lw-migration' ),
			Refused::UNPROTECTED       => __( 'The backups folder lost its protection: a protection file is missing, or a folder above it now serves web pages. Choose the folder again in Settings.', 'lw-migration' ),
			default                    => __( 'This folder cannot be used.', 'lw-migration' ),
		};
	}

	/**
	 * Why a backup could not be moved (FileMover codes).
	 *
	 * @param int    $code FileMover failure code.
	 * @param string $name Backup file name only ('' = none).
	 */
	public static function copyFailed( int $code, string $name = '' ): string {
		switch ( $code ) {
			case FileMover::MISSING:
				return '' === $name
					? __( 'A backup disappeared while it was moved.', 'lw-migration' )
					/* translators: %s: backup file name. */
					: sprintf( __( 'The backup %s disappeared while it was moved.', 'lw-migration' ), $name );
			case FileMover::FULL:
				return __( 'The new folder is full. Nothing was removed; free up space and start again.', 'lw-migration' );
			case FileMover::MISMATCH:
				return '' === $name
					? __( 'A copy did not match the original. Nothing was removed; start again.', 'lw-migration' )
					/* translators: %s: backup file name. */
					: sprintf( __( 'The copy of %s did not match the original. Nothing was removed; start again.', 'lw-migration' ), $name );
			case FileMover::TOO_BIG:
				return __( 'A backup is too large for the file system of the new folder (a FAT disk holds at most 4 GB in one file). Nothing was removed; choose another folder.', 'lw-migration' );
			default:
				return __( "A backup could not be copied. Nothing was removed; check the folder's permissions and start again.", 'lw-migration' );
		}
	}

	/**
	 * Why a finished export could not be saved into the backups folder
	 * (FileMover codes); every text says the backup can be started again.
	 *
	 * @param int $code FileMover failure code.
	 */
	public static function storeFailed( int $code ): string {
		switch ( $code ) {
			case FileMover::MISSING:
				return __( 'The finished backup disappeared before it was saved. Start the backup again.', 'lw-migration' );
			case FileMover::FULL:
				return __( 'The backups folder is full. Free up space and start the backup again.', 'lw-migration' );
			case FileMover::MISMATCH:
				return __( 'The saved copy of the backup did not match the original. Start the backup again.', 'lw-migration' );
			case FileMover::TOO_BIG:
				return __( 'The backup is too large for the file system of the backups folder (a FAT disk holds at most 4 GB in one file). Choose another folder and start the backup again.', 'lw-migration' );
			default:
				return __( 'Could not move the finished backup into the backups folder.', 'lw-migration' );
		}
	}
}
