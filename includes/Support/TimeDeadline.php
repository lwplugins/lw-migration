<?php
/**
 * Wall-clock deadline.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reached once the given number of seconds has elapsed since construction.
 */
final class TimeDeadline implements Deadline {

	/**
	 * Microtime timestamp at which the deadline is reached.
	 *
	 * @var float
	 */
	private float $until;

	public function __construct( float $seconds ) {
		$this->until = microtime( true ) + $seconds;
	}

	public function reached(): bool {
		return microtime( true ) >= $this->until;
	}

	/** Seconds left (0 once reached). */
	public function remaining(): float {
		return max( 0.0, $this->until - microtime( true ) );
	}
}
