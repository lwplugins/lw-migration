<?php
/**
 * Drives a job from the command line.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Support\TimeDeadline;

defined( 'ABSPATH' ) || exit;

/**
 * Ticks in short slices (progress is saved, the site lock refreshed), so any
 * other driver can take over a job a CLI process left behind.
 */
final class CliDriver {

	/** One-second waits while another process holds the job lock. */
	private const BUSY_LIMIT = 30;

	/** Percentage points the progress moves before the same message is printed again. */
	private const PROGRESS_STEP = 5;

	/**
	 * @param Runner   $runner     Runner for the job type.
	 * @param Output   $out        Output.
	 * @param \Closure $beforeTick fn( string $jobId ): void — e.g. refresh the site lock; an exception stops the drive before the tick.
	 * @param float    $seconds    Tick length.
	 */
	public function __construct( private Runner $runner, private Output $out, private \Closure $beforeTick, private float $seconds = 10.0 ) {
	}

	/**
	 * @param string        $id      Job id.
	 * @param \Closure|null $onInput fn( Job $job ): ?array — input for a waiting job; null cancels it.
	 * @return Job The finished job.
	 * @throws JobException When another process keeps the job busy (or the before-tick hook stops the drive).
	 */
	public function run( string $id, ?\Closure $onInput = null ): Job {
		$printed = [
			'message'  => '',
			'progress' => 0,
		];
		$warned  = 0;
		$busy    = 0;
		while ( true ) {
			( $this->beforeTick )( $id );
			$started = time();
			$job     = $this->runner->tick( $id, new TimeDeadline( $this->seconds ), $started );
			$this->report( $job, $printed, $warned );
			if ( $job->isFinished() ) {
				return $job;
			}
			if ( Job::NEEDS_INPUT === $job->status ) {
				$input = null === $onInput ? null : $onInput( $job );
				if ( null === $input ) {
					$job = $this->runner->cancel( $id, time() );
					$this->report( $job, $printed, $warned ); // Notices of the finish hook.
					if ( ! $job->isFinished() ) { // Past its point of no return: the cancel was only recorded.
						$this->out->line( Drive::endText( $job ) );
					}
					return $job;
				}
				$this->runner->provideInput( $id, $input, time() );
				continue;
			}
			if ( $job->updatedAt < $started ) {
				if ( ++$busy > self::BUSY_LIMIT ) {
					throw new JobException( __( 'Another process is running this job.', 'lw-migration' ) );
				}
				sleep( 1 );
				continue;
			}
			$busy = 0;
		}
	}

	/**
	 * Prints the message (with progress while running) when it changed or the
	 * progress moved PROGRESS_STEP points since the last line, and new warnings.
	 *
	 * @param Job                                   $job     Job after the tick.
	 * @param array{message: string, progress: int} $printed Last printed message and progress.
	 * @param int                                   $warned  Warnings printed so far.
	 * @return void
	 */
	private function report( Job $job, array &$printed, int &$warned ): void {
		$running = $job->progress > 0 && $job->progress < 100 && ! $job->isFinished();
		$changed = $job->message !== $printed['message'];
		$moved   = $running && abs( $job->progress - $printed['progress'] ) >= self::PROGRESS_STEP;
		if ( '' !== $job->message && ( $changed || $moved ) ) {
			$this->out->line( $job->message . ( $running ? sprintf( ' (%d%%)', $job->progress ) : '' ) );
			$printed = [
				'message'  => $job->message,
				'progress' => $job->progress,
			];
		}
		foreach ( array_slice( $job->warnings, $warned ) as $warning ) {
			$this->out->warning( $warning );
		}
		$warned = count( $job->warnings );
	}
}
