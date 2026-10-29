<?php
/**
 * Throws away whatever a piece of work prints.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * A REST answer is JSON: a PHP notice printed with display_errors on would
 * break it and could show server paths. Everything printed inside run() is
 * discarded, also when the work throws or leaves its own buffers open.
 */
final class QuietOutput {

	/**
	 * @template T
	 * @param \Closure $work The work: fn(): T.
	 * @return mixed What the work returned.
	 * @phpstan-param \Closure(): T $work
	 * @phpstan-return T
	 */
	public static function run( \Closure $work ) {
		$level = ob_get_level();
		ob_start();
		try {
			return $work();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}
}
