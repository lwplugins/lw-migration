<?php
/**
 * Lazily built services, wired to WordPress.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Database\Connection;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Export\Estimate\EstimateStore;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Multisite\SiteFactory;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetPipeline;
use LightweightPlugins\Migration\Schedules\Repository as ScheduleRepository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\ScheduleSecrets;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\WordPress\SiteLock;
use LightweightPlugins\Migration\WordPress\TmpRunRegistry;
use LightweightPlugins\Migration\WordPress\TransientSecretStore;
use LightweightPlugins\Migration\WordPress\WpConnection;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that knows how the pieces fit together in WordPress. Jobs
 * live inside the protected storage root (random name + deny files).
 */
final class Services {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The storage root folder, resolved once (the backups folder setting is not cached).
	 *
	 * @var string|null
	 */
	private ?string $root = null;

	/**
	 * Job store under the storage root.
	 *
	 * @var JobStore|null
	 */
	private ?JobStore $jobs = null;

	/**
	 * Site lock.
	 *
	 * @var SiteLock|null
	 */
	private ?SiteLock $lock = null;

	public static function get(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The storage root with the backups folder setting read now: a move in
	 * another process must never leave this one writing into the old folder.
	 */
	public function storage(): StorageDir {
		if ( null === $this->root ) {
			$this->root = StorageDir::fromWordPress()->root();
		}

		return new StorageDir( $this->root, BackupDir::read() );
	}

	public function jobs(): JobStore {
		if ( null === $this->jobs ) {
			$this->jobs = new JobStore( $this->storage()->jobsDir() );
		}

		return $this->jobs;
	}

	/**
	 * A new wrapper on every call: $wpdb may have reconnected (and replaced its
	 * link) since the last tick, so a connection is never kept between ticks.
	 *
	 * @return Connection
	 */
	public function connection(): Connection {
		return WpConnection::fromWpdb();
	}

	public function lock(): SiteLock {
		if ( null === $this->lock ) {
			$this->lock = new SiteLock();
		}

		return $this->lock;
	}

	public function backups(): Repository {
		return Repository::fromWordPress( $this->storage() );
	}

	public function schedules(): ScheduleRepository {
		return new ScheduleRepository();
	}

	/**
	 * Job secrets for drivers whose ticks are separate requests (browser,
	 * cron): the sealed job transient, and for a scheduled job its schedule's
	 * password when the transient was evicted (ScheduleSecrets).
	 */
	public function webSecrets(): SecretStore {
		return new ScheduleSecrets( new TransientSecretStore(), $this->jobs(), $this->schedules(), new SealedPassword() );
	}

	public function retention(): Retention {
		return Retention::fromOption( get_option( Options::RETENTION, [] ) );
	}

	/** Size of all tables of the site's database, for the disk-space estimate. */
	public function databaseBytes(): int {
		$rows = $this->connection()->query( 'SELECT COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.TABLES WHERE table_schema = DATABASE()' );

		return (int) ( $rows[0]['b'] ?? 0 );
	}

	/**
	 * Hard links where they work (filter lw_migration_move_links: false forces copies).
	 */
	public function fileMover(): FileMover {
		/**
		 * Whether a hard link may stand in for a copy when backups are moved.
		 *
		 * @param bool $links Default true.
		 */
		return new FileMover( (bool) apply_filters( 'lw_migration_move_links', true ) );
	}

	/**
	 * @param string $type Job type.
	 * @return array<string, StepInterface>
	 * @throws JobException For an unknown type.
	 */
	public function steps( string $type ): array {
		if ( 'export' === $type ) {
			return ExportPipeline::steps( $this ) + CloudPipeline::steps( $this );
		}
		if ( 'import' === $type ) {
			return ImportPipeline::steps( $this ) + CloudPipeline::steps( $this );
		}
		if ( MoveJobs::TYPE === $type ) {
			return MoveJobs::steps( $this );
		}
		if ( PushJobs::TYPE === $type ) {
			return LinkPipeline::pushSteps( $this );
		}
		if ( ResetJobs::TYPE === $type ) {
			return ResetPipeline::steps( $this );
		}
		if ( CloudJobs::TYPE === $type ) {
			return CloudPipeline::steps( $this );
		}
		throw new JobException( sprintf( 'Unknown job type: %s', $type ) );
	}

	/**
	 * A runner whose failure messages never show the server's folders and whose
	 * finish hook cleans up after the job (it runs after the final save). An
	 * import whose swap started is never cancelled (Runner::cancel defers it),
	 * nor a push whose confirmation reached the other site until that site
	 * ends (LinkPipeline::pastNoReturn()).
	 * A driver may wrap the steps (the browser guards whole-file downloads).
	 *
	 * @param string        $type     Job type.
	 * @param SecretStore   $secrets  Where the job's password is kept.
	 * @param \Closure|null $decorate fn( array<string, StepInterface> $steps ): array<string, StepInterface>.
	 * @return Runner
	 */
	public function runner( string $type, SecretStore $secrets, ?\Closure $decorate = null ): Runner {
		$steps = $this->steps( $type );

		return new Runner(
			$this->jobs(),
			null === $decorate ? $steps : $decorate( $steps ),
			$secrets,
			$this->redactPaths(),
			function ( Job $job ): void {
				$this->finished( $job );
			},
			fn( Job $job ): bool => ( 'import' === $job->type && ImportCleanup::maySwapped( $job, $this->jobs()->dir( $job->id ) ) ) || LinkPipeline::pastNoReturn( $job ) || ResetJobs::defersCancel( $this, $job )
		);
	}

	/**
	 * Folders hidden from failure messages and job.log: the storage root, the
	 * site, wp-content, this plugin and the backups folders (the chosen one and
	 * the target of an unfinished move), each also by its real path when a
	 * symlink makes that differ.
	 *
	 * @return array<int, string>
	 */
	public function redactPaths(): array {
		$paths   = [];
		$storage = $this->storage();
		foreach ( array_merge( [ $storage->root(), ABSPATH, WP_CONTENT_DIR, dirname( LW_MIGRATION_FILE ) ], $storage->backupDirs() ) as $path ) {
			$paths[] = $path;
			$real    = realpath( $path );
			if ( false !== $real ) {
				$paths[] = $real;
			}
		}

		return array_values( array_unique( array_map( static fn( string $p ): string => rtrim( $p, '/' ), $paths ) ) );
	}

	/**
	 * Runs once when a job is done, failed or cancelled: a failed or cancelled
	 * export loses its work files and the temp copy of an interrupted store (a
	 * finished one cleaned them itself); a failed or cancelled import drops its
	 * temporary tables (or, after the swap, says what is live) and its
	 * temporary files; a failed or cancelled move loses the temp of the backup
	 * in flight (originals and moved backups stay); a failed or cancelled push
	 * loses its work files and asks the other site to let go of its job; a
	 * failed or cancelled cloud upload is aborted at its destination and a
	 * cloud download loses its temp (CloudJobs::finished()). An import or a
	 * reset, done or not, forgets the backup size estimate first: the site may
	 * be another one now, so the Backup section measures it again. A job of a schedule is
	 * recorded last (RunRecorder: log, label, mail); a failure there reaches
	 * the Runner's job.log note like any cleanup failure, and the site lock is
	 * released in every case (when this job holds it).
	 *
	 * @param Job $job Ended job.
	 * @return void
	 */
	public function finished( Job $job ): void {
		try {
			if ( in_array( $job->type, [ 'import', ResetJobs::TYPE ], true ) ) {
				( new EstimateStore() )->forget();
			}
			if ( 'export' === $job->type && Job::DONE !== $job->status ) {
				$this->discardExportWork( $job );
			}
			if ( 'import' === $job->type && Job::DONE !== $job->status ) {
				$this->afterFailedImport( $job );
			}
			if ( MoveJobs::TYPE === $job->type && Job::DONE !== $job->status ) {
				MoveJobs::discard( $job );
			}
			if ( PushJobs::TYPE === $job->type && Job::DONE !== $job->status ) {
				$this->afterFailedPush( $job );
			}
			ResetJobs::finished( $this, $job );
			CloudJobs::finished( $this, $job );
		} finally {
			try {
				// After the cleanup, before the lock goes: the run log, label and mail of a scheduled job.
				if ( isset( $job->state['schedule'] ) ) {
					RunRecorder::fromServices( $this )->finished( $job, time() );
				}
			} finally {
				$this->lock()->release( $job->id );
			}
		}
	}

	/**
	 * Removes what an unfinished export left: its work files, its safety
	 * folder, and the temp copy and empty name reservation of an interrupted
	 * store.
	 *
	 * @param Job $job Failed, cancelled or given-up export or push.
	 * @return void
	 */
	public function discardExportWork( Job $job ): void {
		if ( ! in_array( $job->type, [ 'export', PushJobs::TYPE ], true ) ) {
			return;
		}
		$dir = $this->jobs()->dir( $job->id );
		ExportFiles::remove( $dir );
		Files::removeTree( $dir . '/' . ImportFiles::SAFETY );
		( new BackupPublisher( $this->storage()->backupsDir() ) )->discard( (string) ( $job->cursor['temp'] ?? '' ), (string) ( $job->cursor['reserving'] ?? '' ) );
	}

	/**
	 * The work files go first; the other site's answer to the release may add
	 * a warning, which is saved here (the Runner saved the job before this
	 * hook).
	 *
	 * @param Job $job Failed or cancelled push.
	 * @return void
	 */
	private function afterFailedPush( Job $job ): void {
		$warnings = count( $job->warnings );
		try {
			$this->discardExportWork( $job );
		} finally {
			LinkPipeline::release( $job );
			if ( count( $job->warnings ) !== $warnings ) {
				$this->jobs()->save( $job );
			}
		}
	}

	/**
	 * The Runner saved the job before this hook, so warnings the cleanup adds
	 * (the after-swap notice, tables that could not be dropped, the removed
	 * subsites) and the forgotten download address are saved again here, also
	 * when the cleanup throws. On a network the subsites this import created go
	 * first, while the swap marker still tells whether they may hold restored
	 * data (SiteFactory::undoCreated()).
	 *
	 * @param Job $job Failed or cancelled import.
	 * @return void
	 */
	private function afterFailedImport( Job $job ): void {
		$warnings = count( $job->warnings );
		$dir      = $this->jobs()->dir( $job->id );
		$staging  = $dir . '/' . ImportFiles::STAGING;
		$root     = $this->storage()->root();
		try {
			try {
				foreach ( ImportState::onNetwork( $job ) ? ( new SiteFactory() )->undoCreated( $job, $dir ) : [] as $warning ) {
					$job->addWarning( $warning );
				}
			} finally {
				ImportCleanup::afterFailure(
					$job,
					$this->jobs(),
					fn() => $this->connection(),
					$this->storage()->backupsDir(),
					static fn( Job $j ): array => ImportPipeline::rules( $j, $staging, $root )->bases(),
					static fn( string $run ): bool => ( new TmpRunRegistry() )->forget( $run )
				);
			}
		} finally {
			if ( ImportOptions::LINK === ( $job->options['source'] ?? '' ) && true !== ( $job->state['remote']['released'] ?? null ) ) {
				LinkPipeline::release( $job ); // A pull: the other site drops its export (best effort).
			}
			$scrubbed = ImportState::forgetAddress( $job );
			if ( $scrubbed || count( $job->warnings ) !== $warnings ) {
				$this->jobs()->save( $job );
			}
		}
	}
}
