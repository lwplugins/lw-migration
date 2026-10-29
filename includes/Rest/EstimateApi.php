<?php
/**
 * GET /export/estimate: how big each part of a backup would be.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Export\Estimate\DatabaseSizes;
use LightweightPlugins\Migration\Export\Estimate\EstimateParts;
use LightweightPlugins\Migration\Export\Estimate\EstimateScan;
use LightweightPlugins\Migration\Export\Estimate\EstimateStore;
use LightweightPlugins\Migration\Export\Estimate\FileSizes;
use LightweightPlugins\Migration\Export\Estimate\NetworkSoftware;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\Support\TimeDeadline;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteReader;

defined( 'ABSPATH' ) || exit;

/**
 * Never blocks: a request answers what is stored, after measuring for at
 * most BUDGET seconds itself (continued on the next request: the Backup tab
 * asks again while `complete` is false). It measures only when there is
 * something to do (no result yet, a measuring under way, or a result older
 * than STALE_AFTER) and only when it takes the lease (EstimateStore), so two
 * requests never measure at once. While a new measuring runs, the last
 * finished result is answered, marked not complete; before the first one
 * finished, the parts measured so far (EstimateParts). A measuring nobody
 * continued for STALE_AFTER seconds starts over.
 *
 * The time budget is checked between folders (FileEnumerator's unit of
 * work), so one huge flat folder can make a slice run long, and a host's
 * time limit may kill it before anything is saved. After MAX_ATTEMPTS slices
 * in a row that did not end normally (died or failed: EstimateStore), the
 * measuring is given up instead of retried on every request: the result
 * keeps what was finished, the parts it could not finish are null and named
 * in `partial`, and it counts as complete (measured again after
 * STALE_AFTER). On a network the estimate is for the whole network
 * (`scope`). It carries sizes and counts only.
 */
final class EstimateApi {

	/** Seconds one request may measure. */
	public const BUDGET = 2.0;

	/** Seconds after which a result is measured again. */
	public const STALE_AFTER = 3600;

	/** Slices in a row that died or failed before the measuring is given up. */
	public const MAX_ATTEMPTS = 2;

	/** Folder of the work files, in the jobs folder (never a job: JobStore ignores the name). */
	public const WORK_DIR = 'estimate';

	/**
	 * @param EstimateStore $store     The stored estimate.
	 * @param EstimateScan  $scan      The measuring.
	 * @param \Closure      $prepare   fn(): void — runs before a slice (makes the storage folder and its deny files).
	 * @param \Closure      $site      fn(): Site — the site now, when a measuring starts.
	 * @param \Closure      $freeSpace fn(): ?int — free bytes where a backup is built.
	 * @param \Closure      $clock     fn(): int — Unix time.
	 * @param bool          $network   Whether this is a network.
	 * @param float         $budget    Seconds one request may measure.
	 */
	public function __construct(
		private EstimateStore $store,
		private EstimateScan $scan,
		private \Closure $prepare,
		private \Closure $site,
		private \Closure $freeSpace,
		private \Closure $clock,
		private bool $network,
		private float $budget = self::BUDGET
	) {
	}

	public static function fromServices( Services $services ): self {
		$storage = $services->storage();
		$network = is_multisite();

		return new self(
			new EstimateStore(),
			new EstimateScan(
				new FileSizes( $storage->jobsDir() . '/' . self::WORK_DIR, $storage->root() ),
				static fn(): DatabaseSizes => new DatabaseSizes( $services->connection() ),
				$network ? new NetworkSoftware( new NetworkReader(), plugin_basename( LW_MIGRATION_FILE ) ) : null
			),
			static function () use ( $storage ): void {
				$storage->ensure();
			},
			static fn(): Site => SiteReader::read(),
			static fn(): ?int => DiskSpace::free( $storage->root() ),
			static fn(): int => time(),
			$network
		);
	}

	/**
	 * @return array{parts: array<string, array<string, int|null>|null>, complete: bool, partial: array<int, string>, measuredAt: int|null, freeSpace: int|null, scope: string}
	 */
	public function view(): array {
		$now   = ( $this->clock )();
		$state = $this->store->get();
		if ( null !== $state['scan'] || $this->stale( $state['result'], $now ) ) {
			$claimed = $this->store->claim( $now );
			if ( null !== $claimed ) {
				$state = $claimed['attempts'] >= self::MAX_ATTEMPTS ? $this->giveUp( $claimed, $now ) : $this->measure( $claimed, $now );
			}
		}
		$result = $state['result'];

		return [
			'parts'      => null !== $result ? (array) $result['parts'] : ( null !== $state['scan'] ? EstimateParts::of( $state['scan'] ) : EstimateParts::none() ),
			'complete'   => null === $state['scan'] && ! $this->stale( $result, $now ),
			'partial'    => null === $result ? [] : array_values( array_map( 'strval', (array) ( $result['partial'] ?? [] ) ) ),
			'measuredAt' => null === $result ? null : (int) $result['measuredAt'],
			'freeSpace'  => ( $this->freeSpace )(),
			'scope'      => $this->network ? 'network' : 'site',
		];
	}

	/**
	 * One slice under the lease. A failure gives the lease back, counts as
	 * an attempt that did not end normally, and is thrown.
	 *
	 * @param array{result: array<string, mixed>|null, scan: array<string, mixed>|null, lease: array{token: string, until: int}|null, attempts: int} $claimed State with this request's lease.
	 * @param int                                                                                                                                    $now     Unix time.
	 * @return array{result: array<string, mixed>|null, scan: array<string, mixed>|null} What is stored afterwards.
	 * @throws \Throwable What the slice threw (the storage folder, a work file, the database).
	 */
	private function measure( array $claimed, int $now ): array {
		$token  = (string) ( $claimed['lease']['token'] ?? '' );
		$result = $claimed['result'];
		$scan   = $claimed['scan'];
		try {
			$deadline = new TimeDeadline( $this->budget ); // The storage check and the site snapshot count too.
			( $this->prepare )();
			if ( null === $scan || $now - (int) ( $scan['touchedAt'] ?? 0 ) >= self::STALE_AFTER ) {
				$scan = $this->scan->start( ( $this->site )(), $now );
			}
			$scan = $this->scan->run( $scan, $deadline, $now, $token );
		} catch ( \Throwable $e ) {
			$this->store->finish( $token, $claimed['result'], $claimed['scan'], true );
			throw $e;
		}
		if ( EstimateScan::DONE === $scan['phase'] ) {
			$result = [
				'parts'      => EstimateParts::of( $scan ),
				'partial'    => [],
				'measuredAt' => $now,
			];
			$scan   = null;
		}

		return $this->save( $token, $result, $scan );
	}

	/**
	 * Ends a measuring whose slices keep dying: what it finished is kept,
	 * the rest is null and named in `partial`.
	 *
	 * @param array{result: array<string, mixed>|null, scan: array<string, mixed>|null, lease: array{token: string, until: int}|null, attempts: int} $claimed State with this request's lease.
	 * @param int                                                                                                                                    $now     Unix time.
	 * @return array{result: array<string, mixed>|null, scan: array<string, mixed>|null} What is stored afterwards.
	 */
	private function giveUp( array $claimed, int $now ): array {
		$scan    = $claimed['scan'];
		$parts   = null === $scan ? EstimateParts::none() : EstimateParts::of( $scan );
		$partial = null === $scan ? EstimateParts::KEYS : EstimateParts::unfinished( (string) $scan['phase'] );
		foreach ( $partial as $key ) {
			$parts[ $key ] = null;
		}
		$this->scan->discard();

		return $this->save(
			(string) ( $claimed['lease']['token'] ?? '' ),
			[
				'parts'      => $parts,
				'partial'    => $partial,
				'measuredAt' => $now,
			],
			null
		);
	}

	/**
	 * Stores the state while this request still holds its lease; otherwise
	 * (it ran past the lease and another request took over) nothing of it is
	 * kept, and what is stored is answered instead.
	 *
	 * @param string                    $token  Lease token.
	 * @param array<string, mixed>|null $result Last finished measuring.
	 * @param array<string, mixed>|null $scan   Measuring under way.
	 * @return array{result: array<string, mixed>|null, scan: array<string, mixed>|null}
	 */
	private function save( string $token, ?array $result, ?array $scan ): array {
		if ( ! $this->store->finish( $token, $result, $scan ) ) {
			return $this->store->get();
		}

		return [
			'result' => $result,
			'scan'   => $scan,
		];
	}

	/**
	 * @param array<string, mixed>|null $result Last finished measuring.
	 * @param int                       $now    Unix time.
	 */
	private function stale( ?array $result, int $now ): bool {
		return null === $result || $now - (int) ( $result['measuredAt'] ?? 0 ) >= self::STALE_AFTER;
	}
}
