<?php
/**
 * Import step: extracts database.sql into the job folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use LightweightPlugins\Migration\Import\ExtractBudget;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\PasswordTrial;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the header offset the Content step recorded; no offset means no database.
 * The file goes to the job's work folder (temporary file + rename, like every
 * extracted entry); the cursor is an ExtractCursor. Its bytes count against
 * the import's ExtractBudget, after the files.
 */
final class DatabaseFileStep implements StepInterface {

	/**
	 * Free bytes of a folder (null: unknown).
	 *
	 * @var \Closure(string): ?int
	 */
	private \Closure $freeSpace;

	/**
	 * @param JobStore      $store     Job store.
	 * @param \Closure|null $freeSpace Free bytes of a folder; DiskSpace::free() by default.
	 */
	public function __construct( private JobStore $store, ?\Closure $freeSpace = null ) {
		$this->freeSpace = $freeSpace ?? static fn( string $dir ): ?int => DiskSpace::free( $dir );
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! isset( $job->state['databaseEntry'] ) ) {
			$job->state['hasDatabase'] = false;
			return StepResult::done( __( 'The archive has no database.', 'lw-migration' ) );
		}
		$codec = ImportState::codec( $job );
		if ( null === $codec ) {
			return PasswordTrial::ask( $job, 'encrypted' );
		}
		$work   = WorkDir::path( $this->store, $job );
		$budget = ExtractBudget::load( $job, [ ImportState::site( $job )->contentDir, $work ], $this->freeSpace );
		if ( ! PasswordTrial::passes( $job, $codec, $work, $budget ) ) {
			return PasswordTrial::ask( $job, 'wrong' );
		}
		$cursor = ExtractCursor::fromArray( $job->cursor );
		if ( null !== $cursor->tempPath ) {
			clearstatcache( true, $cursor->tempPath );
			if ( ! file_exists( $cursor->tempPath ) && ! is_link( $cursor->tempPath ) ) {
				// Renamed into place (or lost) before the cursor was saved: start the file over.
				$cursor = new ExtractCursor();
			}
		}
		$reader = new Reader( ImportState::archive( $job ), $codec );
		try {
			$entry = $reader->entryAt( (int) $job->state['databaseEntry'] );
			if ( Format::DATABASE !== $entry->name ) {
				throw new ImportException( __( 'The archive changed while it was being restored.', 'lw-migration' ) );
			}
			$done = $reader->extractTo( $entry, $work . '/' . ImportFiles::DATABASE, $cursor, $deadline, $budget );
		} catch ( WriteLimitException $e ) {
			throw new ImportException( ExtractBudget::stopMessage( $budget ), 0, $e ); // extractTo() already deleted its temporary file.
		} catch ( CorruptedEntryException $e ) {
			// extractTo() already deleted its temporary file.
			throw new CorruptedEntryException( __( 'The archive is damaged: its database did not pass its check. The import stopped before the database was changed.', 'lw-migration' ), 0, $e );
		} catch ( ArchiveException $e ) {
			throw new ImportException( __( 'Could not extract the database from the archive: the file could not be written or its extraction could not be resumed. Check the free disk space.', 'lw-migration' ), 0, $e );
		} finally {
			$reader->close();
			$job->cursor = $cursor->toArray();
			ExtractBudget::save( $job, $budget );
		}
		if ( ! $done ) {
			return StepResult::more( (int) ( 100 * $cursor->consumed / max( 1, $entry->size ) ), __( 'Extracting the database...', 'lw-migration' ) );
		}
		$job->state['hasDatabase'] = true;

		return StepResult::done( __( 'Database file extracted.', 'lw-migration' ) );
	}
}
