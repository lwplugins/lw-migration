<?php
/**
 * Advances a job by running its steps until the deadline.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * One tick = lock, load, run steps, save, unlock. A busy lock returns the saved
 * job unchanged; any throwable marks the job failed. A step that returns
 * "more" before the deadline is called again in the same tick (one that asks
 * for the next request ends it: StepResult::nextRequest()). A step started
 * MAX_ATTEMPTS times from the same cursor stops the job (AttemptGuard). A job
 * that ends is saved first; then its secrets are dropped and the finish hook
 * runs, both best effort. A job past its point of no return (an import whose
 * database swap started) is never cancelled: the cancel is recorded and the
 * job runs to its end.
 */
final class Runner {

	/** Starts of one step from the same cursor before the job is stopped. */
	public const MAX_ATTEMPTS = AttemptGuard::MAX_ATTEMPTS;

	/** Input keys kept in the SecretStore, never in the job files. */
	public const SECRET_KEYS = [ 'password' ];

	/** Non-secret input keys that may be saved in $job->state['input']; any other key is refused. */
	public const INPUT_KEYS = [ 'confirm', 'safetyBackup', 'upload', 'sites' ];

	/** State flag: a cancel arrived after the point of no return; the job finishes instead. */
	public const CANCEL_DEFERRED = 'cancelDeferred';

	/**
	 * Where passwords live while a job runs.
	 *
	 * @var SecretStore
	 */
	private SecretStore $secrets;

	/**
	 * Error message and job.log writer.
	 *
	 * @var FailureReporter
	 */
	private FailureReporter $reporter;

	/**
	 * Called once when a job is done, failed or cancelled.
	 *
	 * @var callable|null
	 */
	private $onFinish;

	/**
	 * Whether a job is past its point of no return: fn( Job $job ): bool.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $pastNoReturn;

	/**
	 * @param JobStore                     $store        Job store.
	 * @param array<string, StepInterface> $steps        Step implementations by key.
	 * @param SecretStore|null             $secrets      Secret store (default: in memory).
	 * @param array<int, string>           $redactPaths  Absolute paths removed from failure messages.
	 * @param callable|null                $onFinish     fn( Job $job ): void, after the final save.
	 * @param \Closure|null                $pastNoReturn fn( Job $job ): bool, true when a cancel must not stop the job.
	 */
	public function __construct( private JobStore $store, private array $steps, ?SecretStore $secrets = null, array $redactPaths = [], ?callable $onFinish = null, ?\Closure $pastNoReturn = null ) {
		$this->secrets      = $secrets ?? new MemorySecretStore();
		$this->reporter     = new FailureReporter( $store, $redactPaths );
		$this->onFinish     = $onFinish;
		$this->pastNoReturn = $pastNoReturn;
	}

	public function tick( string $id, Deadline $deadline, int $now ): Job {
		$lock = $this->store->acquire( $id );
		if ( null === $lock ) {
			return $this->store->load( $id );
		}
		try {
			$job = $this->store->load( $id );
			if ( $job->isFinished() || Job::NEEDS_INPUT === $job->status ) {
				return $job;
			}
			$this->advance( $job, $deadline, $now );
			$this->store->save( $job );
			if ( $job->isFinished() ) {
				$this->finish( $job, $now );
			}
		} finally {
			$this->store->release( $lock );
		}

		return $job;
	}

	/**
	 * @param string               $id    Job id.
	 * @param array<string, mixed> $input User input (password, confirm ...).
	 * @param int                  $now   Unix time.
	 * @return Job
	 * @throws JobException When the input holds a key that is neither a secret nor allowed input, or another process holds the job (JobBusyException).
	 */
	public function provideInput( string $id, array $input, int $now ): Job {
		if ( [] !== array_diff_key( $input, array_flip( array_merge( self::SECRET_KEYS, self::INPUT_KEYS ) ) ) ) {
			throw new JobException( 'Unknown input.' );
		}

		return $this->update(
			$id,
			$now,
			function ( Job $job ) use ( $input ): void {
				if ( Job::NEEDS_INPUT !== $job->status ) {
					return;
				}
				$secret = array_intersect_key( $input, array_flip( self::SECRET_KEYS ) );
				if ( [] !== $secret ) {
					$this->secrets->put( $job->id, array_merge( $this->secrets->get( $job->id ), $secret ) );
				}
				foreach ( array_diff_key( $input, $secret ) as $key => $value ) {
					$job->state['input'][ (string) $key ] = $value;
				}
				$job->input   = null;
				$job->status  = Job::RUNNING;
				$job->attempt = [];
			}
		);
	}

	/**
	 * Fails an unfinished job for a reason found outside its steps; the reason
	 * goes to the job's error and job.log like a step failure.
	 *
	 * @param string $id      Job id.
	 * @param string $message Reason, shown to the user.
	 * @param int    $now     Unix time.
	 * @return Job
	 * @throws JobBusyException When another process holds the job.
	 */
	public function fail( string $id, string $message, int $now ): Job {
		return $this->update(
			$id,
			$now,
			function ( Job $job ) use ( $message, $now ): void {
				if ( ! $job->isFinished() ) {
					$job->secrets = $this->secrets->get( $job->id );
					$this->reporter->stop( $job, $message, $now );
				}
			}
		);
	}

	/**
	 * Cancels an unfinished job. A job past its point of no return is not
	 * cancelled: the request is recorded (state.cancelDeferred), its status and
	 * site lock stay, and the next ticks finish its remaining steps. Every
	 * caller (the CLI's Ctrl-C, the REST cancel route in M2c) gets the job back
	 * still running and must say so instead of reporting a cancel.
	 *
	 * @param string $id  Job id.
	 * @param int    $now Unix time.
	 * @return Job
	 * @throws JobBusyException When another process holds the job.
	 */
	public function cancel( string $id, int $now ): Job {
		return $this->update(
			$id,
			$now,
			function ( Job $job ): void {
				if ( $this->defersCancel( $job ) ) {
					$job->state[ self::CANCEL_DEFERRED ] = true;
				} elseif ( ! $job->isFinished() ) {
					$job->status = Job::CANCELLED;
				}
			}
		);
	}

	/**
	 * Whether cancel() would only record the request for this job.
	 *
	 * @param Job $job Saved job.
	 */
	public function defersCancel( Job $job ): bool {
		return ! $job->isFinished() && null !== $this->pastNoReturn && ( $this->pastNoReturn )( $job );
	}

	/**
	 * Whether a cancel of this job was deferred.
	 *
	 * @param Job $job Job.
	 */
	public static function cancelDeferred( Job $job ): bool {
		return ! empty( $job->state[ self::CANCEL_DEFERRED ] );
	}

	/**
	 * Runs the steps; a throwable fails the job instead of leaving the tick.
	 *
	 * @param Job      $job      Job.
	 * @param Deadline $deadline Deadline.
	 * @param int      $now      Unix time.
	 * @return void
	 */
	private function advance( Job $job, Deadline $deadline, int $now ): void {
		try {
			$job->secrets = $this->secrets->get( $job->id );
			$job->status  = Job::RUNNING;
			$this->runSteps( $job, $deadline, $now );
		} catch ( \Throwable $e ) {
			$this->reporter->fail( $job, $e, $now );
		}
		$job->updatedAt = $now;
		$this->reporter->flushWarnings( $job, $now );
	}

	private function runSteps( Job $job, Deadline $deadline, int $now ): void {
		$key = $job->currentStep();
		while ( null !== $key ) {
			if ( ! isset( $this->steps[ $key ] ) ) {
				throw new JobException( sprintf( 'Unknown job step: %s', $key ) );
			}
			$this->countAttempt( $job, $key, $now );
			$result = $this->steps[ $key ]->run( $job, $deadline );
			if ( '' !== $result->message() ) {
				$job->message = $result->message();
			}
			$kind = $result->needsInputKind();
			if ( null !== $kind ) {
				$this->askFor( $job, $kind, $result->inputData() );
				return;
			}
			if ( ! $result->isDone() ) {
				$job->progress = (int) $result->progress();
				if ( $result->endsTick() || $deadline->reached() ) {
					return;
				}
				continue;
			}
			++$job->stepIndex;
			$job->cursor   = [];
			$job->progress = 0;
			$job->attempt  = [];
			$key           = $job->currentStep();
			if ( null !== $key && $deadline->reached() ) {
				return;
			}
		}
		$job->status   = Job::DONE;
		$job->progress = 100;
	}

	/**
	 * Saved before every step call: the count survives a request that dies
	 * inside the step, and a re-run after "more" (e.g. a step that stopped at
	 * a table boundary) starts from its persisted cursor. The warnings of the
	 * previous call go to job.log at the same time.
	 *
	 * @param Job    $job Job.
	 * @param string $key Step key.
	 * @param int    $now Unix time.
	 * @return void
	 */
	private function countAttempt( Job $job, string $key, int $now ): void {
		AttemptGuard::record( $job, $key );
		$this->store->save( $job );
		$this->reporter->flushWarnings( $job, $now );
	}

	/**
	 * @param Job                  $job  Job.
	 * @param string               $kind Input kind.
	 * @param array<string, mixed> $data Details for the UI.
	 * @return void
	 */
	private function askFor( Job $job, string $kind, array $data ): void {
		$job->status = Job::NEEDS_INPUT;
		$job->input  = [
			'kind' => $kind,
			'data' => $data,
		];
		// A step asking again means the value it got was wrong: drop it.
		$secrets = $this->secrets->get( $job->id );
		if ( array_key_exists( $kind, $secrets ) ) {
			unset( $secrets[ $kind ] );
			$this->secrets->put( $job->id, $secrets );
		}
		unset( $job->state['input'][ $kind ] );
	}

	/**
	 * Drops the secrets and runs the finish hook of a saved, ended job. Both
	 * are best effort: a failure is logged, the job stays as saved.
	 *
	 * @param Job $job Ended job.
	 * @param int $now Unix time.
	 * @return void
	 */
	private function finish( Job $job, int $now ): void {
		try {
			$this->secrets->delete( $job->id );
		} catch ( \Throwable $e ) {
			$this->reporter->note( $job, $now, 'Cannot delete the job secrets: ' . $e->getMessage() );
		}
		if ( null === $this->onFinish ) {
			return;
		}
		try {
			( $this->onFinish )( $job );
		} catch ( \Throwable $e ) {
			$this->reporter->note( $job, $now, 'Cleanup after the job failed: ' . $e->getMessage() );
		}
	}

	private function update( string $id, int $now, callable $change ): Job {
		$lock = $this->store->acquire( $id );
		if ( null === $lock ) {
			throw new JobBusyException( 'The job is busy; try again.' );
		}
		try {
			$job      = $this->store->load( $id );
			$finished = $job->isFinished();
			$change( $job );
			$job->updatedAt = $now;
			$this->store->save( $job );
			if ( ! $finished && $job->isFinished() ) {
				$this->finish( $job, $now );
			}
		} finally {
			$this->store->release( $lock );
		}

		return $job;
	}
}
