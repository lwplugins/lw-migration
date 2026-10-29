<?php
/**
 * The write limit of an import's extraction, kept in the job state.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * The free disk space is read once, when the import is about to extract its
 * first byte (after the download and the safety backup took theirs), and the
 * limit and the running total are kept in state.extractBudget like a cursor,
 * so every later request and step (files, database, password trial) counts
 * against the same total. When no folder tells its free space, the limit is
 * the filter `lw_migration_max_extract_bytes` (int bytes, default 50 GiB).
 */
final class ExtractBudget {

	public const STATE = 'extractBudget';

	/** The filter forFreeSpace() applies (named literally there). */
	public const FILTER = 'lw_migration_max_extract_bytes';

	/**
	 * The saved budget, or a new one from the smallest free space of the folders (saved at once).
	 *
	 * @param Job                $job       Import job.
	 * @param array<int, string> $dirs      Folders the import writes to.
	 * @param \Closure           $freeSpace fn( string $dir ): ?int.
	 */
	public static function load( Job $job, array $dirs, \Closure $freeSpace ): WriteBudget {
		$saved = $job->state[ self::STATE ] ?? null;
		if ( is_array( $saved ) ) {
			return WriteBudget::fromArray( $saved );
		}
		$free = null;
		foreach ( $dirs as $dir ) {
			$bytes = $freeSpace( $dir );
			if ( null !== $bytes ) {
				$free = null === $free ? (int) $bytes : min( $free, (int) $bytes );
			}
		}
		$budget = self::forFreeSpace( $free );
		self::save( $job, $budget );

		return $budget;
	}

	/**
	 * @param int|null $free Free bytes, null when unknown.
	 */
	public static function forFreeSpace( ?int $free ): WriteBudget {
		return WriteBudget::forFreeSpace( $free, (int) apply_filters( 'lw_migration_max_extract_bytes', WriteBudget::UNKNOWN_CEILING ) );
	}

	public static function save( Job $job, WriteBudget $budget ): void {
		$job->state[ self::STATE ] = $budget->toArray();
	}

	/**
	 * What an import reports when the limit is reached (a WriteLimitException).
	 *
	 * @param WriteBudget $budget Budget that refused the write.
	 */
	public static function stopMessage( WriteBudget $budget ): string {
		/* translators: %s: size the import may write, e.g. "12 GB" */
		$message = __( 'The import stopped: the archive holds more data than the %s the free disk space allows it to write. Free up disk space and try again.', 'lw-migration' );

		return sprintf( $message, (string) size_format( (int) $budget->limit() ) );
	}
}
