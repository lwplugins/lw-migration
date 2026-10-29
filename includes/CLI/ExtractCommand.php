<?php
/**
 * `wp lw-migration extract`.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Extractor;
use LightweightPlugins\Migration\Archive\Folder;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Import\ExtractBudget;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\WrongPasswordException;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts a backup into a folder.
 */
final class ExtractCommand {

	/**
	 * Extracts every file of a backup into a folder (nothing is restored).
	 * Files that would land outside the folder are skipped with a warning, and
	 * so are files that already exist there unless you add --force. The
	 * extraction stops before it fills the disk: it keeps 256 MiB or 5 % of the
	 * free space free, whichever is more; a file it replaces counts only with
	 * what it adds once it is in place, but must fit beside the old one while it
	 * is written (when the free space cannot be read, it adds at most 50 GiB;
	 * filter `lw_migration_max_extract_bytes`).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup name (see `wp lw-migration list`) or path to a .wpress file.
	 *
	 * [--dir=<dir>]
	 * : Target folder. Default: the backup name without .wpress, in the current folder.
	 *
	 * [--allow-web-root]
	 * : Allow a target inside the WordPress folder, where the extracted files
	 * (and the database dump) could be reachable from the web.
	 *
	 * [--force]
	 * : Overwrite files that already exist in the folder.
	 *
	 * [--password-file=<path>]
	 * : Read the password of an encrypted backup from this file (the safest form for scripts).
	 *
	 * [--password[=<password>]]
	 * : Password of an encrypted backup. Without a value you are asked for it on a terminal, or it is read as one line from standard input; a value on the command line stays in the process list and the shell history. Not together with --password-file. Without either you are asked only when the backup is encrypted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-migration extract site-20261007-120000-abcd.wpress --dir=/tmp/backup
	 *
	 * @param array<int, string>   $args  Backup.
	 * @param array<string, mixed> $assoc Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$arg  = (string) $args[0];
		$path = Archives::resolve( $arg, Services::get()->backups() );
		if ( null === $path ) {
			/* translators: %s: backup name or path */
			Console::error( sprintf( __( 'No backup or .wpress file found: %s', 'lw-migration' ), $arg ) );
			return;
		}
		$target   = isset( $assoc['dir'] ) ? (string) $assoc['dir'] : pathinfo( $path, PATHINFO_FILENAME );
		$resolved = ExtractTarget::resolve( $target, (string) getcwd() );
		if ( empty( $assoc['allow-web-root'] ) && ExtractTarget::isInside( $resolved, [ ABSPATH, WP_CONTENT_DIR ] ) ) {
			Console::error( __( 'Refusing to extract inside the WordPress folder, where the files (and the database dump) could be reachable from the web. Choose --dir outside the site, or add --allow-web-root.', 'lw-migration' ) );
			return;
		}
		$shown   = [
			$path     => $arg,
			$resolved => $target,
		];
		$skipped = 0;
		$budget  = WriteBudget::unlimited();
		$warn    = static function ( string $message ) use ( $shown, &$skipped ): void {
			++$skipped;
			Console::warning( Archives::redact( $message, $shown ) );
		};
		try {
			$package = PackageCodec::read( $path );
			$secret  = $package->isEncrypted() ? Prompt::password( $assoc, false, true ) : null;
			$codec   = PackageCodec::forPackage( $package, $secret );
			Folder::ensure( $resolved );
			$budget = ExtractBudget::forFreeSpace( DiskSpace::free( $resolved ) );
			$count  = Extractor::extractAll( $path, $codec, $resolved, $budget, $warn, ! empty( $assoc['force'] ) );
		} catch ( WrongPasswordException $e ) {
			Console::error( __( 'The password is wrong.', 'lw-migration' ) );
			return;
		} catch ( WriteLimitException $e ) {
			/* translators: %s: size the extraction may write, e.g. "12 GB" */
			Console::error( sprintf( __( 'Stopped: the backup holds more data than the %s the free disk space allows here. Free up disk space or choose another --dir.', 'lw-migration' ), (string) size_format( (int) $budget->limit() ) ) );
			return;
		} catch ( ArchiveException $e ) {
			Console::error( Archives::redact( $e->getMessage(), $shown ) );
			return;
		}
		Console::success( self::summary( $count, $skipped, $target ) );
	}

	private static function summary( int $count, int $skipped, string $target ): string {
		if ( $skipped > 0 ) {
			/* translators: 1: number of files, 2: folder, 3: number of skipped files */
			return sprintf( __( 'Extracted %1$d files into %2$s, skipped %3$d.', 'lw-migration' ), $count, $target, $skipped );
		}

		/* translators: 1: number of files, 2: folder */
		return sprintf( __( 'Extracted %1$d files into %2$s.', 'lw-migration' ), $count, $target );
	}
}
