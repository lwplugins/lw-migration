<?php
/**
 * Time budget for resumable work.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resumable operations poll this after each unit of work and stop when reached.
 */
interface Deadline {

	public function reached(): bool;
}
