<?php
/**
 * Total bytes an extraction may write.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * A compressed entry can inflate thousands of times (each chunk is capped,
 * the entry and the archive are not), so every extraction counts how much it
 * grows the disk use against one limit (Reader::extractTo: every byte while it
 * is written, then a refund of the size of the file it replaced once the rename
 * freed it, so the disk never holds more than the limit): the free disk space at its start minus a margin of
 * max( 256 MiB, 5 % ), or a ceiling when the free space cannot be read. The
 * count is kept by the caller between requests (toArray/fromArray).
 */
final class WriteBudget {

	/** Smallest margin left free on the disk (256 MiB). */
	public const MIN_MARGIN = 268435456;

	/** Default limit when the free disk space is unknown (50 GiB, like the download ceiling). */
	public const UNKNOWN_CEILING = 53687091200;

	/**
	 * @param int|null $limit   Most bytes in total, null for no limit.
	 * @param int      $written Bytes counted so far.
	 */
	public function __construct( private ?int $limit, private int $written = 0 ) {
	}

	/** No limit: for reads that never reach the disk, and tests. */
	public static function unlimited(): self {
		return new self( null );
	}

	/**
	 * @param int|null $free    Free bytes at the start, null when unknown.
	 * @param int      $ceiling Limit when the free space is unknown.
	 */
	public static function forFreeSpace( ?int $free, int $ceiling ): self {
		if ( null === $free ) {
			return new self( max( 0, $ceiling ) );
		}

		return new self( max( 0, $free - self::margin( $free ) ) );
	}

	/**
	 * Bytes kept free: max( 256 MiB, 5 % of the free space ).
	 *
	 * @param int $free Free bytes.
	 */
	public static function margin( int $free ): int {
		return max( self::MIN_MARGIN, intdiv( max( 0, $free ), 20 ) );
	}

	/**
	 * Counts bytes that are about to be written.
	 *
	 * @param int $bytes Bytes.
	 * @return void
	 * @throws WriteLimitException When they would take the total past the limit (nothing is counted then).
	 */
	public function spend( int $bytes ): void {
		if ( null !== $this->limit && $this->written + $bytes > $this->limit ) {
			throw new WriteLimitException( sprintf( 'The extraction would add more than %d bytes to the disk, the limit set by the free disk space.', $this->limit ) );
		}
		$this->written += $bytes;
	}

	/**
	 * Gives back bytes that were freed (a replaced file, after the rename).
	 *
	 * @param int $bytes Bytes.
	 */
	public function refund( int $bytes ): void {
		$this->written = max( 0, $this->written - max( 0, $bytes ) );
	}

	public function limit(): ?int {
		return $this->limit;
	}

	public function written(): int {
		return $this->written;
	}

	/**
	 * @return array{limit: int|null, written: int}
	 */
	public function toArray(): array {
		return [
			'limit'   => $this->limit,
			'written' => $this->written,
		];
	}

	/**
	 * @param array<string, mixed> $data Saved budget.
	 */
	public static function fromArray( array $data ): self {
		$limit = $data['limit'] ?? null;

		return new self( null === $limit ? null : max( 0, (int) $limit ), max( 0, (int) ( $data['written'] ?? 0 ) ) );
	}
}
