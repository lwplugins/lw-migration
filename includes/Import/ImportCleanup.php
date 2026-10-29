<?php
/**
 * Removes what an import leaves behind.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\ExtractCursor;
use LightweightPlugins\Migration\Archive\TargetFile;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Database\RunMarker;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\Steps\ContentStep;
use LightweightPlugins\Migration\Import\Steps\DatabaseStep;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\PostImportStep;
use LightweightPlugins\Migration\Import\Steps\SafetyBackupStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Work files after any import; temporary tables after a failed or cancelled
 * one, but only while the swap cannot have happened. Once the swap marker is
 * written (or the swap is recorded) nothing is dropped: the tables named like
 * replaced ones may hold the previous database, and the user is told the site
 * may already run on the imported one.
 */
final class ImportCleanup {

	/**
	 * Deletes the work files of the import. A local archive is never deleted,
	 * a download only when the job made it, an upload always. The extraction
	 * journal and the swap marker go too: callers run this after their
	 * readers (the temp sweep, the swap check).
	 *
	 * @param string $dir          The job's work folder.
	 * @param bool   $ownsDownload Whether the archive was downloaded by this job.
	 * @return bool False when a work file could not be deleted (no PHP warning; the caller reports it).
	 */
	public static function workFiles( string $dir, bool $ownsDownload ): bool {
		$files = [ ImportFiles::DATABASE, ImportFiles::DEFERRED, ImportFiles::EXTRACT_DIRS, ImportFiles::SWAP_STARTED, ImportFiles::UPLOAD, ImportFiles::UPLOAD . UploadReceiver::PART_SUFFIX ];
		if ( $ownsDownload ) {
			$files[] = ImportFiles::DOWNLOAD;
			$files[] = ImportFiles::DOWNLOAD . RangeDownloader::PART_SUFFIX;
		}
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		foreach ( false === $names ? [] : $names as $name ) {
			if ( TempName::is( $name ) ) {
				$files[] = $name; // An interrupted database extraction.
			}
		}
		$removed = true;
		foreach ( $files as $file ) {
			$removed = Files::removeFile( $dir . '/' . $file ) && $removed;
		}
		Files::removeTree( $dir . '/' . ImportFiles::STAGING );
		Files::removeTree( $dir . '/' . ImportFiles::SAFETY );

		return $removed;
	}

	/**
	 * Whether the download file in the job folder is this job's own: always
	 * for an address or a connection (the job made it, also when it stopped
	 * before the download was complete), else when the job marked its
	 * archive as owned (state.archiveOwned).
	 *
	 * @param Job $job Import job.
	 */
	public static function ownsDownload( Job $job ): bool {
		return ! empty( $job->state['archiveOwned'] ) || in_array( $job->options['source'] ?? '', [ ImportOptions::URL, ImportOptions::LINK ], true );
	}

	/**
	 * The warning for work files that stayed (workFiles() returned false).
	 */
	public static function workFilesKeptNotice(): string {
		return __( 'Some work files of this import could not be removed. They are in the plugin\'s private storage folder and go with the daily cleanup.', 'lw-migration' );
	}

	/**
	 * Deletes the temporary files an unfinished import may have left outside its
	 * work folder: the file being extracted, the safety backup being stored, and
	 * orphans in every folder the content step journaled. Runs before the work
	 * folder (and its journal) goes.
	 *
	 * @param Job                   $job        Import job.
	 * @param string                $workDir    The job's work folder.
	 * @param string                $backupsDir Backups folder (the safety backup's temporary copy).
	 * @param array<string, string> $bases      ContentRules base key => folder.
	 * @return void
	 */
	public static function discardTemps( Job $job, string $workDir, string $backupsDir, array $bases ): void {
		$cursor = $job->cursor;
		if ( isset( $cursor['extract'], $cursor['target'] ) && is_array( $cursor['extract'] ) && is_string( $cursor['target'] ) ) {
			TargetFile::discard( ExtractCursor::fromArray( $cursor['extract'] ), $cursor['target'] );
		}
		$temp = SafetyBackupStep::storeTemp( $cursor );
		if ( null !== $temp ) {
			( new BackupPublisher( $backupsDir ) )->discard( $temp, SafetyBackupStep::storeReserving( $cursor ) );
		}
		if ( [] !== $bases ) {
			ImportTemps::sweep( $workDir, $bases, $job->createdAt );
		}
	}

	/**
	 * Finish hook of a failed or cancelled import (nothing for exports and
	 * finished imports). A failure while discarding temporary files is
	 * reported and never stops the work files from going (the job keeps its
	 * original error).
	 *
	 * @param Job      $job        Ended job.
	 * @param JobStore $store      Job store.
	 * @param \Closure $connect    fn(): Connection.
	 * @param string   $backupsDir Backups folder.
	 * @param \Closure $bases      fn( Job $job ): array<string, string>, the ContentRules bases; asked only when a step journaled a folder.
	 * @param \Closure $forget     fn( string $runId ): bool, removes the run from the site's registry (TmpRunRegistry).
	 * @return void
	 */
	public static function afterFailure( Job $job, JobStore $store, \Closure $connect, string $backupsDir, \Closure $bases, \Closure $forget ): void {
		if ( 'import' !== $job->type || Job::DONE === $job->status ) {
			return;
		}
		$dir = $store->dir( $job->id );
		try {
			if ( self::maySwapped( $job, $dir ) ) {
				$users = HeldStaging::afterSwappedFailure( $job, $connect );
				$job->addWarning( self::swappedNotice( $job, $dir, self::previousTablesKept( $job, $connect ), $users ) );
			} else {
				if ( ContentStep::filesPlaced( $job ) ) {
					$job->addWarning( __( 'Some files from the archive were already put in place on the site; the database was not changed.', 'lw-migration' ) );
				}
				self::dropTemporaryTables( $job, $connect, $forget );
			}
		} finally {
			self::discardQuietly( $job, $dir, $backupsDir, $bases );
			if ( ! self::workFiles( $dir, self::ownsDownload( $job ) ) ) {
				$job->addWarning( self::workFilesKeptNotice() );
			}
		}
	}

	/**
	 * Runs discardTemps() for a failed, cancelled or abandoned import and never
	 * throws: a failure is reported as a warning on the job.
	 *
	 * @param Job      $job        Import job.
	 * @param string   $dir        The job's work folder.
	 * @param string   $backupsDir Backups folder.
	 * @param \Closure $bases      fn( Job $job ): array<string, string>, asked only when a step journaled a folder.
	 * @return bool False when some temporary file could not be removed.
	 */
	public static function discardQuietly( Job $job, string $dir, string $backupsDir, \Closure $bases ): bool {
		$failed = false;
		$sweep  = [];
		if ( is_file( $dir . '/' . ImportFiles::EXTRACT_DIRS ) ) {
			$parts = [
				static fn(): array => (array) $bases( $job ),
				static fn(): array => self::muPluginsBase( $job ),
			];
			foreach ( $parts as $part ) {
				try {
					$sweep += $part();
				} catch ( \Throwable $e ) {
					$failed = true;
				}
			}
		}
		try {
			self::discardTemps( $job, $dir, $backupsDir, $sweep );
		} catch ( \Throwable $e ) {
			$failed = true;
		}
		if ( $failed ) {
			$job->addWarning( __( 'Some temporary files of this import could not be removed. They are named .lwm-….part and can be deleted.', 'lw-migration' ) );
		}

		return ! $failed;
	}

	/**
	 * @param Job $job Import job.
	 * @return array<string, string> The must-use plugins folder (MuPluginsStep journals it).
	 */
	private static function muPluginsBase( Job $job ): array {
		$real = realpath( ImportState::site( $job )->muPluginsDir );

		return false === $real ? [] : [ MuPluginsStep::BASE => $real ];
	}

	/**
	 * Whether replaced tables (lwo/lwy) of this run exist: the daily sweep
	 * keeps them while the job folder exists (Maintenance\TableOwners).
	 *
	 * @param Job      $job     Import job.
	 * @param \Closure $connect fn(): Connection.
	 */
	private static function previousTablesKept( Job $job, \Closure $connect ): bool {
		$tmp = (string) ( $job->state['tmpPrefix'] ?? '' );
		if ( '' === $tmp ) {
			return false;
		}
		try {
			$swap = new TableSwap( $connect(), $tmp, ImportState::site( $job )->tablePrefix );

			return [] !== $swap->tablesWithPrefix( $swap->oldPrefix() ) || [] !== $swap->tablesWithPrefix( $swap->extraOldPrefix() );
		} catch ( \Throwable $e ) {
			return false; // The notice is still given, without this sentence.
		}
	}

	/**
	 * Whether the swap recorded itself or wrote its marker (it may have run).
	 *
	 * @param Job    $job Import job.
	 * @param string $dir The job's work folder.
	 */
	public static function maySwapped( Job $job, string $dir ): bool {
		return ! empty( $job->state['swapped'] ) || is_file( $dir . '/' . ImportFiles::SWAP_STARTED );
	}

	/**
	 * Drops the run's tables; when none is left, the run is forgotten and its
	 * id freed (RunMarker last), so the daily sweep has nothing to do.
	 *
	 * @param Job      $job     Import job.
	 * @param \Closure $connect fn(): Connection.
	 * @param \Closure $forget  fn( string $runId ): bool.
	 */
	private static function dropTemporaryTables( Job $job, \Closure $connect, \Closure $forget ): void {
		$tmp = (string) ( $job->state['tmpPrefix'] ?? '' );
		if ( '' === $tmp ) {
			self::releaseUnregistered( $job, $connect, $forget );
			return;
		}
		$db = $connect();
		foreach ( ( new TableSwap( $db, $tmp, ImportState::site( $job )->tablePrefix ) )->cleanup() as $warning ) {
			/* translators: %s: technical message from the database */
			$job->addWarning( sprintf( __( 'Removing the temporary tables of this import: %s', 'lw-migration' ), $warning ) );
		}
		try {
			RunMarker::releaseIfUnused( $db, $tmp, $forget );
		} catch ( QueryException $e ) {
			unset( $e ); // The daily sweep frees the run later.
		}
	}

	/**
	 * A run id this job reserved but whose prefix it never saved (the request
	 * died in between, perhaps after registering it): when its marker carries
	 * the job's token, the run is forgotten first, then the marker goes
	 * (RunMarker::releaseIfUnused, which also keeps it while any table is left).
	 *
	 * @param Job      $job     Import job without a temporary prefix.
	 * @param \Closure $connect fn(): Connection.
	 * @param \Closure $forget  fn( string $runId ): bool.
	 */
	private static function releaseUnregistered( Job $job, \Closure $connect, \Closure $forget ): void {
		$candidate = DatabaseStep::candidate( $job );
		if ( null === $candidate ) {
			return;
		}
		$db = $connect();
		try {
			if ( RunMarker::heldWith( $db, $candidate['prefix'], $candidate['token'] ) ) {
				RunMarker::releaseIfUnused( $db, $candidate['prefix'], $forget );
			}
		} catch ( QueryException $e ) {
			unset( $e ); // The marker stays; nothing else of the run exists.
		}
	}

	/**
	 * What the swap step had not done yet when it stopped ('' when nothing).
	 *
	 * @param Job $job Import job stopped in the swap step.
	 */
	private static function swapPending( Job $job ): string {
		$views  = empty( $job->cursor[ SwapStep::DEFERRED ] );
		$tables = empty( $job->cursor[ SwapStep::CLEANED ] );
		if ( $views && $tables ) {
			return __( 'The imported views were not created and the replaced tables were not removed.', 'lw-migration' );
		}
		if ( $views ) {
			return __( 'The imported views were not created.', 'lw-migration' );
		}

		return $tables ? __( 'The replaced tables were not removed.', 'lw-migration' ) : '';
	}

	/**
	 * What is live and what did not happen, by the step that stopped.
	 *
	 * @param Job    $job  Import job.
	 * @param string $dir  Work folder.
	 * @param bool   $kept  Whether replaced tables of this run exist.
	 * @param string $users What the user merge left undone (HeldStaging), or ''.
	 */
	private static function swappedNotice( Job $job, string $dir, bool $kept, string $users ): string {
		$step  = (string) $job->currentStep();
		$parts = [
			! empty( $job->state['swapped'] )
				? __( 'The import stopped after the database had been replaced: the site already runs on the imported database.', 'lw-migration' )
				: __( 'The import stopped while the database was being replaced: the site may already run on the imported database. Leftover tables were kept.', 'lw-migration' ),
		];
		if ( SwapStep::KEY === $step ) {
			$pending = self::swapPending( $job );
			if ( '' !== $pending ) {
				$parts[] = $pending;
			}
		} elseif ( PostImportStep::KEY === $step ) {
			$parts[] = __( 'The post-import fixes (caches, permalinks, plugin settings) did not finish.', 'lw-migration' );
		}
		if ( '' !== $users ) {
			$parts[] = $users;
		}
		$staged = MuPluginsStep::staged( $dir . '/' . ImportFiles::STAGING );
		if ( [] !== $staged ) {
			/* translators: %s: list of file names below the must-use plugins folder */
			$parts[] = sprintf( __( 'The archive\'s must-use plugins were not restored: %s.', 'lw-migration' ), MuPluginsStep::nameList( $staged ) );
		}
		$backup    = $job->state['safetyBackup']['name'] ?? null;
		$hasBackup = is_string( $backup ) && '' !== $backup;
		if ( $kept ) {
			$parts[] = $hasBackup
				? __( 'The previous database tables are kept for about a day for manual recovery; the safety backup stays until you delete it.', 'lw-migration' )
				: __( 'The previous database tables are kept for about a day for manual recovery.', 'lw-migration' );
		}
		$parts[] = $hasBackup
			/* translators: %s: backup file name */
			? sprintf( __( 'Restore the safety backup %s if you need the previous database (it holds the database only, no files).', 'lw-migration' ), $backup )
			: __( 'No safety backup of the previous database was taken.', 'lw-migration' );

		return implode( ' ', $parts );
	}
}
