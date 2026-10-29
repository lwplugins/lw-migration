<?php
/**
 * One SQL statement read from a dump.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Byte range in the file, the trimmed SQL, whether it was too large to run,
 * and whether the file ended before its closing ";" (then it must not run).
 */
final class Statement {

	public function __construct(
		public string $sql,
		public int $start,
		public int $end,
		public bool $oversized,
		public bool $truncated = false
	) {
	}
}
