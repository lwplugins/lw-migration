<?php
/**
 * PHP memory limit arithmetic.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a step refuse work that would exceed memory_limit instead of dying
 * with a fatal error that no catch block can report.
 */
final class Memory {

	private const UNITS = [
		''  => 1,
		'k' => 1024,
		'm' => 1048576,
		'g' => 1073741824,
	];

	/**
	 * Bytes still free under memory_limit (against the memory PHP has reserved,
	 * which is what the limit counts), or null when there is no limit.
	 */
	public static function available(): ?int {
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );

		return null === $limit ? null : $limit - memory_get_usage( true );
	}

	/**
	 * A memory_limit value in bytes ("256M", "1G", "512K", "134217728").
	 *
	 * @param string $value Ini value.
	 * @return int|null Null for -1 (unlimited) or a value that cannot be read.
	 */
	public static function bytes( string $value ): ?int {
		if ( 1 !== preg_match( '/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m ) ) {
			return null;
		}
		$bytes = (int) $m[1] * self::UNITS[ strtolower( $m[2] ) ];

		return $bytes > 0 ? $bytes : null;
	}
}
