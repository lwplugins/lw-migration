<?php
/**
 * Temporary extraction files an import may leave in the site folders.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * A request can die after a temporary file was created but before the cursor
 * naming it was saved; nothing points to that file any more. So every folder
 * an entry is extracted into is written to a journal in the work folder first
 * (base key and folder below the base), and sweep() later deletes, in those
 * folders only, regular files with our temporary name that are not older than
 * the job by their inode change time (set when the file is created and on every
touch, so the entry time an extraction stamps on its file does not hide it).
Pure: no WordPress calls.
 */
final class ImportTemps {

	/** Separates the base key from the folder in a journal line. */
	private const SEPARATOR = '|';

	/**
	 * @param string $workDir The job's work folder.
	 * @param string $baseKey ContentRules base key (uploads, plugins…).
	 * @param string $relDir  Folder below the base ('' for the base itself).
	 * @return void
	 * @throws ArchiveException When the journal cannot be written.
	 */
	public static function record( string $workDir, string $baseKey, string $relDir ): void {
		$journal = self::journal( $workDir );
		Files::privateFile( $journal );
		$handle = is_file( $journal ) && is_writable( $journal ) ? fopen( $journal, 'ab' ) : false;
		if ( false === $handle ) {
			throw new ArchiveException( 'Cannot write the extraction journal.' );
		}
		$line    = $baseKey . self::SEPARATOR . $relDir . "\n";
		$written = fwrite( $handle, $line );
		fclose( $handle );
		if ( strlen( $line ) !== $written ) {
			throw new ArchiveException( 'Cannot write the extraction journal.' );
		}
	}

	/**
	 * Deletes our temporary files in every journaled folder.
	 *
	 * @param string                $workDir The job's work folder.
	 * @param array<string, string> $bases   Base key => folder (ContentRules::bases()).
	 * @param int                   $since   Job start (Unix time); files changed before it are someone else's.
	 * @return int Number of files deleted.
	 */
	public static function sweep( string $workDir, array $bases, int $since ): int {
		$journal = self::journal( $workDir );
		$lines   = is_file( $journal ) && ! is_link( $journal ) ? file( $journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		if ( false === $lines ) {
			return 0;
		}
		$deleted = 0;
		foreach ( array_unique( $lines ) as $line ) {
			$dir = self::folder( (string) $line, $bases );
			if ( null !== $dir ) {
				$deleted += self::sweepFolder( $dir, $since );
			}
		}

		return $deleted;
	}

	private static function journal( string $workDir ): string {
		return rtrim( $workDir, '/' ) . '/' . ImportFiles::EXTRACT_DIRS;
	}

	/**
	 * @param string                $line  Journal line.
	 * @param array<string, string> $bases Base key => folder.
	 * @return string|null The folder, when it is a real folder inside its base.
	 */
	private static function folder( string $line, array $bases ): ?string {
		$parts = explode( self::SEPARATOR, $line, 2 );
		if ( 2 !== count( $parts ) || '' === (string) ( $bases[ $parts[0] ] ?? '' ) ) {
			return null;
		}
		if ( '' !== $parts[1] && ! PathGuard::isSafeEntryName( $parts[1] ) ) {
			return null;
		}
		$base = realpath( $bases[ $parts[0] ] );
		$dir  = rtrim( $bases[ $parts[0] ], '/' ) . ( '' === $parts[1] ? '' : '/' . $parts[1] );
		$real = is_link( $dir ) ? false : realpath( $dir );
		if ( false === $base || false === $real || ! is_dir( $real ) ) {
			return null;
		}

		return $real === $base || str_starts_with( $real, $base . '/' ) ? $real : null;
	}

	private static function sweepFolder( string $dir, int $since ): int {
		$names = is_readable( $dir ) ? scandir( $dir ) : false;
		if ( false === $names ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $names as $name ) {
			if ( ! TempName::is( $name ) ) {
				continue;
			}
			$path = $dir . '/' . $name;
			clearstatcache( true, $path );
			$stat = is_link( $path ) || ! is_file( $path ) ? false : lstat( $path );
			if ( false !== $stat && $stat['ctime'] >= $since && is_writable( $dir ) && unlink( $path ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}
