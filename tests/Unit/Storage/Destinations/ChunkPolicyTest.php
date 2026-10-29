<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\ChunkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChunkPolicyTest extends TestCase {

	/**
	 * @return array<string, array{0: int, 1: int}> Size => expected part size.
	 */
	public static function sizes(): array {
		$mib = ChunkPolicy::MIB;
		return [
			'one byte'              => [ 1, 5 * $mib ],
			'just under the floor'  => [ 50000 * $mib - 1, 5 * $mib ],
			'exactly 5 MiB × 10000' => [ 50000 * $mib, 5 * $mib ],
			'one byte more'         => [ 50000 * $mib + 1, 6 * $mib ],
			'60 GiB'                => [ 60 * 1024 * $mib, 7 * $mib ],
			'1 TiB'                 => [ 1024 * 1024 * $mib, 105 * $mib ],
		];
	}

	#[DataProvider( 'sizes' )]
	public function test_s3_part_size_is_the_floor_or_the_size_over_ten_thousand_rounded_up_to_a_mebibyte( int $size, int $part ): void {
		$this->assertSame( $part, ChunkPolicy::s3PartSize( $size ) );
	}

	/**
	 * Review Focus 1 (spec 21.10, R2: "All parts except the last must be the same size").
	 */
	public function test_s3_parts_are_equal_whole_mebibytes_and_never_more_than_ten_thousand(): void {
		foreach ( [ 1, 5242880, 5242881, 52428800001, 68719476736, 1099511627776, 5 * 1099511627776 ] as $size ) {
			$part = ChunkPolicy::s3PartSize( $size );
			$this->assertSame( 0, $part % ChunkPolicy::MIB, (string) $size );
			$this->assertGreaterThanOrEqual( ChunkPolicy::S3_MIN_PART, $part );
			$this->assertLessThanOrEqual( ChunkPolicy::S3_MAX_PARTS, (int) ceil( $size / $part ), (string) $size );
			$policy = ChunkPolicy::s3( $size );
			$this->assertTrue( $policy->isFixed() );
			$this->assertSame( $part, $policy->first() );
			foreach ( [ 0.01, 3.0, 60.0 ] as $seconds ) {
				$this->assertSame( $part, $policy->next( $part, $seconds ), 'A fixed policy never changes the part size.' );
			}
		}
	}

	public function test_a_size_past_ten_thousand_maximal_parts_is_reported_by_a_part_above_the_maximum(): void {
		$this->assertGreaterThan( ChunkPolicy::S3_MAX_PART, ChunkPolicy::s3PartSize( ChunkPolicy::S3_MAX_PART * ChunkPolicy::S3_MAX_PARTS + 1 ) );
	}

	public function test_a_growing_policy_doubles_when_fast_halves_when_slow_and_keeps_its_multiple(): void {
		$multiple = 327680; // OneDrive's 320 KiB (spec 21.10).
		$policy   = ChunkPolicy::growing( $multiple, 60 * ChunkPolicy::MIB - 1, $multiple );

		$this->assertFalse( $policy->isFixed() );
		$this->assertSame( $multiple, $policy->first() );
		$this->assertSame( 2 * $multiple, $policy->next( $multiple, 0.5 ), 'Fast: doubles.' );
		$this->assertSame( 4 * $multiple, $policy->next( 4 * $multiple, 4.0 ), 'Near the target: stays.' );
		$this->assertSame( 2 * $multiple, $policy->next( 4 * $multiple, 20.0 ), 'Slow: halves.' );
		$this->assertSame( $multiple, $policy->next( $multiple, 99.0 ), 'Never below one multiple.' );
		$top = $policy->next( 1000 * $multiple, 0.1 );
		$this->assertLessThan( 60 * ChunkPolicy::MIB, $top, 'Never above the maximum.' );
		$this->assertSame( 0, $top % $multiple, 'Always a whole multiple.' );
	}

	public function test_a_growing_policy_rounds_its_start_and_maximum_to_the_multiple(): void {
		$policy = ChunkPolicy::growing( 300000, 1000000, 262144 ); // Google Drive's 256 KiB.

		$this->assertSame( 262144, $policy->first() );
		$this->assertSame( 786432, $policy->next( 786432, 0.1 ) );
		$this->assertSame( 262144, $policy->multiple() );
	}
}
