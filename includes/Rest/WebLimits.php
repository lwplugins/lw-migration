<?php
/**
 * Limits of a browser-driven request.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Support\Memory;

defined( 'ABSPATH' ) || exit;

/**
 * Web requests run under max_execution_time, post_max_size, memory_limit and
 * proxy timeouts that WP-CLI never meets: each limit here is filterable or
 * read from PHP and stays well inside them.
 */
final class WebLimits {

	/** Seconds of work per browser tick (filter `lw_migration_web_tick_seconds`). */
	public const TICK_SECONDS = 8.0;

	/** Largest upload chunk. */
	public const MAX_CHUNK = 8388608;

	/** Largest download without byte ranges in one request (filter `lw_migration_web_whole_download_bytes`). */
	public const WHOLE_DOWNLOAD = 209715200;

	/** Smallest upload chunk (unless post_max_size leaves less). */
	public const MIN_CHUNK = 262144;

	/** Room left in the request body for headers and form overhead. */
	private const BODY_MARGIN = 65536;

	/** Memory a REST request needs before the chunk (WordPress, plugins, our classes). */
	private const MEMORY_BASELINE = 50331648;

	/** A request body is held in memory about three times (raw input, request, our copy). */
	private const MEMORY_SHARE = 4;

	/** Seconds of work per tick: the filter's value (a non-finite one is ignored), at most half of max_execution_time, at least one. */
	public static function tickSeconds(): float {
		/**
		 * Seconds of work per browser tick.
		 *
		 * @param float $seconds Tick length.
		 */
		$seconds = (float) apply_filters( 'lw_migration_web_tick_seconds', self::TICK_SECONDS );
		if ( ! is_finite( $seconds ) ) {
			$seconds = self::TICK_SECONDS;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$seconds = min( $seconds, $limit / 2 );
		}

		return max( 1.0, $seconds );
	}

	/**
	 * Seconds a WP-Cron or loopback request works on a scheduled backup: the
	 * filter's value (a non-finite one is ignored), at most half of
	 * max_execution_time less one tick (a tick may start just before the
	 * budget ends), at least one.
	 *
	 * @param float $fallback Budget without the filter.
	 */
	public static function cronSeconds( float $fallback ): float {
		/**
		 * Seconds a WP-Cron or loopback request works on a scheduled backup
		 * before it hands over to the next request.
		 *
		 * @param float $seconds Budget.
		 */
		$seconds = (float) apply_filters( 'lw_migration_cron_seconds', $fallback );
		if ( ! is_finite( $seconds ) ) {
			$seconds = $fallback;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$seconds = min( $seconds, $limit / 2 - self::tickSeconds() );
		}

		return max( 1.0, $seconds );
	}

	/**
	 * Bytes per upload chunk, the same on every request of this server: the
	 * page sends chunks of this size (JobView's upload input, the 413 data)
	 * and the upload route takes chunks up to it.
	 */
	public static function chunkBytes(): int {
		return self::chunkFor( Memory::bytes( (string) ini_get( 'memory_limit' ) ), (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) );
	}

	/**
	 * A quarter of the memory limit above a fixed baseline (never the live
	 * memory use, which differs from request to request), clamped to
	 * MIN_CHUNK..MAX_CHUNK and always inside post_max_size: a larger body is
	 * dropped by PHP before any code runs.
	 *
	 * @param int|null $memoryLimit memory_limit in bytes (null: no limit).
	 * @param int      $postMax     post_max_size in bytes (0 or less: no limit).
	 */
	public static function chunkFor( ?int $memoryLimit, int $postMax ): int {
		$chunk = self::MAX_CHUNK;
		if ( null !== $memoryLimit ) {
			$chunk = min( $chunk, max( self::MIN_CHUNK, intdiv( $memoryLimit - self::MEMORY_BASELINE, self::MEMORY_SHARE ) ) );
		}
		if ( $postMax > 0 ) {
			$chunk = min( $chunk, $postMax - self::BODY_MARGIN );
		}

		return max( 1, $chunk );
	}

	public static function wholeDownloadBytes(): int {
		/**
		 * Largest download without byte ranges in one browser request.
		 *
		 * @param int $bytes Byte limit.
		 */
		return max( 0, (int) apply_filters( 'lw_migration_web_whole_download_bytes', self::WHOLE_DOWNLOAD ) );
	}

	/**
	 * Most bytes an upload may declare: the free space minus the import's margin,
	 * or, when that is unknown, the filter `lw_migration_max_upload_bytes` (50 GiB).
	 *
	 * @param int|null $free Free bytes in the jobs folder.
	 */
	public static function uploadRoom( ?int $free ): int {
		if ( null !== $free ) {
			return max( 0, $free - UrlDownloadStep::DISK_MARGIN );
		}

		/**
		 * Largest upload when the free disk space is unknown.
		 *
		 * @param int $bytes Byte limit.
		 */
		return max( 0, (int) apply_filters( 'lw_migration_max_upload_bytes', UrlDownloadStep::MAX_DOWNLOAD ) );
	}

	/**
	 * Why the download step would fetch a whole file that may not fit one
	 * request (no byte ranges and unknown or too large size), or null.
	 *
	 * @param Job $job Job.
	 */
	public static function tooLargeForOneRequest( Job $job ): ?string {
		if ( WebDownloadGuard::STEP !== $job->currentStep() ) {
			return null;
		}
		$download = (array) ( $job->state['download'] ?? [] );
		$size     = isset( $download['size'] ) ? (int) $download['size'] : null;
		if ( null !== $size && ( ! empty( $download['ranges'] ) || $size <= self::wholeDownloadBytes() ) ) {
			return null;
		}

		return __( 'The server of this address cannot send the file in parts, and the file is too large (or of unknown size) to download in one browser request. Download the file and upload it here, or run: wp lw-migration import <address>', 'lw-migration' );
	}
}
