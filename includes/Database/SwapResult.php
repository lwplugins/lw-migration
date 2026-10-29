<?php
/**
 * Outcome of a table swap.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Final table names plus warnings about replaced tables that could not be dropped.
 */
final class SwapResult {

	/**
	 * @param array<int, string> $finals   Final names of the swapped-in tables.
	 * @param array<int, string> $warnings Post-swap cleanup warnings.
	 */
	public function __construct(
		public array $finals,
		public array $warnings
	) {
	}
}
