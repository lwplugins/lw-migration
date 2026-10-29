<?php
/**
 * Runs a job to the end from a command.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\WordPress\SiteLock;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by export and import: refresh the lock before each tick, report, fail
 * loudly. Ctrl-C stops the drive with an InterruptedException (the command
 * cancels the job; Interrupt explains the case without pcntl); a lost site
 * lock or a busy job with a JobException (the command fails the job). Ctrl-C
 * after an import's database swap started does not stop the drive: the cancel
 * is deferred (Runner::cancel) and the remaining steps run to the end; a
 * second Ctrl-C kills the process (the signal handlers are back), the site
 * lock then expires and a later run of the job continues it.
 */
final class Drive {

	/**
	 * @param Services      $services Services.
	 * @param string        $id       Job id.
	 * @param SecretStore   $secrets  Secrets of this process.
	 * @param \Closure|null $onInput  Input callback; an exception it throws leaves the drive (the command stops the job).
	 * @param \Closure|null $onEnd    fn( Job $job ): void, called for a job that failed or was cancelled, before the error.
	 * @return Job Done job.
	 * @throws InterruptedException On Ctrl-C (or SIGTERM/SIGHUP) between ticks.
	 * @throws JobException When the drive stops before the job ends (lock lost, job busy).
	 */
	public static function run( Services $services, string $id, SecretStore $secrets, ?\Closure $onInput = null, ?\Closure $onEnd = null ): Job {
		$runner    = $services->runner( $services->jobs()->load( $id )->type, $secrets );
		$interrupt = new Interrupt();
		$driver    = new CliDriver(
			$runner,
			new WpCliOutput(),
			self::beforeTick( $services->lock(), $interrupt, self::deferredCancel( $runner, $services->jobs(), static fn( string $line ) => Console::log( $line ) ) ),
			/**
			 * Seconds of work per tick (spec 8: about 10 s, filterable).
			 *
			 * @param float $seconds Tick length.
			 */
			max( 1.0, (float) apply_filters( 'lw_migration_tick_seconds', 10.0 ) )
		);
		$interrupt->trap();
		try {
			$job = $driver->run( $id, $onInput );
		} finally {
			$interrupt->restore();
		}
		if ( Job::DONE !== $job->status ) {
			if ( null !== $onEnd ) {
				$onEnd( $job );
			}
			Console::error( self::endText( $job ) );
		}

		return $job;
	}

	/**
	 * Why a drive ended without a done job: the error, that it was
	 * cancelled, or (a cancel past the point of no return was only
	 * recorded) that it goes on.
	 *
	 * @param Job $job Job the drive ended with.
	 */
	public static function endText( Job $job ): string {
		if ( '' !== $job->error ) {
			return $job->error;
		}
		if ( ! $job->isFinished() ) {
			return __( 'The job was not cancelled: it is past its point of no return and finishes when it is driven again.', 'lw-migration' );
		}

		return __( 'The job was cancelled.', 'lw-migration' );
	}

	/**
	 * Ends a job the drive left unfinished: an interrupt cancels it, anything
	 * else fails it with the reason in its error and job.log. Either way the
	 * finish hook removes the work files and releases the site lock when the
	 * job still holds it; the notices it adds are printed. Best effort: a job
	 * that stays busy keeps its lock until the TTL ends.
	 *
	 * @param Services          $services Services.
	 * @param string            $id       Job id.
	 * @param SecretStore       $secrets  Secrets of this process.
	 * @param \RuntimeException $reason   What stopped the drive.
	 * @param \Closure|null     $onEnd    fn( Job $job ): void, called with the ended job.
	 * @return string Message for the user (the redacted job error when there is one).
	 */
	public static function stop( Services $services, string $id, SecretStore $secrets, \RuntimeException $reason, ?\Closure $onEnd = null ): string {
		try {
			$before = $services->jobs()->load( $id ); // Its warnings were printed after the last tick.
			$runner = $services->runner( $before->type, $secrets );
			$job    = $reason instanceof InterruptedException ? $runner->cancel( $id, time() ) : $runner->fail( $id, $reason->getMessage(), time() );
			foreach ( array_slice( $job->warnings, count( $before->warnings ) ) as $warning ) {
				Console::warning( $warning );
			}
			if ( ! $job->isFinished() ) { // A deferred cancel: the job keeps its lock until the TTL ends.
				return self::notCancelled( $job->type );
			}
			if ( null !== $onEnd ) {
				$onEnd( $job );
			}
			if ( ! $reason instanceof InterruptedException && Job::FAILED === $job->status && '' !== $job->error ) {
				return $job->error;
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The error the user needs is the one that stopped the drive.
		}

		return $reason->getMessage();
	}

	/**
	 * Runs before every tick: stops on a signal (unless $goesOn says the job
	 * finishes instead), extends the site lock, and stops when this job no
	 * longer holds it (another job may be running on the site).
	 *
	 * @param SiteLock      $lock      Site lock.
	 * @param Interrupt     $interrupt Ctrl-C state.
	 * @param \Closure|null $goesOn    fn( string $jobId ): bool, true when the job runs on after the signal (deferredCancel()).
	 * @return \Closure fn( string $jobId ): void
	 */
	public static function beforeTick( SiteLock $lock, Interrupt $interrupt, ?\Closure $goesOn = null ): \Closure {
		return static function ( string $jobId ) use ( $lock, $interrupt, $goesOn ): void {
			if ( $interrupt->requested() && ( null === $goesOn || ! $goesOn( $jobId ) ) ) {
				throw new InterruptedException( __( 'Interrupted. The job was cancelled.', 'lw-migration' ) );
			}
			if ( ! $lock->refresh( $jobId, time() ) ) {
				throw new JobException( __( 'This job lost the site lock (it was not driven for too long, or another job took over the site). The job was stopped.', 'lw-migration' ) );
			}
		};
	}

	/**
	 * For beforeTick(): a job past its point of no return is not cancelled. The
	 * first time, the cancel is recorded and the user told; afterwards the drive
	 * just goes on. Any other job returns false (the command cancels it).
	 *
	 * @param Runner   $runner Runner of the job.
	 * @param JobStore $jobs   Job store.
	 * @param \Closure $say    fn( string $line ): void.
	 * @return \Closure fn( string $jobId ): bool
	 */
	public static function deferredCancel( Runner $runner, JobStore $jobs, \Closure $say ): \Closure {
		$deferred = false;

		return static function ( string $jobId ) use ( $runner, $jobs, $say, &$deferred ): bool {
			$job = $deferred ? null : $jobs->load( $jobId );
			if ( null !== $job && $runner->defersCancel( $job ) ) {
				$runner->cancel( $jobId, time() );
				$say(
					PushJobs::TYPE === $job->type
						? __( 'The other site may already be replacing its content; finishing the remaining steps (this takes a moment)…', 'lw-migration' )
						: __( 'The database is already replaced; finishing the remaining steps (this takes a moment)…', 'lw-migration' )
				);
				$deferred = true;
			}

			return $deferred;
		};
	}

	/**
	 * Why a cancel was only recorded: past its point of no return a restore
	 * or a database reset here has replaced the database; a push has had the
	 * other site's summary confirmed, so the other site may already be
	 * replacing its content.
	 *
	 * @param string $type Job type.
	 */
	public static function notCancelled( string $type ): string {
		if ( PushJobs::TYPE === $type ) {
			return __( 'The other site may already be replacing its content, so sending was not cancelled; its remaining steps did not finish yet.', 'lw-migration' );
		}
		if ( ResetJobs::TYPE === $type ) {
			return __( 'The database is already replaced, so the reset was not cancelled; its remaining steps did not finish yet.', 'lw-migration' );
		}

		return __( 'The database is already replaced, so the import was not cancelled; its remaining steps did not finish yet.', 'lw-migration' );
	}
}
