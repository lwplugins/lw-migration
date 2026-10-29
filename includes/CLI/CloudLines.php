<?php
/**
 * What a job did at each destination, as command-line lines.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;

defined( 'ABSPATH' ) || exit;

/**
 * One line per destination: a success as a log line, a failure or a skip as
 * a warning with its reason (the reasons carry no secret).
 */
final class CloudLines {

	/**
	 * @param Job $job Export or cloud job.
	 * @return array<int, array{0: string, 1: string}> [log|warning, text].
	 */
	public static function of( Job $job ): array {
		$rows = [];
		foreach ( CloudOutcome::view( $job ) as $row ) {
			$rows[] = [
				'name'   => '' !== $row['name'] ? $row['name'] : $row['id'],
				'status' => $row['status'],
				'error'  => $row['message'],
			];
		}

		return self::ofLog( $rows );
	}

	/**
	 * The same lines for the rows of a schedule's run log.
	 *
	 * @param array<int, array{name: string, status: string, error: string}> $rows Rows {name, status, error}.
	 * @return array<int, array{0: string, 1: string}> [log|warning, text].
	 */
	public static function ofLog( array $rows ): array {
		$lines = [];
		foreach ( $rows as $row ) {
			if ( CloudOutcome::OK === $row['status'] ) {
				/* translators: %s: destination name */
				$lines[] = [ 'log', sprintf( __( 'Uploaded to %s.', 'lw-migration' ), $row['name'] ) ];
			} elseif ( CloudOutcome::SKIPPED === $row['status'] ) {
				/* translators: 1: destination name or ID, 2: reason */
				$lines[] = [ 'warning', sprintf( __( 'Skipped %1$s: %2$s', 'lw-migration' ), $row['name'], $row['error'] ) ];
			} elseif ( CloudOutcome::FAILED !== $row['status'] ) { // Still pending when the job ended.
				/* translators: %s: destination name or ID */
				$lines[] = [ 'warning', sprintf( __( 'Not uploaded to %s.', 'lw-migration' ), $row['name'] ) ];
			} else {
				/* translators: 1: destination name, 2: reason */
				$lines[] = [ 'warning', sprintf( __( 'Upload to %1$s failed: %2$s', 'lw-migration' ), $row['name'], $row['error'] ) ];
			}
		}

		return $lines;
	}

	public static function print( Job $job ): void {
		self::emit( self::of( $job ) );
	}

	/**
	 * Prints the lines and, when not every upload succeeded, the summary.
	 *
	 * @param Job $job Export or cloud job.
	 * @return bool Whether the result is partial (the backup exists, an upload did not succeed).
	 */
	public static function conclude( Job $job ): bool {
		self::print( $job );
		$partial = CloudOutcome::partial( $job );
		if ( $partial ) {
			Console::warning( __( 'The backup is ready, but not every upload succeeded.', 'lw-migration' ) );
		}

		return $partial;
	}

	/**
	 * @param array<int, array{name: string, status: string, error: string}> $rows Rows of a run log entry.
	 */
	public static function printLog( array $rows ): void {
		self::emit( self::ofLog( $rows ) );
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $lines [log|warning, text].
	 */
	private static function emit( array $lines ): void {
		foreach ( $lines as [ $kind, $text ] ) {
			if ( 'log' === $kind ) {
				Console::log( $text );
			} else {
				Console::warning( $text );
			}
		}
	}
}
