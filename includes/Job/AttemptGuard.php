<?php
/**
 * Stops a job step that keeps starting from the same place.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: counts starts of the current step from the same (step, cursor) in
 * $job->attempt. A step that makes no progress, or a request that keeps dying
 * inside it, reaches MAX_ATTEMPTS and the job is stopped.
 */
final class AttemptGuard {

	/** Starts of one step from the same cursor before the job is stopped. */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Records one more start of the current step.
	 *
	 * @param Job    $job Job.
	 * @param string $key Step key (for the message).
	 * @throws JobException After MAX_ATTEMPTS starts without progress.
	 * @return void
	 */
	public static function record( Job $job, string $key ): void {
		$print = sha1( $job->stepIndex . '|' . (string) json_encode( $job->cursor, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
		$count = ( $job->attempt['print'] ?? '' ) === $print ? (int) ( $job->attempt['count'] ?? 0 ) + 1 : 1;
		if ( $count > self::MAX_ATTEMPTS ) {
			throw new JobException( sprintf( 'The step "%1$s" started %2$d times without making progress (it may hit a time or memory limit); the job was stopped.', $key, self::MAX_ATTEMPTS ) );
		}
		$job->attempt = [
			'print' => $print,
			'count' => $count,
		];
	}
}
