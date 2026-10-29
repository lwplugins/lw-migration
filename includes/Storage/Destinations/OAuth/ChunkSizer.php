<?php
/**
 * Chunk sizes of the OAuth destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\ChunkPolicy;
use LightweightPlugins\Migration\Support\Memory;

defined( 'ABSPATH' ) || exit;

/**
 * M7c plan E10 with preflight P-M2/P-M3: M7a's growing ChunkPolicy (doubled
 * while fast, halved while slow, a whole multiple, never above the maximum),
 * the maximum also held under a quarter of the memory still free — the chunk
 * is a string in memory that cURL copies (as GrowingParts::policy()). The
 * request timeouts come from CloudTimeout, not from here.
 */
final class ChunkSizer {

	/**
	 * The growing policy.
	 *
	 * @var ChunkPolicy
	 */
	private ChunkPolicy $policy;

	/**
	 * Clock: fn(): float (seconds).
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * The largest chunk this process sends (a whole multiple).
	 *
	 * @var int
	 */
	private int $largest;

	/**
	 * @param int           $start    First chunk.
	 * @param int           $max      Largest chunk.
	 * @param int           $multiple Every chunk but the last is a multiple of this.
	 * @param \Closure|null $memory   fn(): ?int — bytes still free (default Memory::available(); null = no limit).
	 * @param \Closure|null $clock    fn(): float (default microtime( true )).
	 */
	public function __construct( int $start, int $max, int $multiple, ?\Closure $memory = null, ?\Closure $clock = null ) {
		$free          = null === $memory ? Memory::available() : $memory();
		$cap           = null === $free ? $max : intdiv( intdiv( max( 0, (int) $free ), 4 ), max( 1, $multiple ) ) * $multiple;
		$this->policy  = ChunkPolicy::growing( min( $start, $cap ), min( $max, $cap ), $multiple );
		$this->largest = max( $multiple, intdiv( min( $max, $cap ), $multiple ) * $multiple );
		$this->clock   = $clock ?? static fn(): float => microtime( true );
	}

	public function first(): int {
		return $this->policy->first();
	}

	/**
	 * The largest chunk under this process's memory cap: a session stored
	 * by a process with more memory sends no more than this (Task 8 m4).
	 */
	public function largest(): int {
		return $this->largest;
	}

	public function clock(): float {
		return (float) ( $this->clock )();
	}

	/**
	 * @param int   $sent      Bytes of the chunk just sent.
	 * @param float $startedAt clock() before it was sent.
	 */
	public function next( int $sent, float $startedAt ): int {
		return $this->policy->next( max( $this->policy->multiple(), $sent ), $this->clock() - $startedAt );
	}
}
