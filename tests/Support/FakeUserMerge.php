<?php
/**
 * Recording stand-in for the user merge.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Multisite\UserMerge;

/**
 * Every call is recorded in order ("shift wp_7_posts.post_author 6"); a hook
 * runs before each recorded call (to look at what the job had saved by then),
 * and a method named in $failOn throws a QueryException with errno 1205.
 */
final class FakeUserMerge extends UserMerge {

	/** @var array<int, string> */
	public array $calls = [];

	public bool $staged = true;

	public int $max = 6;

	/** @var array<int, array{0: string, 1: string}> */
	public array $targetList = [ [ 'wp_7_comments', 'user_id' ], [ 'wp_7_posts', 'post_author' ] ];

	/** @var array<string, string> */
	public array $clashes = [];

	/** @var array<int, string> Method names that fail. */
	public array $failOn = [];

	/** @var \Closure|null fn( string $call ): void, run before each recorded call. */
	public ?\Closure $before = null;

	/** @var array<int, mixed> Arguments of the last targets() / insertUserMeta() call. */
	public array $args = [];

	public function __construct() {
		parent::__construct( new FakeConnection(), 'wp_', 'lwtabcd_' );
	}

	public function hasStaging(): bool {
		return $this->staged;
	}

	public function insertUsers(): void {
		$this->record( 'insertUsers' );
	}

	public function emailClashes(): array {
		$this->record( 'emailClashes' );

		return $this->clashes;
	}

	/** @var array<int, int> Live max ids returned in turn (the last one repeats). */
	public array $live = [ 2 ];

	/** @var array<string, string> */
	public array $slugs = [];

	/** @var array<string, string> */
	public array $matches = [];

	/** @var array<int, string> Tables holding values above the offset. */
	public array $shifted = [];

	public function liveMaxId(): int {
		return count( $this->live ) > 1 ? (int) array_shift( $this->live ) : $this->live[0];
	}

	public function nicenameClashes(): array {
		return $this->slugs;
	}

	public function loginMatches(): array {
		return $this->matches;
	}

	public function holdsShifted( string $table, string $column ): bool {
		return in_array( $table, $this->shifted, true );
	}

	public function maxStagedId(): int {
		return $this->max;
	}

	public function targets( array $newIds, array $swapped ): array {
		$this->args['targets'] = [ $newIds, $swapped ];

		return $this->targetList;
	}

	public function shift( string $table, string $column, int $max ): void {
		$this->record( "shift {$table}.{$column} {$max}" );
	}

	public function remap( string $table, string $column ): void {
		$this->record( "remap {$table}.{$column}" );
	}

	public function unshift( string $table, string $column ): void {
		$this->record( "unshift {$table}.{$column}" );
	}

	/** @var array<int, string> Logins sanitizeCapabilities() reports. */
	public array $refused = [];

	public function sanitizeCapabilities( array $newIds ): array {
		$this->record( 'sanitizeCapabilities' );

		return $this->refused;
	}

	public function insertUserMeta( array $blogIds, array $hosts, int $mainSiteId, string $networkHost, int $liveMax ): void {
		$this->args['insertUserMeta'] = [ $blogIds, $hosts, $mainSiteId, $networkHost, $liveMax ];
		$this->record( 'insertUserMeta' );
	}

	private function record( string $call ): void {
		if ( null !== $this->before ) {
			( $this->before )( $call );
		}
		$this->calls[] = $call;
		if ( in_array( strtok( $call, ' ' ), $this->failOn, true ) ) {
			throw new QueryException( 'Simulated', 1205, $call );
		}
	}
}
