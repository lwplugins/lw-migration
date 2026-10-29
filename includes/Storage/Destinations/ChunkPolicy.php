<?php
/**
 * How large each part of an upload is.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (spec 21.2, 21.10). S3 and its compatibles get one fixed part size
 * per upload — Cloudflare R2 requires every part but the last to have the
 * same size — computed from the file size: max(5 MiB, size / 10 000) rounded
 * up to a whole MiB, so at most 10 000 parts. A part size above S3_MAX_PART
 * means the file is too large for S3; the caller refuses it. Other types
 * grow their chunk from a small start like the push upload: doubled while a
 * chunk takes under half of TARGET_SECONDS, halved when it takes more than
 * twice that, always a whole multiple (Google Drive 256 KiB, OneDrive
 * 320 KiB) and never above the type's maximum.
 */
final class ChunkPolicy {

	public const MIB = 1048576;

	public const S3_MIN_PART = 5242880;

	public const S3_MAX_PART = 5368709120;

	public const S3_MAX_PARTS = 10000;

	/** Seconds one growing chunk should take. */
	public const TARGET_SECONDS = 4.0;

	private function __construct( private int $start, private int $max, private int $multiple, private bool $fixed ) {
	}

	/**
	 * @param int $size File size in bytes.
	 */
	public static function s3( int $size ): self {
		$part = self::s3PartSize( $size );

		return new self( $part, $part, self::MIB, true );
	}

	/**
	 * @param int $size File size in bytes.
	 * @return int Part size; above S3_MAX_PART when the file cannot be uploaded in 10 000 parts.
	 */
	public static function s3PartSize( int $size ): int {
		$part = max( self::S3_MIN_PART, intdiv( max( 0, $size ) + self::S3_MAX_PARTS - 1, self::S3_MAX_PARTS ) );

		return intdiv( $part + self::MIB - 1, self::MIB ) * self::MIB;
	}

	/**
	 * @param int $start    First chunk (rounded down to the multiple, at least one multiple).
	 * @param int $max      Largest chunk (rounded down to the multiple, at least one multiple).
	 * @param int $multiple Every chunk is a whole multiple of this (> 0).
	 * @throws \InvalidArgumentException On a multiple below 1.
	 */
	public static function growing( int $start, int $max, int $multiple ): self {
		if ( $multiple < 1 ) {
			throw new \InvalidArgumentException( 'A chunk multiple is at least one byte.' );
		}
		$max = max( $multiple, intdiv( $max, $multiple ) * $multiple );

		return new self( max( $multiple, min( $max, intdiv( $start, $multiple ) * $multiple ) ), $max, $multiple, false );
	}

	public function isFixed(): bool {
		return $this->fixed;
	}

	public function first(): int {
		return $this->start;
	}

	public function multiple(): int {
		return $this->multiple;
	}

	/**
	 * @param int   $current The chunk just sent.
	 * @param float $seconds How long it took.
	 */
	public function next( int $current, float $seconds ): int {
		if ( $this->fixed ) {
			return $this->start;
		}
		$want = $current;
		if ( $seconds < self::TARGET_SECONDS / 2 ) {
			$want = $current * 2;
		} elseif ( $seconds > self::TARGET_SECONDS * 2 ) {
			$want = intdiv( $current, 2 );
		}
		$want = max( $this->multiple, min( $this->max, $want ) );

		return intdiv( $want, $this->multiple ) * $this->multiple;
	}
}
