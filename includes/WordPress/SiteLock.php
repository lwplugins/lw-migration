<?php
/**
 * One export/import job per site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\WordPress;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Option row {job, expires} as JSON; the driver refreshes it before every tick,
 * so a job that nobody drives any more frees the site after TTL seconds.
 *
 * Every change is atomic in the database (OptionRow): a free lock is taken with
 * INSERT IGNORE, an expired one and a refresh by compare-and-swap on the exact
 * value read, a release deletes only the value read. Of two concurrent drivers
 * exactly one wins.
 */
final class SiteLock {

	public const TTL = 1800;

	/**
	 * The lock's option row.
	 *
	 * @var OptionRow
	 */
	private OptionRow $row;

	/**
	 * @param \wpdb|null $db Database object; defaults to the global $wpdb.
	 */
	public function __construct( ?object $db = null ) {
		$this->row = new OptionRow( Options::LOCK, $db );
	}

	public function acquire( string $jobId, int $now ): bool {
		$value = $this->encode( $jobId, $now );
		$raw   = $this->row->read();
		if ( null === $raw ) {
			if ( $this->row->insert( $value ) ) {
				return true;
			}
			$raw = $this->row->read();
			if ( null === $raw ) {
				return false;
			}
		}
		$current = $this->decode( $raw );
		if ( null !== $current && $current['job'] !== $jobId && $current['expires'] > $now ) {
			return false;
		}

		return $raw === $value || $this->row->swap( $raw, $value );
	}

	/**
	 * Extends the lock of the job that holds it.
	 *
	 * @param string $jobId Job that should hold the lock.
	 * @param int    $now   Current Unix time.
	 * @return bool False when another job holds the lock (or it is gone).
	 */
	public function refresh( string $jobId, int $now ): bool {
		$raw     = $this->row->read();
		$current = null === $raw ? null : $this->decode( $raw );
		if ( null === $raw || null === $current || $current['job'] !== $jobId ) {
			return false;
		}
		$value = $this->encode( $jobId, $now );

		return $raw === $value || $this->row->swap( $raw, $value );
	}

	public function release( string $jobId ): void {
		$raw     = $this->row->read();
		$current = null === $raw ? null : $this->decode( $raw );
		if ( null !== $raw && null !== $current && $current['job'] === $jobId ) {
			$this->row->deleteIf( $raw );
		}
	}

	/**
	 * @param int $now Current Unix time.
	 * @return int|null When the current lock expires unless its job refreshes it; null when the site is free.
	 */
	public function expiresAt( int $now ): ?int {
		$raw     = $this->row->read();
		$current = null === $raw ? null : $this->decode( $raw );

		return null !== $current && $current['expires'] > $now ? $current['expires'] : null;
	}

	public function holder( int $now ): ?string {
		$raw     = $this->row->read();
		$current = null === $raw ? null : $this->decode( $raw );

		return null !== $current && $current['expires'] > $now ? $current['job'] : null;
	}

	/**
	 * @param string $jobId Job that holds the lock.
	 * @param int    $now   Current Unix time.
	 */
	private function encode( string $jobId, int $now ): string {
		return (string) json_encode(
			[
				'job'     => $jobId,
				'expires' => $now + self::TTL,
			]
		);
	}

	/**
	 * @param string $raw Stored value.
	 * @return array{job: string, expires: int}|null Null for anything that is not a lock.
	 */
	private function decode( string $raw ): ?array {
		$value = json_decode( $raw, true );
		if ( ! is_array( $value ) || ! is_string( $value['job'] ?? null ) || ! is_int( $value['expires'] ?? null ) ) {
			return null;
		}

		return [
			'job'     => $value['job'],
			'expires' => $value['expires'],
		];
	}
}
