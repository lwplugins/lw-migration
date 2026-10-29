<?php
/**
 * Test deadline reached after a fixed number of checks.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Support\Deadline;

/**
 * Forces resumable operations to stop after N units of work.
 */
final class CountdownDeadline implements Deadline {

	private int $left;

	public function __construct( int $allowedChecks ) {
		$this->left = $allowedChecks;
	}

	public function reached(): bool {
		--$this->left;

		return $this->left < 0;
	}
}
