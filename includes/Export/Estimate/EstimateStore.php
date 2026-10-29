<?php
/**
 * Where the size estimate is kept between requests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Estimate;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\WordPress\JsonOptionRow;

defined( 'ABSPATH' ) || exit;

/**
 * One option row (JsonOptionRow: never autoloaded, compare-and-swap):
 * {result, scan, lease, attempts}. `result` is the last finished measuring
 * with its time, `scan` the one under way, `lease` the request measuring
 * now. A request measures only after it took the lease (written by
 * compare-and-swap, so of two concurrent requests one wins; the other
 * answers what is stored), and its work is saved only while it still holds
 * the lease. A request that died lets the lease expire after LEASE seconds.
 * `attempts` counts the slices in a row that did not end normally: a claim
 * that finds an expired lease (its request died, for example killed by the
 * host's time limit) and a slice that failed; a slice that ends normally
 * sets it back to 0. Our own rows never go into a database dump
 * (Options::DUMP_EXCLUDED_PREFIXES).
 *
 * @phpstan-type State array{result: array<string, mixed>|null, scan: array<string, mixed>|null, lease: array{token: string, until: int}|null, attempts: int}
 */
final class EstimateStore {

	/** Seconds a lease lasts: well past a slice, also one stuck on a huge folder. */
	public const LEASE = 120;

	/**
	 * The option row.
	 *
	 * @var JsonOptionRow
	 */
	private JsonOptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new JsonOptionRow( Options::EXPORT_ESTIMATE, $db );
	}

	/**
	 * @return State
	 */
	public function get(): array {
		return self::normalize( $this->row->get() );
	}

	/**
	 * Takes the lease unless another request holds it.
	 *
	 * @param int $now Unix time.
	 * @return State|null The stored state with this request's lease, or null.
	 */
	public function claim( int $now ): ?array {
		$token   = bin2hex( random_bytes( 8 ) );
		$claimed = null;
		$written = $this->row->change(
			static function ( $current ) use ( $token, $now, &$claimed ): ?array {
				$state   = self::normalize( $current );
				$claimed = null;
				if ( null !== $state['lease'] && $state['lease']['until'] > $now ) {
					return null;
				}
				$state['attempts'] += null === $state['lease'] ? 0 : 1; // The holder of an expired lease died.
				$state['lease']     = [
					'token' => $token,
					'until' => $now + self::LEASE,
				];
				$claimed            = $state;

				return $state;
			}
		);

		return $written ? $claimed : null;
	}

	/**
	 * Saves the state and gives the lease back, only while $token holds it.
	 *
	 * @param string                    $token  The lease token of claim().
	 * @param array<string, mixed>|null $result Last finished measuring.
	 * @param array<string, mixed>|null $scan   Measuring under way.
	 * @param bool                      $failed The slice failed: one more attempt that did not end normally.
	 * @return bool False when the lease was lost (nothing written).
	 */
	public function finish( string $token, ?array $result, ?array $scan, bool $failed = false ): bool {
		$saved   = false;
		$written = $this->row->change(
			static function ( $current ) use ( $token, $result, $scan, $failed, &$saved ): ?array {
				$state = self::normalize( $current );
				$saved = ( $state['lease']['token'] ?? null ) === $token;

				return $saved ? [
					'result'   => $result,
					'scan'     => $scan,
					'lease'    => null,
					'attempts' => $failed ? $state['attempts'] + 1 : 0,
				] : null;
			}
		);

		return $written && $saved;
	}

	/**
	 * Drops the stored estimate and the measuring under way (its lease goes
	 * with it, so a slice still running saves nothing): after a restore or a
	 * reset the site is another one, and the next request measures again.
	 * The work files go when the next measuring starts (EstimateScan::start).
	 */
	public function forget(): bool {
		return $this->row->delete();
	}

	/**
	 * @param mixed $stored Decoded row (null: none).
	 * @return State
	 */
	private static function normalize( $stored ): array {
		$stored = is_array( $stored ) ? $stored : [];
		$lease  = is_array( $stored['lease'] ?? null ) && is_string( $stored['lease']['token'] ?? null ) && is_int( $stored['lease']['until'] ?? null ) ? $stored['lease'] : null;

		return [
			'result'   => is_array( $stored['result'] ?? null ) ? $stored['result'] : null,
			'scan'     => is_array( $stored['scan'] ?? null ) ? $stored['scan'] : null,
			'lease'    => null === $lease ? null : [
				'token' => $lease['token'],
				'until' => $lease['until'],
			],
			'attempts' => is_int( $stored['attempts'] ?? null ) ? max( 0, $stored['attempts'] ) : 0,
		];
	}
}
