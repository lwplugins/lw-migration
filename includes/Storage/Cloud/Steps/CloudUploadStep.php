<?php
/**
 * Uploads the backup to each destination of the job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\CloudRetry;
use LightweightPlugins\Migration\Storage\Cloud\ReauthNotice;
use LightweightPlugins\Migration\Storage\Cloud\ResumeCheck;
use LightweightPlugins\Migration\Storage\Cloud\UploadCursor;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\FileUpload;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\ResumesUpload;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.1. Walks the plan's destinations in order (cursor.index). Each
 * call does one unit of work — start the upload, send one part, finish, or
 * record a destination's end — and returns "more", so the Runner saves the
 * job (and the UploadSession in cursor.session) before the next call: a
 * request that dies resends at most the part it was sending, and a started
 * upload is never forgotten (plan D2). A passing failure waits (CloudRetry,
 * Retry-After in cursor.notBefore); a lasting one, or patience spent, ends
 * that destination as failed (the unfinished upload is aborted, best effort)
 * and the next one goes on. A provider that forgot the upload
 * (SESSION_GONE) gets one fresh start. A profile deleted meanwhile is
 * skipped with a warning (spec 21.3). Only a local backup that is gone or
 * changed fails the whole job. Secrets are opened per call and never
 * written into the job. A part whose requests died twice (the Runner's start
 * count, plan D27, and the cursor's death count, ResumeCheck) fails only its
 * destination. A FileUpload destination reads its parts itself; a ResumesUpload
 * one is asked how much it has first after a death or a passing failure (M7c
 * E9). A credentials refusal marks `reauth` only while the profile holds the
 * connection this run used; a cron run mails once per connection (E8). A
 * session started under another connection is dropped unsent (UploadCursor).
 */
final class CloudUploadStep implements StepInterface {

	public const KEY = 'cloud_upload';

	/** Starts from one cursor that mean two requests died on it (plan D27). */
	public const CUT_OFF_STARTS = ResumeCheck::CUT_OFF_STARTS;

	/**
	 * Pause: fn( float $seconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * @param Destinations      $destinations Profiles, secrets and types.
	 * @param \Closure          $now          fn(): int.
	 * @param \Closure|null     $pause        fn( float $seconds ): void (default usleep).
	 * @param ReauthNotice|null $reauth       Reconnect mail (null: none).
	 */
	public function __construct( private Destinations $destinations, private \Closure $now, ?\Closure $pause = null, private ?ReauthNotice $reauth = null ) {
		$this->pause = $pause ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
		};
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$plan  = CloudPlan::of( $job );
		$index = (int) ( $job->cursor['index'] ?? 0 );
		if ( null === $plan && isset( $job->state['cloud'] ) ) {
			throw new CloudJobException( __( 'The list of destinations of this job cannot be read, so the backup was not uploaded.', 'lw-migration' ) );
		}
		if ( null === $plan || ! isset( $plan->targets[ $index ] ) ) {
			return StepResult::done( self::summary( $job, $plan ) );
		}
		$backup  = self::backup( $job );
		$id      = $plan->targets[ $index ]['id'];
		$profile = $this->destinations->find( $id );
		if ( null === $profile ) {
			$text = __( 'This destination was deleted, so the backup was not uploaded there.', 'lw-migration' );
			CloudOutcome::record( $job, $id, CloudOutcome::SKIPPED, $id, '', $text, '', $this->now() );
			/* translators: %s: destination ID */
			$job->addWarning( sprintf( __( 'Destination %s no longer exists; it was skipped.', 'lw-migration' ), $id ) );
			return $this->next( $job, $plan, $index );
		}
		if ( CloudRetry::waitIfHeld( $job, $deadline, $this->pause, $this->now() ) ) {
			/* translators: %s: destination name */
			return StepResult::more( self::progress( $job, $plan, $index ), sprintf( __( 'Waiting for %s to answer again…', 'lw-migration' ), $profile->name ) );
		}
		$start = ResumeCheck::start( $job );
		if ( ResumeCheck::GO !== $start ) {
			return $this->cutOff( $job, $plan, $index, $profile, $backup['name'], ResumeCheck::CUT_ABORT === $start );
		}

		return $this->work( $job, $plan, $index, $profile, $backup );
	}

	/**
	 * The backup the job uploads.
	 *
	 * @param Job $job Job.
	 * @return array{name: string, path: string, size: int}
	 * @throws CloudJobException When it is gone or changed.
	 */
	public static function backup( Job $job ): array {
		$backup = (array) ( $job->state['backup'] ?? [] );
		$name   = (string) ( $backup['name'] ?? '' );
		$path   = (string) ( $backup['path'] ?? '' );
		$size   = (int) ( $backup['size'] ?? 0 );
		clearstatcache( true, $path );
		if ( '' === $name || '' === $path || $size < 1 || ! is_file( $path ) || (int) filesize( $path ) !== $size ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'The backup %s is no longer in the backups folder (or it changed), so it cannot be uploaded.', 'lw-migration' ), $name ) );
		}

		return [
			'name' => $name,
			'path' => $path,
			'size' => $size,
		];
	}

	/**
	 * One unit of work for one destination.
	 *
	 * @param Job                                          $job     Job.
	 * @param CloudPlan                                    $plan    Plan.
	 * @param int                                          $index   Destination position.
	 * @param Profile                                      $profile Its profile.
	 * @param array{name: string, path: string, size: int} $backup  The backup.
	 */
	private function work( Job $job, CloudPlan $plan, int $index, Profile $profile, array $backup ): StepResult {
		$destination = null;
		try {
			$destination = $this->destinations->open( $profile );
			$file        = $this->advance( $job, $destination, $profile, $backup );
			if ( null !== $file ) {
				return $this->succeeded( $job, $plan, $index, $profile, $backup['name'], $file );
			}
			CloudRetry::clear( $job );
		} catch ( TransientFailure $e ) {
			if ( $destination instanceof ResumesUpload ) {
				ResumeCheck::passing( $job );
			}
			try {
				CloudRetry::defer( $job, $e, $this->now() );
			} catch ( TransientFailure $spent ) {
				return $this->failed( $job, $plan, $index, $profile, $backup['name'], $spent, $destination );
			}
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::SESSION_GONE === $e->reason && empty( $job->cursor['restarted'] ) ) {
				unset( $job->cursor['session'] );
				$job->cursor['restarted'] = true;
			} else {
				return $this->failed( $job, $plan, $index, $profile, $backup['name'], $e, $destination );
			}
		}

		/* translators: 1: destination name, 2: bytes sent, 3: backup size */
		return StepResult::more( self::progress( $job, $plan, $index ), sprintf( __( 'Uploading to %1$s: %2$s of %3$s…', 'lw-migration' ), $profile->name, size_format( self::offset( $job ) ), size_format( $backup['size'] ) ) );
	}

	/**
	 * Starts the upload, sends one part, or finishes it.
	 *
	 * @param Job                                          $job         Job.
	 * @param DestinationInterface                         $destination Destination.
	 * @param Profile                                      $profile     Its profile.
	 * @param array{name: string, path: string, size: int} $backup      The backup.
	 * @return RemoteFile|null The finished file, or null when there is more to do.
	 * @throws PermanentFailure INVALID for a status answer that is not this upload's, SESSION_GONE for one below what was confirmed; any CloudFailure of the destination passes through.
	 */
	private function advance( Job $job, DestinationInterface $destination, Profile $profile, array $backup ): ?RemoteFile {
		$session = UploadCursor::session( $job, $profile );
		if ( null === $session || $session->name !== $backup['name'] || $session->size !== $backup['size'] ) {
			CloudCopies::mark( $backup['name'], $profile->id, CloudCopies::PENDING, $this->now() );
			UploadCursor::start( $job, $profile, $destination->startUpload( $backup['name'], $backup['size'] ) );
			ResumeCheck::progressed( $job );
			return null;
		}
		if ( ! $session->isComplete() && $destination instanceof ResumesUpload && ResumeCheck::askFirst( $job ) ) {
			$answer = $destination->resume( $session );
			if ( ! ResumeCheck::fits( $session, $answer ) ) {
				/* translators: %s: destination name */
				throw new PermanentFailure( sprintf( __( '%s answered that it has more of this upload than was sent, or another upload, so the backup was not uploaded there.', 'lw-migration' ), $profile->name ), PermanentFailure::INVALID );
			}
			if ( ResumeCheck::lost( $session, $answer ) ) {
				/* translators: %s: destination name */
				throw new PermanentFailure( sprintf( __( '%s lost part of this upload; the upload starts over.', 'lw-migration' ), $profile->name ), PermanentFailure::SESSION_GONE );
			}
			$job->cursor['session'] = $answer->toArray();
			ResumeCheck::asked( $job );
			return null;
		}
		if ( ! $session->isComplete() ) {
			$next                   = $destination instanceof FileUpload
				? $destination->uploadFrom( $session, $backup['path'] )
				: $destination->uploadChunk( $session, self::read( $backup, $session->offset, $session->nextLength() ) );
			$job->cursor['session'] = $next->toArray();
			ResumeCheck::progressed( $job );
			/**
			 * Fires after a part of a backup reached a destination. Internal:
			 * a test hook (the cloud E2E logs and cuts uploads with it), not a
			 * public API; it may change or go without notice.
			 *
			 * @internal
			 *
			 * @param string $destinationId Profile id.
			 * @param int    $offset        Bytes the destination has now.
			 * @param int    $size          Backup size.
			 */
			do_action( 'lw_migration_cloud_part', $profile->id, $next->offset, $next->size );
			return null;
		}

		return $destination->finishUpload( $session );
	}

	/**
	 * @param Job        $job     Job.
	 * @param CloudPlan  $plan    Plan.
	 * @param int        $index   Destination position.
	 * @param Profile    $profile Its profile.
	 * @param string     $name    Backup name.
	 * @param RemoteFile $file   The uploaded file.
	 */
	private function succeeded( Job $job, CloudPlan $plan, int $index, Profile $profile, string $name, RemoteFile $file ): StepResult {
		$now = $this->now();
		CloudOutcome::record( $job, $profile->id, CloudOutcome::OK, $profile->name, $profile->type, '', $file->ref, $now );
		CloudCopies::mark( $name, $profile->id, CloudCopies::UP, $now );
		$this->destinations->profiles()->mark( $profile->id, Profile::OK, '', $now );

		return $this->next( $job, $plan, $index );
	}

	/**
	 * @param Job                       $job         Job.
	 * @param CloudPlan                 $plan        Plan.
	 * @param int                       $index       Destination position.
	 * @param Profile                   $profile     Its profile.
	 * @param string                    $name        Backup name.
	 * @param CloudFailure              $e           Why.
	 * @param DestinationInterface|null $destination The opened destination (null: it did not open).
	 */
	private function failed( Job $job, CloudPlan $plan, int $index, Profile $profile, string $name, CloudFailure $e, ?DestinationInterface $destination ): StepResult {
		$session = UploadCursor::session( $job, $profile );
		if ( null !== $destination && null !== $session ) {
			try {
				$destination->abort( $session );
			} catch ( CloudFailure $ignored ) {
				unset( $ignored ); // Best effort; most providers expire unfinished uploads themselves.
			}
		}
		CloudOutcome::record( $job, $profile->id, CloudOutcome::FAILED, $profile->name, $profile->type, $e->getMessage(), '', $this->now() );
		CloudCopies::mark( $name, $profile->id, CloudCopies::FAILED, $this->now() );
		if ( PermanentFailure::REAUTH !== $e->reason ) {
			$this->destinations->profiles()->mark( $profile->id, Profile::ERROR, $e->getMessage() );
		} elseif ( $this->destinations->profiles()->revoked( $profile->id, $e->getMessage(), $profile->connection, null !== $this->reauth && $this->reauth->due( $job ) ) && null !== $this->reauth ) {
			$this->reauth->notify( $job, $profile );
		}
		/* translators: 1: destination name, 2: reason */
		$job->addWarning( sprintf( __( 'The upload to %1$s failed: %2$s', 'lw-migration' ), $profile->name, $e->getMessage() ) );

		return $this->next( $job, $plan, $index );
	}

	/**
	 * Two requests died while working on the same part (plan D27): this
	 * destination fails with advice, the others go on. Only the first such
	 * start tries the abort (it opens a connection, which may be what dies);
	 * a later one just records the failure (preflight P-M7).
	 *
	 * @param Job       $job     Job.
	 * @param CloudPlan $plan    Plan.
	 * @param int       $index   Destination position.
	 * @param Profile   $profile Its profile.
	 * @param string    $name    Backup name.
	 * @param bool      $abort   Try the abort (ResumeCheck::CUT_ABORT).
	 */
	private function cutOff( Job $job, CloudPlan $plan, int $index, Profile $profile, string $name, bool $abort ): StepResult {
		$destination = null;
		if ( $abort ) {
			try {
				$destination = $this->destinations->open( $profile );
			} catch ( CloudFailure $ignored ) {
				unset( $ignored ); // Only needed for the best-effort abort.
			}
		}
		/* translators: 1: destination name, 2: backup file name, 3: destination ID */
		$text = sprintf( __( 'The request was cut off twice while uploading to %1$s (most likely the web server\'s time limit). Upload this backup from the command line: wp lw-migration upload %2$s --to=%3$s', 'lw-migration' ), $profile->name, $name, $profile->id );

		return $this->failed( $job, $plan, $index, $profile, $name, new PermanentFailure( $text, PermanentFailure::CUT_OFF ), $destination );
	}

	private function next( Job $job, CloudPlan $plan, int $index ): StepResult {
		$job->cursor = [ 'index' => $index + 1 ];

		return isset( $plan->targets[ $index + 1 ] )
			? StepResult::more( self::progress( $job, $plan, $index + 1 ) )
			: StepResult::done( self::summary( $job, $plan ) );
	}

	/**
	 * @param array{name: string, path: string, size: int} $backup The backup.
	 * @param int                                          $offset First byte.
	 * @param int                                          $length Bytes.
	 * @throws CloudJobException When the bytes cannot be read.
	 */
	private static function read( array $backup, int $offset, int $length ): string {
		$in = fopen( $backup['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming our own backup in parts.
		if ( false === $in ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'The backup %s cannot be read.', 'lw-migration' ), $backup['name'] ) );
		}
		try {
			$bytes = (string) stream_get_contents( $in, $length, $offset );
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
		}
		if ( strlen( $bytes ) !== $length ) {
			/* translators: %s: backup file name */
			throw new CloudJobException( sprintf( __( 'The backup %s changed while it was uploaded.', 'lw-migration' ), $backup['name'] ) );
		}

		return $bytes;
	}

	private static function offset( Job $job ): int {
		return (int) ( $job->cursor['session']['offset'] ?? 0 );
	}

	private static function progress( Job $job, CloudPlan $plan, int $index ): int {
		$size = (int) ( $job->cursor['session']['size'] ?? 0 );
		$part = $size > 0 ? self::offset( $job ) / $size : 0.0;

		return (int) floor( 100 * ( $index + $part ) / max( 1, count( $plan->targets ) ) );
	}

	private static function summary( Job $job, ?CloudPlan $plan ): string {
		if ( null === $plan ) {
			return '';
		}
		$ok = count( array_filter( $plan->ids(), static fn( string $id ): bool => CloudOutcome::OK === ( CloudOutcome::get( $job, $id )['status'] ?? null ) ) );

		/* translators: 1: destinations with the backup, 2: all destinations */
		return sprintf( __( 'Uploaded to %1$d of %2$d destinations.', 'lw-migration' ), $ok, count( $plan->targets ) );
	}

	private function now(): int {
		return (int) ( $this->now )();
	}
}
