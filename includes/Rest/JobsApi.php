<?php
/**
 * Jobs over REST: start, look, tick, answer, cancel, upload.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportJobs;
use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\Steps\UploadStep;
use LightweightPlugins\Migration\Import\UploadException;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * The browser's entry to the job engine (spec 8). Jobs are created through
 * the same ExportJobs/ImportJobs as on the command line, ticked through
 * WebDriver, answered only with what they asked for (JobInput), and shown only
 * through JobView. Every answer and upload chunk first claims the site lock
 * (WebDriver::claim); a finished job is handed back as it is, and every result
 * passes WebDriver::settle so a finished job never keeps the lock. Upload
 * chunks are appended under the job's lock after the job is read again, so they
 * never race a tick, a cancel or each other. Anything a call prints is thrown
 * away (QuietOutput). A busy job (JobBusyException), a busy site
 * (SiteBusyException) and internal failures go to ErrorMap as they are.
 */
final class JobsApi {

	/**
	 * @param JobStore           $jobs        Job store.
	 * @param SiteLock           $lock        Site lock.
	 * @param Repository         $backups     Backups.
	 * @param SecretStore        $secrets     Passwords between requests.
	 * @param WebDriver          $driver      Browser driver.
	 * @param \Closure           $context     fn(): array{site: \LightweightPlugins\Migration\WordPress\Site, admin: array{id: int, login: string}|null, permalinks: bool, free: int|null}; prepares the storage folder.
	 * @param \Closure           $now         fn(): int.
	 * @param array<int, string> $redactPaths Absolute paths removed from the job texts (Services::redactPaths()).
	 * @param \Closure|null      $backupsReady fn(): void — the backups folder can take a backup now, or FolderRefusedException; null = not checked.
	 * @param Destinations|null  $destinations Stored destinations a backup may be sent to (null = the site's).
	 */
	public function __construct(
		private JobStore $jobs,
		private SiteLock $lock,
		private Repository $backups,
		private SecretStore $secrets,
		private WebDriver $driver,
		private \Closure $context,
		private \Closure $now,
		private array $redactPaths,
		private ?\Closure $backupsReady = null,
		private ?Destinations $destinations = null
	) {
	}

	public static function fromServices( Services $services ): self {
		$secrets   = $services->webSecrets();
		$now       = static fn(): int => time();
		$guard     = static fn( array $steps ): array => WebDownloadGuard::wrap( $steps );
		$runnerFor = static fn( string $type ): Runner => $services->runner( $type, $secrets, $guard );

		return new self(
			$services->jobs(),
			$services->lock(),
			$services->backups(),
			$secrets,
			new WebDriver( $services->jobs(), $services->lock(), $runnerFor, $now, WebLimits::tickSeconds() ),
			JobContext::closure( $services ),
			$now,
			$services->redactPaths(),
			static fn() => $services->storage()->ensureBackups(),
			Destinations::fromWordPress()
		);
	}

	/**
	 * @param array<string, mixed> $body {type: export, options?, destinations?, keepLocal?, password?} or {type: import, source, …, safetyBackup?, password?}.
	 * @throws RestException On an unknown type or a refused payload.
	 */
	public function create( array $body ): Job {
		return QuietOutput::run(
			function () use ( $body ): Job {
				$type     = $body['type'] ?? '';
				$password = JobInput::password( $body['password'] ?? null );
				if ( 'export' === $type ) {
					$options = JobInput::exportRequest( $body );
					$cloud   = ExportCloudInput::plan( $body, $this->destinations ?? Destinations::fromWordPress() );
					$context = ( $this->context )();
					$this->backupsReady();

					return JobInput::checked( fn(): Job => ExportJobs::create( $this->jobs, $this->lock, $context['site'], $options, $password, $this->secrets, $this->now(), [], $cloud ) );
				}
				if ( 'import' === $type ) {
					$context = ( $this->context )();
					$source  = JobInput::importSource( $body, $this->backups, WebLimits::uploadRoom( $context['free'] ) );
					if ( $source['options']->safetyBackup ) {
						$this->backupsReady();
					}

					return JobInput::checked( fn(): Job => ImportJobs::create( $this->jobs, $this->lock, $context['site'], $source['options'], $context['admin'], $context['permalinks'], $password, $this->secrets, $this->now(), $source['state'] ) );
				}
				throw RestException::invalid( __( 'Start a backup or a restore.', 'lw-migration' ), 'type' );
			}
		);
	}

	/**
	 * A backup (or an import's safety backup) starts only when the backups
	 * folder can take it: never hours of work for an archive with nowhere to go.
	 *
	 * @throws RestException 409 lw_migration_backup_dir_unusable.
	 */
	private function backupsReady(): void {
		try {
			if ( null !== $this->backupsReady ) {
				( $this->backupsReady )();
			}
		} catch ( FolderRefusedException $e ) {
			throw RestException::backupDirUnusable( $e );
		}
	}

	/**
	 * @param string $id Job id.
	 * @throws RestException 404 for an unknown job.
	 */
	public function get( string $id ): Job {
		return QuietOutput::run( fn(): Job => $this->load( $id ) );
	}

	public function active(): ?Job {
		return QuietOutput::run( fn(): ?Job => ActiveJob::find( $this->jobs, $this->lock->holder( $this->now() ) ) );
	}

	/**
	 * @param string $id Job id.
	 * @throws RestException 404 for an unknown job, 409 when another job holds the site.
	 */
	public function tick( string $id ): Job {
		return $this->driver->tick( $this->load( $id ) );
	}

	/**
	 * A declined confirmation cancels the job.
	 *
	 * @param string               $id   Job id.
	 * @param array<string, mixed> $body The answer.
	 * @throws RestException When the job does not wait for this answer.
	 */
	public function answer( string $id, array $body ): Job {
		return QuietOutput::run(
			function () use ( $id, $body ): Job {
				JobInput::answer( $this->load( $id ), $body ); // Refused before the site lock is touched.
				$job = $this->driver->claim( $id );
				if ( $job->isFinished() ) {
					return $job;
				}
				$answer = JobInput::answer( $job, $body );
				$runner = $this->driver->runner( $job->type );
				$after  = false === ( $answer['confirm'] ?? null ) ? $runner->cancel( $id, $this->now() ) : $runner->provideInput( $id, $answer, $this->now() );

				return $this->driver->settle( $after );
			}
		);
	}

	/**
	 * @param string $id Job id.
	 * @return array{job: Job, cancelDeferred: bool} cancelDeferred: the database was already replaced, the job finishes instead.
	 * @throws RestException 404 for an unknown job.
	 */
	public function cancel( string $id ): array {
		return QuietOutput::run(
			function () use ( $id ): array {
				$job = $this->load( $id );
				if ( ! $job->isFinished() ) {
					$job = $this->driver->settle( $this->driver->runner( $job->type )->cancel( $id, $this->now() ) );
				}

				return [
					'job'            => $job,
					'cancelDeferred' => ! $job->isFinished() && Runner::cancelDeferred( $job ),
				];
			}
		);
	}

	/**
	 * Appends a chunk. Once every byte is there (also when a resent chunk is
	 * refused for its offset because an earlier request landed it), the job is
	 * handed its upload and runs on; the last chunk resent after that answers
	 * with the job as it is.
	 *
	 * @param string $id     Job id.
	 * @param int    $offset Where the chunk starts.
	 * @param string $bytes  Raw chunk.
	 * @throws RestException When the job does not wait for an upload or the chunk is refused.
	 */
	public function upload( string $id, int $offset, string $bytes ): Job {
		return QuietOutput::run(
			function () use ( $id, $offset, $bytes ): Job {
				$saved = $this->load( $id );
				if ( $this->resentLast( $saved, $offset, $bytes ) ) {
					return $saved;
				}
				self::awaitUpload( $saved );
				$chunkBytes = WebLimits::chunkBytes();
				if ( strlen( $bytes ) > $chunkBytes ) {
					throw new RestException( 'lw_migration_chunk_too_large', __( 'This part of the upload is too large for the server.', 'lw-migration' ), 413, [ 'chunkBytes' => $chunkBytes ] );
				}
				$job = $this->driver->claim( $id );
				if ( $job->isFinished() ) {
					return $job;
				}
				$target     = UploadStep::target( $this->jobs, $id );
				$size       = (int) ( $job->state['upload']['size'] ?? 0 );
				$handedOver = $this->append( $id, $target, $size, $offset, $bytes );
				if ( null !== $handedOver ) {
					return $handedOver;
				}
				if ( UploadReceiver::received( $target ) < $size ) {
					return $this->jobs->load( $id );
				}

				return $this->driver->settle( $this->driver->runner( $job->type )->provideInput( $id, [ UploadStep::KEY => true ], $this->now() ) );
			}
		);
	}

	/**
	 * @param Job $job Job.
	 * @return array<string, mixed>
	 */
	public function view( Job $job ): array {
		return QuietOutput::run(
			function () use ( $job ): array {
				$job = clone $job;
				if ( self::waitsForUpload( $job ) ) {
					$job->input['data']['received']   = UploadReceiver::received( UploadStep::target( $this->jobs, $job->id ) );
					$job->input['data']['chunkBytes'] = WebLimits::chunkBytes();
				}

				return JobView::of( $job, $this->driver->runner( $job->type )->defersCancel( $job ), $this->redactPaths );
			}
		);
	}

	/**
	 * Appends under the job's lock, after reading the job again (a cancel, a
	 * finish or a handover since the claim is seen here; a resent last chunk
	 * then returns that job). A refusal (offset, a failed rename of the full
	 * part …) when every byte is already there is no failure: the caller hands
	 * the upload over, and UploadStep finishes it or fails truthfully. A job
	 * that no longer waits or a refused chunk is a RestException (a non-archive
	 * also fails the job).
	 *
	 * @param string $id     Job id.
	 * @param string $target The upload in the job folder.
	 * @param int    $size   Declared size.
	 * @param int    $offset Where the chunk starts.
	 * @param string $bytes  Raw chunk.
	 * @return Job|null The job when it was handed its upload before; null when the caller goes on.
	 * @throws JobBusyException When another request holds the job.
	 */
	private function append( string $id, string $target, int $size, int $offset, string $bytes ): ?Job {
		$lock = $this->jobs->acquire( $id );
		if ( null === $lock ) {
			throw new JobBusyException( 'The job is busy; try again.' );
		}
		$failure = null;
		try {
			$saved = $this->jobs->load( $id );
			if ( $this->resentLast( $saved, $offset, $bytes ) ) {
				return $saved;
			}
			self::awaitUpload( $saved );
			UploadReceiver::append( $target, $size, $offset, $bytes );
		} catch ( UploadException $e ) {
			$failure = UploadException::NOT_ARCHIVE !== $e->reason && UploadReceiver::received( $target ) === $size ? null : $e;
		} finally {
			$this->jobs->release( $lock );
		}
		if ( null !== $failure ) {
			throw $this->refused( $id, $failure );
		}

		return null;
	}

	private function refused( string $id, UploadException $e ): RestException {
		if ( UploadException::NOT_ARCHIVE === $e->reason ) {
			$message = __( 'This file is not a .wpress archive: it does not start with package.json.', 'lw-migration' );
			$this->driver->settle( $this->driver->runner( 'import' )->fail( $id, $message, $this->now() ) );

			return new RestException( 'lw_migration_not_wpress', $message, 400 );
		}

		return match ( $e->reason ) {
			UploadException::OFFSET      => new RestException( 'lw_migration_upload_offset', __( 'The upload goes on from where it stopped.', 'lw-migration' ), 409, [ 'received' => $e->received ] ),
			UploadException::TOO_LARGE   => new RestException( 'lw_migration_upload_too_large', __( 'The file is larger than it was when the upload started.', 'lw-migration' ), 400 ),
			UploadException::EMPTY_CHUNK => new RestException( 'lw_migration_upload_empty', __( 'An empty part of the upload arrived.', 'lw-migration' ), 400 ),
			default                      => new RestException( 'lw_migration_upload_write', __( 'The uploaded part could not be saved. Is the disk full?', 'lw-migration' ), 500 ),
		};
	}

	/**
	 * @param Job $job Job as saved.
	 * @throws RestException Unless the job waits for its upload.
	 */
	private static function awaitUpload( Job $job ): void {
		if ( ! self::waitsForUpload( $job ) ) {
			throw new RestException( 'lw_migration_not_uploading', __( 'This job is not waiting for an upload.', 'lw-migration' ), 409 );
		}
	}

	/**
	 * Whether this chunk is the last one of an upload the job no longer waits
	 * for because it already has every byte (the answer to it got lost).
	 *
	 * @param Job    $job    Job as saved.
	 * @param int    $offset Where the chunk starts.
	 * @param string $bytes  Raw chunk.
	 */
	private function resentLast( Job $job, int $offset, string $bytes ): bool {
		$size = (int) ( $job->state['upload']['size'] ?? 0 );

		return ! self::waitsForUpload( $job ) && $size > 0 && $offset + strlen( $bytes ) === $size
			&& UploadReceiver::received( UploadStep::target( $this->jobs, $job->id ) ) === $size;
	}

	private static function waitsForUpload( Job $job ): bool {
		return Job::NEEDS_INPUT === $job->status && UploadStep::KEY === ( $job->input['kind'] ?? '' );
	}

	/**
	 * @param string $id Job id.
	 * @throws RestException 404 for an unknown or malformed id.
	 */
	private function load( string $id ): Job {
		try {
			return $this->jobs->load( $id );
		} catch ( JobException $e ) {
			throw new RestException( 'lw_migration_no_job', __( 'This job does not exist (any more).', 'lw-migration' ), 404 );
		}
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
