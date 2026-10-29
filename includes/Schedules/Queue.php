<?php
/**
 * Schedules whose run is due but has not started yet.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\OptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * A JSON list [{id, due}] in one option row, changed only by compare-and-swap
 * (OptionRow): two requests that queue at the same moment (a cron run and a
 * loopback) both land. A schedule is queued at most once, so missed and
 * overlapping runs coalesce into one (decision 5), unless the waiting run is
 * past the skip limit (add()). Oldest due first. take()
 * is the claim on a run: only one request removes an entry.
 */
final class Queue {

	private const TRIES = 5;

	/**
	 * The option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::SCHEDULE_QUEUE, $db );
	}

	/**
	 * @return array<int, array{id: string, due: int}>
	 */
	public function all(): array {
		return self::decode( $this->row->read() );
	}

	/**
	 * Queues the run. A run of the schedule already waiting absorbs it, unless
	 * that one waited longer than CronDriver::QUEUE_LIMIT before this due time:
	 * it would only be skipped, so this run takes its place.
	 *
	 * @param string   $id       Schedule id.
	 * @param int      $due      When the run was due.
	 * @param int|null $replaced Set to the due time of the waiting run this one replaced (the write that landed), else null.
	 * @return bool False when a run of the schedule already waits (and goes).
	 */
	public function add( string $id, int $due, ?int &$replaced = null ): bool {
		$added = false;
		$stale = null;
		$this->change(
			static function ( array $list ) use ( $id, $due, &$added, &$stale ): array {
				$stale = null; // Each try reads the queue afresh.
				foreach ( $list as $key => $entry ) {
					if ( $entry['id'] === $id && $entry['due'] < $due - CronDriver::QUEUE_LIMIT ) {
						$stale = $entry['due'];
						unset( $list[ $key ] );
					}
				}
				$added = ! in_array( $id, array_column( $list, 'id' ), true );
				if ( $added ) {
					$list[] = [
						'id'  => $id,
						'due' => $due,
					];
				}
				return array_values( $list );
			}
		);
		$replaced = $stale;

		return $added;
	}

	/**
	 * Removes the entry as a claim: of two requests that read the same
	 * queue, only the one whose write removed it starts the run.
	 *
	 * @param string $id Schedule id.
	 * @return bool Whether this request removed it.
	 */
	public function take( string $id ): bool {
		$taken = false;
		$this->change(
			static function ( array $list ) use ( $id, &$taken ): array {
				$kept  = array_values( array_filter( $list, static fn( array $entry ): bool => $entry['id'] !== $id ) );
				$taken = count( $kept ) !== count( $list );
				return $kept;
			}
		);

		return $taken;
	}

	/**
	 * @param string $id Schedule id.
	 */
	public function remove( string $id ): void {
		$this->change( static fn( array $list ): array => array_values( array_filter( $list, static fn( array $entry ): bool => $entry['id'] !== $id ) ) );
	}

	/**
	 * Read, change, write back only if nobody wrote in between; else again.
	 *
	 * @param \Closure $edit fn( array $list ): array.
	 * @throws \RuntimeException When the row keeps changing under us (internal, never shown to people).
	 */
	private function change( \Closure $edit ): void {
		for ( $try = 0; $try < self::TRIES; $try++ ) {
			$raw  = $this->row->read();
			$list = self::decode( $raw );
			$next = self::decode( (string) json_encode( $edit( $list ) ) );
			if ( $next === $list ) {
				return;
			}
			$value = (string) json_encode( $next );
			if ( null === $raw ? $this->row->insert( $value ) : $this->row->swap( $raw, $value ) ) {
				return;
			}
		}
		throw new \RuntimeException( 'The schedule queue was changed by too many requests at once.' );
	}

	/**
	 * @param string|null $raw Stored value.
	 * @return array<int, array{id: string, due: int}> Valid entries, oldest due first.
	 */
	private static function decode( ?string $raw ): array {
		$data = null === $raw ? [] : json_decode( $raw, true );
		$list = [];
		foreach ( is_array( $data ) ? $data : [] as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && 1 === preg_match( Schedule::ID_PATTERN, $entry['id'] ) && is_int( $entry['due'] ?? null ) ) {
				$list[] = [
					'id'  => $entry['id'],
					'due' => $entry['due'],
				];
			}
		}
		usort( $list, static fn( array $a, array $b ): int => [ $a['due'], $a['id'] ] <=> [ $b['due'], $b['id'] ] );

		return $list;
	}
}
