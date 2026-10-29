<?php
/**
 * How long one cloud request may take.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D38: one place for the per-request timeout every destination uses
 * (it was S3Destination::timeout() in M7a), and the longer one a whole-file
 * request needs (D26).
 */
final class CloudTimeout {

	public const DEFAULT = 120;

	public const MIN = 10;

	public const MAX = 600;

	/** Longest single request (a whole backup over WebDAV from the command line). */
	public const LONGEST = 21600;

	/** Seconds one part, range read or listing may take. */
	public static function seconds(): int {
		/**
		 * Seconds one cloud part (or ranged read) may take.
		 *
		 * @param int $seconds Default 120.
		 */
		return max( self::MIN, min( self::MAX, (int) apply_filters( 'lw_migration_cloud_timeout', self::DEFAULT ) ) );
	}

	/**
	 * Seconds for one request that sends $bytes at no less than $rate bytes a second.
	 *
	 * @param int $bytes Bytes sent.
	 * @param int $rate  Slowest rate expected (bytes per second).
	 */
	public static function forBytes( int $bytes, int $rate ): int {
		$needed = (int) min( (float) self::LONGEST, ceil( max( 0, $bytes ) / max( 1, $rate ) ) );

		return max( self::seconds(), $needed );
	}
}
