<?php
/**
 * Measures the files a backup would contain, a slice at a time.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportRoots;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * The export's own pieces walk the site: ExportRoots with every option on
 * (so each part is there to be measured; the parts a backup may leave out
 * are told apart by FileTally) and FileEnumerator, which writes what it
 * lists to a work file. After each run the new lines go into the tally and
 * the cursor's list length is set back to 0, so the list holds one slice at
 * most; the visited-folders side file grows until the walk is done.
 *
 * Every slice works in files of its own, named by its lease token: it
 * copies the visited-folders file of the slice whose state was saved ($from,
 * its valid length) and lists into a list of its own. A slice that lost its
 * lease (it ran too long) therefore never writes into the files a saved
 * state names; its files are never adopted, and the next slice removes them.
 * The caller saves the cursor, the tally and the new work id together, only
 * after a run returned and only while it still holds the lease.
 */
final class FileSizes {

	/** Prefix of every work file: files-<id>.list and files-<id>.list.dirs. */
	public const PREFIX = 'files-';

	private const ID = '/^[a-f0-9]{16}\z/';

	/**
	 * @param string $workDir     Folder of the work files (in our storage, never exported).
	 * @param string $storageRoot Our storage root (never exported).
	 */
	public function __construct( private string $workDir, private string $storageRoot ) {
	}

	/**
	 * @param Site       $site     Snapshot taken when the measuring started.
	 * @param EnumCursor $cursor   Progress (listBytes is 0 afterwards).
	 * @param FileTally  $tally    Totals (receives the newly listed files).
	 * @param Deadline   $deadline Time budget.
	 * @param string     $from     Work id of the saved state ('' before the first slice).
	 * @param string     $to       Work id of this slice (its lease token).
	 * @return bool True when every root is walked.
	 * @throws ExportException When a work id is not one, or a work file cannot be written or read.
	 */
	public function run( Site $site, EnumCursor $cursor, FileTally $tally, Deadline $deadline, string $from, string $to ): bool {
		if ( 1 !== preg_match( self::ID, $to ) || ( '' !== $from && 1 !== preg_match( self::ID, $from ) ) ) {
			throw new ExportException( 'Invalid work id of the size estimate.' );
		}
		if ( ! Files::privateDir( $this->workDir ) ) {
			throw new ExportException( 'Cannot create the folder of the size estimate.' );
		}
		$this->removeExcept( $from, time() - EstimateStore::LEASE );
		$this->adopt( $from, $to, $cursor->visitedBytes );
		$roots = ExportRoots::build( $site, new ExportOptions(), $this->storageRoot );
		$list  = $this->workDir . '/' . self::PREFIX . $to . '.list';
		$done  = ( new FileEnumerator( $roots, $list, ExportRoots::territories( $site ) ) )->run(
			$cursor,
			$deadline,
			static function (): void {
				// A file the backup would skip is not counted; the backup itself names it.
			}
		);
		self::count( $list, $cursor->listBytes, $site, $roots, $tally );
		$cursor->listBytes = 0;
		Files::removeFile( $list );
		if ( $done ) {
			$this->clear();
		}

		return $done;
	}

	/** Removes every work file (a finished measuring, or a new one starting). */
	public function clear(): void {
		$this->removeExcept( '', PHP_INT_MAX );
	}

	/**
	 * Removes the files of other slices: those of slices that died or lost
	 * their lease. A file written within the lease time is kept (a slice
	 * that saved its state a moment ago may own it), so only a slice's own
	 * work and the saved work are ever in use.
	 *
	 * @param string $keep   Work id whose files stay ('' = none).
	 * @param int    $before Only files last written before this Unix time go.
	 */
	private function removeExcept( string $keep, int $before ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing folder has nothing to remove; never a warning with the path.
		foreach ( (array) @scandir( $this->workDir ) as $name ) {
			$name = (string) $name;
			$path = $this->workDir . '/' . $name;
			if ( str_starts_with( $name, self::PREFIX ) && ( '' === $keep || ! str_starts_with( $name, self::PREFIX . $keep . '.' ) ) && (int) @filemtime( $path ) < $before ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile is gone either way.
				Files::removeFile( $path );
			}
		}
	}

	/**
	 * Copies the saved visited-folders file (its valid length) into this slice's own.
	 *
	 * @param string $from   Saved work id ('' = none: this slice starts empty).
	 * @param string $to     This slice's work id.
	 * @param int    $length Valid length of the saved file.
	 * @throws ExportException When the copy fails (a missing or short file is refused when it is opened).
	 */
	private function adopt( string $from, string $to, int $length ): void {
		$source = $this->workDir . '/' . self::PREFIX . $from . '.list' . FileEnumerator::VISITED_SUFFIX;
		if ( '' === $from || 0 === $length || ! is_file( $source ) ) {
			return;
		}
		$target = $this->workDir . '/' . self::PREFIX . $to . '.list' . FileEnumerator::VISITED_SUFFIX;
		Files::privateFile( $target );
		$in  = fopen( $source, 'rb' );
		$out = fopen( $target, 'wb' );
		$ok  = false !== $in && false !== $out && false !== stream_copy_to_stream( $in, $out, $length );
		foreach ( [ $in, $out ] as $handle ) {
			if ( false !== $handle ) {
				fclose( $handle );
			}
		}
		if ( ! $ok ) {
			throw new ExportException( 'Cannot copy a work file of the size estimate.' );
		}
	}

	/**
	 * @param string                                                       $list   List file.
	 * @param int                                                          $length Its valid length.
	 * @param Site                                                         $site   Snapshot.
	 * @param array<int, array{dir: string, entry: string, filter: mixed}> $roots  Roots.
	 * @param FileTally                                                    $tally  Totals.
	 * @throws ExportException When the list cannot be read.
	 */
	private static function count( string $list, int $length, Site $site, array $roots, FileTally $tally ): void {
		if ( 0 === $length ) {
			return;
		}
		$handle = fopen( $list, 'rb' );
		if ( false === $handle ) {
			throw new ExportException( 'Cannot read the file list of the size estimate.' );
		}
		try {
			$read = 0;
			while ( $read < $length ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$read  += strlen( $line );
				$parsed = FileEnumerator::parseLine( $line );
				if ( null !== $parsed ) {
					$tally->add( $site, $roots, $parsed['entry'], $parsed['path'], $parsed['size'] );
				}
			}
		} finally {
			fclose( $handle );
		}
	}
}
