<?php
/**
 * Import step: restores the files of the archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\Entry;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Folder;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\PathGuard;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\TargetFile;
use LightweightPlugins\Migration\Archive\UnsafePathException;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\ExtractBudget;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ImportTemps;
use LightweightPlugins\Migration\Import\Ownership;
use LightweightPlugins\Migration\Import\OwnershipNotice;
use LightweightPlugins\Migration\Import\PasswordTrial;
use LightweightPlugins\Migration\Import\SkipMessages;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the entries from a stored header offset (and checks the stored name
 * on resume). Each file is written to a temporary file next to its target and
 * renamed over it when complete; must-use plugins are staged; skipped entries
 * are summed up per reason; a damaged entry stops the import and leaves the
 * live file as it was. A resumed entry whose temporary file is gone (the request
 * died after the rename, or before its cursor was saved) starts over; one whose
 * temporary file was replaced stops the import. Every folder written to is
 * journaled first, and our orphaned temporary files there are swept at the end.
 * Every byte written counts against the import's ExtractBudget (the size of a
 * replaced file comes back after the rename); past its limit the import stops
 * like on a damaged entry.
 *
 * Cursor: offset (next header), name (entry in progress), extract
 * (ExtractCursor with its temporary file) and target (the file it becomes,
 * so a cancelled import can discard the temporary file), skips (reason => count).
 * State: filesPlaced once a file was renamed into place on the site.
 */
final class ContentStep implements StepInterface {

	/** State flag: at least one file of the archive was put in place on the site. */
	private const FILES_PLACED = 'filesPlaced';

	/**
	 * Owner of each real base path (null: nothing to change).
	 *
	 * @var array<string, Ownership|null>
	 */
	private array $owners = [];

	/**
	 * The job's work folder (set per run).
	 *
	 * @var string
	 */
	private string $workDir = '';

	/**
	 * Base key => folder of the current rules.
	 *
	 * @var array<string, string>
	 */
	private array $bases = [];

	/**
	 * Last journal line written in this run (consecutive duplicates are skipped).
	 *
	 * @var string
	 */
	private string $journaled = '';

	/**
	 * Free bytes of a folder (null: unknown).
	 *
	 * @var \Closure(string): ?int
	 */
	private \Closure $freeSpace;

	/**
	 * The import's write limit (set per run).
	 *
	 * @var WriteBudget
	 */
	private WriteBudget $budget;

	/**
	 * @param JobStore      $store     Job store.
	 * @param \Closure      $rules     fn( Job $job, string $stagingDir ): ContentRules.
	 * @param \Closure|null $freeSpace Free bytes of a folder; DiskSpace::free() by default.
	 */
	public function __construct( private JobStore $store, private \Closure $rules, ?\Closure $freeSpace = null ) {
		$this->freeSpace = $freeSpace ?? static fn( string $dir ): ?int => DiskSpace::free( $dir );
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$codec = ImportState::codec( $job );
		if ( null === $codec ) {
			return PasswordTrial::ask( $job, 'encrypted' );
		}
		$work         = WorkDir::path( $this->store, $job );
		$this->budget = ExtractBudget::load( $job, [ ImportState::site( $job )->contentDir, $work ], $this->freeSpace );
		if ( ! PasswordTrial::passes( $job, $codec, $work, $this->budget ) ) {
			return PasswordTrial::ask( $job, 'wrong' );
		}
		$rules         = ( $this->rules )( $job, $work . '/' . ImportFiles::STAGING );
		$this->workDir = $work;
		$this->bases   = $rules->bases();
		$reader        = new Reader( ImportState::archive( $job ), $codec );
		$cursor        = $job->cursor;
		try {
			$end      = $reader->dataEnd();
			$finished = $this->walk( $job, $reader, $rules, $cursor, $end, $deadline );
		} finally {
			$reader->close();
			$job->cursor = $cursor;
			ExtractBudget::save( $job, $this->budget );
		}
		if ( ! $finished ) {
			return StepResult::more( (int) ( 100 * (int) ( $cursor['offset'] ?? 0 ) / max( 1, $end ) ), __( 'Restoring files...', 'lw-migration' ) );
		}
		ImportTemps::sweep( $work, $this->bases, $job->createdAt );
		$this->reportSkips( $job, (array) ( $cursor['skips'] ?? [] ) );

		return StepResult::done( __( 'Files restored.', 'lw-migration' ) );
	}

	/**
	 * @param Job                  $job      Job.
	 * @param Reader               $reader   Archive.
	 * @param ContentRules         $rules    Routes.
	 * @param array<string, mixed> $cursor   Step cursor.
	 * @param int                  $end      End of the entry data.
	 * @param Deadline             $deadline Time budget.
	 * @return bool True when every entry is done.
	 * @throws ImportException When the entry at the saved offset is not the one in progress.
	 */
	private function walk( Job $job, Reader $reader, ContentRules $rules, array &$cursor, int $end, Deadline $deadline ): bool {
		$offset = (int) ( $cursor['offset'] ?? 0 );
		while ( $offset < $end ) {
			$entry = $reader->entryAt( $offset );
			if ( isset( $cursor['name'] ) && $cursor['name'] !== $entry->name ) {
				self::discard( $cursor );
				throw new ImportException( __( 'The archive changed while it was being restored.', 'lw-migration' ) );
			}
			$cursor['name'] = $entry->name;
			if ( Format::DATABASE === $entry->name ) {
				$job->state['databaseEntry'] = $offset;
			}
			if ( ! $this->entry( $job, $reader, $entry, $rules->route( $entry->name ), $cursor, $deadline ) ) {
				return false;
			}
			$offset           = $entry->dataOffset() + $entry->size;
			$cursor['offset'] = $offset;
			unset( $cursor['name'], $cursor['extract'], $cursor['target'] );
			if ( $offset < $end && $deadline->reached() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param Job                  $job      Job.
	 * @param Reader               $reader   Archive.
	 * @param Entry                $entry    Entry.
	 * @param array<string, mixed> $route    ContentRules::route() result.
	 * @param array<string, mixed> $cursor   Step cursor.
	 * @param Deadline             $deadline Time budget.
	 * @return bool True when the entry is finished (written or skipped).
	 * @throws CorruptedEntryException When the entry is damaged (the live file is kept).
	 * @throws ImportException When the file cannot be written, its extraction cannot be resumed or the write limit is reached.
	 */
	private function entry( Job $job, Reader $reader, Entry $entry, array $route, array &$cursor, Deadline $deadline ): bool {
		if ( ContentRules::SKIP === $route['action'] ) {
			$reason = (string) ( $route['reason'] ?? '' );
			if ( '' !== $reason ) {
				$cursor['skips'][ $reason ] = (int) ( $cursor['skips'][ $reason ] ?? 0 ) + 1;
			}
			return true;
		}
		$base     = (string) $route['base'];
		$progress = self::progress( $cursor );
		$resumed  = null !== $progress->tempPath;
		try {
			Folder::ensure( $base );
			$target = PathGuard::resolve( $base, (string) $route['rel'] );
			$this->journal( $base, (string) $route['rel'] );
			$cursor['target'] = $target;
			$done             = $reader->extractTo( $entry, $target, $progress, $deadline, $this->budget );
		} catch ( UnsafePathException $e ) {
			self::discard( $cursor );
			if ( $resumed ) {
				/* translators: %s: path inside the archive */
				throw new ImportException( sprintf( __( 'The partly restored file %s was changed by something else; the import stopped.', 'lw-migration' ), $entry->name ), 0, $e );
			}
			/* translators: %s: path inside the archive */
			$job->addWarning( sprintf( __( 'Skipped an unsafe path in the archive: %s', 'lw-migration' ), $entry->name ) );
			return true;
		} catch ( WriteLimitException $e ) {
			unset( $cursor['extract'], $cursor['target'] ); // extractTo() already deleted its temporary file.
			throw new ImportException( ExtractBudget::stopMessage( $this->budget ), 0, $e );
		} catch ( CorruptedEntryException $e ) {
			// extractTo() already deleted its temporary file; the live file was never touched.
			unset( $cursor['extract'], $cursor['target'] );
			/* translators: %s: path inside the archive */
			throw new CorruptedEntryException( sprintf( __( 'The archive is damaged: %s did not pass its check. The import stopped; this file on the site was left as it was.', 'lw-migration' ), $entry->name ), 0, $e );
		} catch ( ArchiveException $e ) {
			unset( $cursor['extract'], $cursor['target'] );
			/* translators: %s: path inside the archive */
			throw new ImportException( sprintf( __( 'Could not restore %s: the file could not be written or its restore could not be resumed. Check the free disk space and the folder permissions.', 'lw-migration' ), $entry->name ), 0, $e );
		}
		if ( ! $done ) {
			$cursor['extract'] = $progress->toArray();
			return false;
		}
		if ( ContentRules::EXTRACT === $route['action'] ) {
			$job->state[ self::FILES_PLACED ] = true; // In state: it outlives this step's cursor.
			$this->own( $job, $base, $target );
		}

		return true;
	}

	/**
	 * Whether a file of the archive reached the site (staged must-use plugins do
	 * not count). Saved with the job like the cursor: a request that dies right
	 * after its first rename may not have recorded it.
	 *
	 * @param Job $job Import job.
	 */
	public static function filesPlaced( Job $job ): bool {
		return ! empty( $job->state[ self::FILES_PLACED ] );
	}

	/**
	 * The saved progress of the entry; a fresh start when its temporary file is gone
	 * (the entry was renamed into place, or the file was lost, before the cursor was saved).
	 *
	 * @param array<string, mixed> $cursor Step cursor.
	 */
	private static function progress( array $cursor ): ExtractCursor {
		$progress = ExtractCursor::fromArray( (array) ( $cursor['extract'] ?? [] ) );
		$temp     = $progress->tempPath;
		if ( null !== $temp ) {
			clearstatcache( true, $temp );
			if ( ! file_exists( $temp ) && ! is_link( $temp ) ) {
				return new ExtractCursor();
			}
		}

		return $progress;
	}

	/**
	 * Notes the folder of a target in the work-folder journal before a temporary file can appear there.
	 *
	 * @param string $base Route base folder.
	 * @param string $rel  Path below it.
	 * @return void
	 * @throws ArchiveException When the journal cannot be written.
	 */
	private function journal( string $base, string $rel ): void {
		$key = array_search( $base, $this->bases, true );
		if ( false === $key ) {
			throw new ArchiveException( 'The extraction base is not one of the rules.' );
		}
		$dir  = dirname( $rel );
		$dir  = '.' === $dir ? '' : $dir;
		$line = $key . '|' . $dir;
		if ( $line !== $this->journaled ) {
			ImportTemps::record( $this->workDir, (string) $key, $dir );
			$this->journaled = $line;
		}
	}

	/**
	 * Deletes the temporary file of the entry in progress (only one of the saved target's).
	 *
	 * @param array<string, mixed> $cursor Step cursor.
	 * @return void
	 */
	private static function discard( array &$cursor ): void {
		if ( isset( $cursor['extract'], $cursor['target'] ) && is_array( $cursor['extract'] ) ) {
			TargetFile::discard( ExtractCursor::fromArray( $cursor['extract'] ), (string) $cursor['target'] );
		}
		unset( $cursor['extract'], $cursor['target'] );
	}

	private function own( Job $job, string $base, string $path ): void {
		$real = realpath( $base );
		if ( false === $real ) {
			return;
		}
		if ( ! array_key_exists( $real, $this->owners ) ) {
			$this->owners[ $real ] = Ownership::forBase( $real );
		}
		$owner = $this->owners[ $real ];
		if ( null !== $owner && ! $owner->apply( $real, $path ) ) {
			OwnershipNotice::once( $job );
		}
	}

	/**
	 * One summary warning per skip reason.
	 *
	 * @param Job                $job   Job.
	 * @param array<string, int> $skips Reason => count.
	 * @return void
	 */
	private function reportSkips( Job $job, array $skips ): void {
		foreach ( SkipMessages::all( $skips ) as $line ) {
			$job->addWarning( $line );
		}
	}
}
