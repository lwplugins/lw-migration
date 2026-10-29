<?php
/**
 * Export step: moves the finished archive into the backups folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Storage\BackupFileMode;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\StorageException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Support\Text;
use LightweightPlugins\Migration\WordPress\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Saves its names before acting: the first call picks a free backup name and
 * a hidden temp name and returns "more" so the Runner saves them. Then the
 * archive goes into the temp: a hard link on one file system, otherwise a
 * verified copy over as many calls as it takes (FileMover, cursor.copy); the
 * finished copy's identity and the archive's are saved (cursor.moved) before
 * anything is published. The next call publishes the temp under the final
 * name without ever replacing a file (BackupPublisher; a name taken meanwhile
 * is picked again), and only then removes the work archive, and only while the
 * published name still holds the copy and the archive is unchanged. A crash
 * at any point repeats safely. The backup is recorded in state.backup before
 * pruning starts in a call of its own: a prune that throws becomes a warning
 * (with its message, server paths redacted), and one that killed the request
 * is skipped on its next start. A backup already published under the saved name is
 * recorded at the very start of every later call, before any other work, so a
 * job that fails after publishing (attempt guard, clean step) still names its
 * complete backup in state.backup; a name given up after a collision drops
 * that record again. A backup taken before an import or a reset (kinds
 * pre-import, pre-reset) never prunes. A scheduled backup (state.schedule) is
 * named with the schedule id as suffix and prunes only that schedule's backups,
 * with the retention copied into the job when it started; it prunes nothing when
 * its schedule no longer exists (deleting a schedule keeps its backups) or cannot be looked up.
 */
final class StoreStep implements StepInterface {

	private const PRUNE = 'prune';

	private const WARNING_BYTES = 512;

	/**
	 * Moves the archive into the backups folder.
	 *
	 * @var FileMover
	 */
	private FileMover $mover;

	/**
	 * @param JobStore       $store          Job store.
	 * @param string         $backupsDir     Backups folder.
	 * @param Retention      $retention      Pruning rules of manual backups.
	 * @param \Closure       $now            fn(): int.
	 * @param \Closure|null  $scheduleExists fn( string $id ): bool — whether a schedule still exists;
	 *                                       null = never prune a scheduled backup.
	 * @param \Closure|null  $ready          fn(): void — makes the backups folder ready or throws ExportException
	 *                                       (a chosen folder is never created here); null = create the folder (tests).
	 * @param FileMover|null $mover          Moves the archive into the folder (default: hard links where they work).
	 * @param \Closure|null  $link           fn( string $from, string $to ): bool — the hard link of the publish (tests); null = link().
	 * @param string         $movingDir      Target of an unfinished move ('' = none): its names are taken too.
	 */
	public function __construct( private JobStore $store, private string $backupsDir, private Retention $retention, private \Closure $now, private ?\Closure $scheduleExists = null, private ?\Closure $ready = null, ?FileMover $mover = null, private ?\Closure $link = null, private string $movingDir = '' ) {
		$this->mover = $mover ?? new FileMover();
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$this->recordIfPublished( $job );
		if ( null !== $this->ready ) {
			( $this->ready )();
		} elseif ( ! is_dir( $this->backupsDir ) && ! mkdir( $this->backupsDir, Files::PRIVATE_DIR, true ) && ! is_dir( $this->backupsDir ) ) {
			throw new ExportException( __( 'Cannot create the backups folder.', 'lw-migration' ) );
		}
		if ( self::PRUNE === ( $job->cursor['phase'] ?? '' ) ) {
			return $this->pruneOnce( $job );
		}
		$name = (string) ( $job->cursor['name'] ?? '' );
		$temp = (string) ( $job->cursor['temp'] ?? '' );
		if ( ! BackupName::isValid( $name ) || ! TempName::is( $temp ) ) {
			$job->cursor = [
				'name' => $this->freeName( $job ),
				'temp' => TempName::name(),
				'copy' => [],
			];
			return StepResult::more( 25, __( 'Saving the backup...', 'lw-migration' ) );
		}
		$source = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE;
		$moved  = self::moved( $job );
		if ( null === $moved ) {
			return $this->stage( $job, $source, $temp, $deadline );
		}
		if ( ! in_array( FileIdentity::of( $source ), [ null, $moved['original'] ], true ) ) {
			throw new ExportException( FolderMessages::storeFailed( FileMover::MISMATCH ) );
		}
		$more = $this->publish( $job, $temp, $name );
		if ( null !== $more ) {
			return $more;
		}
		// Recorded before the original goes: a failure from here on still names the backup.
		if ( ! $this->recordOurs( $job, $name, $moved['copy'] ) ) {
			throw new ExportException( FolderMessages::storeFailed( FileMover::MISMATCH ) );
		}
		self::removeOriginal( $source, $moved, $this->backupsDir . '/' . $name );
		$job->cursor['phase'] = self::PRUNE;

		return StepResult::more( 90, __( 'Removing old backups...', 'lw-migration' ) );
	}

	/**
	 * Moves the work archive into the hidden temp of the backups folder (one
	 * slice per call); when the copy is verified its identity and the
	 * archive's are saved before the next call publishes it.
	 *
	 * @param Job      $job      Job.
	 * @param string   $source   Work archive.
	 * @param string   $temp     Temp name.
	 * @param Deadline $deadline Time budget.
	 * @throws ExportException When the archive cannot be moved.
	 */
	private function stage( Job $job, string $source, string $temp, Deadline $deadline ): StepResult {
		$copy = (array) ( $job->cursor['copy'] ?? [] );
		try {
			$done = $this->mover->copy( $source, $this->backupsDir . '/' . $temp, $copy, $deadline );
		} catch ( StorageException $e ) {
			throw new ExportException( FolderMessages::storeFailed( (int) $e->getCode() ) );
		} finally {
			$job->cursor['copy'] = $copy;
		}
		if ( $done ) {
			// Owner-only (or the lw_migration_backup_file_mode filter's value): downloads go through PHP.
			BackupFileMode::apply( $this->backupsDir . '/' . $temp );
			$job->cursor['moved'] = [
				'copy'     => $copy['id'],
				'original' => $copy['source'],
			];
			unset( $job->cursor['copy'] );
		}

		return StepResult::more( 25, __( 'Saving the backup...', 'lw-migration' ) );
	}

	/**
	 * @param Job $job Job.
	 * @return array{copy: array{id: string, size: int, mtime: int}, original: array{id: string, size: int, mtime: int}}|null The finished copy, or null.
	 */
	private static function moved( Job $job ): ?array {
		$moved = $job->cursor['moved'] ?? null;

		return is_array( $moved ) && is_array( $moved['copy'] ?? null ) && is_array( $moved['original'] ?? null ) ? $moved : null;
	}

	/**
	 * Gives the copied temp its final name (BackupPublisher). Without hard
	 * links the name is first saved as cursor.reserving and reserved in the
	 * next call, so a request that dies in between finds its empty
	 * reservation again. A name taken meanwhile is picked again.
	 *
	 * @param Job    $job  Job.
	 * @param string $temp Temp name.
	 * @param string $name Backup name.
	 * @return StepResult|null Null once the backup holds the name.
	 * @throws ExportException When the temp cannot get its name, or neither it nor the backup is there.
	 */
	private function publish( Job $job, string $temp, string $name ): ?StepResult {
		$publisher = new BackupPublisher( $this->backupsDir, $this->link );
		if ( $publisher->isStaged( $temp ) ) {
			$result = ( $job->cursor['reserving'] ?? null ) === $name ? $publisher->publishReserved( $temp, $name ) : $publisher->publish( $temp, $name );
			if ( BackupPublisher::RESERVE === $result ) {
				$job->cursor['reserving'] = $name;
				return StepResult::more( 50, __( 'Saving the backup...', 'lw-migration' ) );
			}
			if ( BackupPublisher::TAKEN === $result ) {
				unset( $job->state['backup'], $job->cursor['reserving'] );
				$job->cursor['name'] = $this->freeName( $job );
				return StepResult::more( 50, __( 'Saving the backup...', 'lw-migration' ) );
			}
		} elseif ( ! $publisher->isPublished( $name ) ) {
			throw new ExportException( __( 'The finished backup file is missing.', 'lw-migration' ) );
		}
		unset( $job->cursor['reserving'] );

		return null;
	}

	/**
	 * @param string                                                                                                    $source    Work archive.
	 * @param array{copy: array{id: string, size: int, mtime: int}, original: array{id: string, size: int, mtime: int}} $moved     The finished copy.
	 * @param string                                                                                                    $published The backup.
	 * @throws ExportException When the archive is not the one copied (nothing removed).
	 */
	private static function removeOriginal( string $source, array $moved, string $published ): void {
		try {
			FileIdentity::removeOriginal( $source, $moved['original'], $published, $moved['copy'] );
		} catch ( StorageException $e ) {
			throw new ExportException( FolderMessages::storeFailed( (int) $e->getCode() ) );
		}
	}

	/**
	 * The backup taken before an import or a reset never prunes: the backup
	 * being restored may be one the retention rules would drop.
	 *
	 * @param Job $job Job.
	 * @return StepResult
	 */
	private function pruneOnce( Job $job ): StepResult {
		$name    = (string) ( $job->state['backup']['name'] ?? '' );
		$deleted = [];
		$safety  = self::isSafety( $job );
		if ( ! $safety && (int) ( $job->attempt['count'] ?? 1 ) > 1 ) {
			$job->addWarning( __( 'Old backups were not removed: the previous attempt stopped before it finished.', 'lw-migration' ) );
		} elseif ( ! $safety ) {
			try {
				$this->prune( $job, $name, $deleted );
			} catch ( \Throwable $e ) {
				/* translators: %s: error message */
				$job->addWarning( sprintf( __( 'Old backups could not all be removed (%s). The new backup is complete.', 'lw-migration' ), $this->redact( $e->getMessage() ) ) );
			}
		}
		$job->state['pruned'] = $deleted;

		/* translators: %s: backup file name */
		return StepResult::done( sprintf( __( 'Backup saved: %s', 'lw-migration' ), $name ) );
	}

	/**
	 * Records the backup when the saved name holds our finished copy (its
	 * whole identity tuple) and no temp is left to publish. A copy still under
	 * way has published nothing.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function recordIfPublished( Job $job ): void {
		$name  = (string) ( $job->cursor['name'] ?? '' );
		$temp  = (string) ( $job->cursor['temp'] ?? '' );
		$moved = self::moved( $job );
		if ( null === $moved || self::PRUNE === ( $job->cursor['phase'] ?? '' ) || ! BackupName::isValid( $name ) || ! TempName::is( $temp ) ) {
			return;
		}
		if ( ! ( new BackupPublisher( $this->backupsDir ) )->isStaged( $temp ) ) {
			$this->recordOurs( $job, $name, $moved['copy'] );
		}
	}

	/**
	 * @param Job                                      $job  Job.
	 * @param string                                   $name Backup name.
	 * @param array{id: string, size: int, mtime: int} $copy Identity of our copy.
	 * @return bool False when the name does not hold our copy (nothing recorded).
	 */
	private function recordOurs( Job $job, string $name, array $copy ): bool {
		$target = $this->backupsDir . '/' . $name;
		if ( FileIdentity::of( $target ) !== $copy ) {
			return false;
		}
		$job->state['backup'] = [
			'name' => $name,
			'path' => $target,
			'size' => $copy['size'],
		];

		return true;
	}

	private function redact( string $message ): string {
		$dir  = rtrim( $this->backupsDir, '/' );
		$real = realpath( $dir );
		foreach ( array_unique( array_filter( [ $dir, false === $real ? '' : $real ] ) ) as $path ) {
			$message = str_replace( $path, '[backups]', $message );
		}

		return Text::clip( $message, self::WARNING_BYTES );
	}

	private static function isSafety( Job $job ): bool {
		return in_array( ExportOptions::fromArray( $job->options )->kind, [ 'pre-import', 'pre-reset' ], true );
	}

	private function freeName( Job $job ): string {
		$now      = (int) ( $this->now )();
		$safety   = self::isSafety( $job );
		$schedule = self::scheduleId( $job );
		$home     = Site::fromArray( (array) ( $job->state['site'] ?? [] ) )->homeUrl;
		$suffix   = random_int( 0, 999 );
		do {
			if ( $safety ) {
				$name = 'pre-reset' === ExportOptions::fromArray( $job->options )->kind ? BackupName::preReset( $now ) : BackupName::safety( $now );
			} elseif ( null !== $schedule ) {
				$name = BackupName::forSchedule( $home, $now, $suffix, $schedule );
			} else {
				$name = BackupName::forSite( $home, $now, $suffix );
			}
			++$suffix;
			++$now;
		} while ( self::taken( $this->backupsDir . '/' . $name ) || ( '' !== $this->movingDir && self::taken( $this->movingDir . '/' . $name ) ) );

		return $name;
	}

	private static function taken( string $path ): bool {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Deletes what the retention rules drop (never the backup just stored) through
	 * the repository's own-folder guards, and forgets the labels of the deleted ones.
	 * During an unfinished move both of our folders count: the rules see every
	 * backup once, and a dropped one leaves both folders (Repository::delete()).
	 * A scheduled backup uses its schedule's rules on that schedule's backups only,
	 * and nothing when the schedule is gone or its id is not valid (never the
	 * manual scope).
	 *
	 * @param Job                $job     Job.
	 * @param string             $keep    The backup just stored.
	 * @param array<int, string> $deleted Receives each deleted name, also when a later one throws.
	 * @return void
	 */
	private function prune( Job $job, string $keep, array &$deleted ): void {
		$schedule  = self::scheduleId( $job );
		$retention = $this->retention;
		if ( null === $schedule && array_key_exists( 'schedule', $job->state ) ) {
			return;
		}
		if ( null !== $schedule ) {
			if ( null === $this->scheduleExists || true !== ( $this->scheduleExists )( $schedule ) ) {
				return;
			}
			$retention = Retention::fromOption( $job->state['schedule']['retention'] ?? [] );
		}
		$repository = new Repository( $this->backupsDir, '', $this->movingDir, new Shares() );
		foreach ( $retention->prune( $repository->all(), (int) ( $this->now )(), $keep, $schedule ) as $old ) {
			if ( $old !== $keep && $repository->delete( $old ) ) {
				$deleted[] = $old;
				Labels::remove( $old );
			}
		}
	}

	/**
	 * The schedule of a scheduled backup, or null.
	 *
	 * @param Job $job Job.
	 */
	private static function scheduleId( Job $job ): ?string {
		$id = $job->state['schedule']['id'] ?? null;

		return is_string( $id ) && 1 === preg_match( Schedule::ID_PATTERN, $id ) ? $id : null;
	}
}
