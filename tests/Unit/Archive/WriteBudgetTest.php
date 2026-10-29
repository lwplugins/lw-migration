<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\WriteLimitException;
use PHPUnit\Framework\TestCase;

final class WriteBudgetTest extends TestCase {

	private const MIB = 1048576;

	/**
	 * @dataProvider provide_margins
	 */
	public function test_the_margin_is_256_mib_or_5_percent_of_the_free_space_whichever_is_larger( int $free, int $margin ): void {
		$this->assertSame( $margin, WriteBudget::margin( $free ) );
	}

	/** @return array<string, array{0: int, 1: int}> */
	public static function provide_margins(): array {
		return [
			'small disk'              => [ 100 * self::MIB, 256 * self::MIB ],
			'5 % is exactly 256 MiB'  => [ 5120 * self::MIB, 256 * self::MIB ],
			'large disk: 5 %'         => [ 100 * 1024 * self::MIB, 5120 * self::MIB ],
			'nothing free'            => [ 0, 256 * self::MIB ],
		];
	}

	public function test_the_limit_is_the_free_space_minus_the_margin(): void {
		$this->assertSame( 744 * self::MIB, WriteBudget::forFreeSpace( 1000 * self::MIB, 1 )->limit() );
		$this->assertSame( 95 * 1024 * self::MIB, WriteBudget::forFreeSpace( 100 * 1024 * self::MIB, 1 )->limit() );
	}

	public function test_less_free_space_than_the_margin_allows_nothing(): void {
		$budget = WriteBudget::forFreeSpace( 100 * self::MIB, 1 );

		$this->assertSame( 0, $budget->limit() );
		$budget->spend( 0 );
		$this->expectException( WriteLimitException::class );
		$budget->spend( 1 );
	}

	public function test_unknown_free_space_uses_the_ceiling(): void {
		$this->assertSame( 12345, WriteBudget::forFreeSpace( null, 12345 )->limit() );
	}

	public function test_bytes_up_to_the_limit_pass_and_one_more_is_refused_without_being_counted(): void {
		$budget = new WriteBudget( 10, 4 );
		$budget->spend( 6 );
		$this->assertSame( 10, $budget->written() );
		try {
			$budget->spend( 1 );
			$this->fail( 'Expected a WriteLimitException.' );
		} catch ( WriteLimitException $e ) {
			$this->assertSame( 10, $budget->written() );
		}
	}

	public function test_a_refund_gives_bytes_back_but_never_goes_below_zero(): void {
		$budget = new WriteBudget( 10, 8 );
		$budget->refund( 5 );
		$this->assertSame( 3, $budget->written() );
		$budget->refund( 50 );
		$this->assertSame( 0, $budget->written() );
	}

	public function test_unlimited_counts_but_never_refuses(): void {
		$budget = WriteBudget::unlimited();
		$budget->spend( PHP_INT_MAX >> 1 );
		$this->assertNull( $budget->limit() );
		$this->assertSame( PHP_INT_MAX >> 1, $budget->written() );
	}

	public function test_the_state_survives_a_round_trip(): void {
		$budget = new WriteBudget( 1000, 250 );
		$copy   = WriteBudget::fromArray( $budget->toArray() );
		$this->assertSame( [ 'limit' => 1000, 'written' => 250 ], $copy->toArray() );
		$this->assertSame( [ 'limit' => null, 'written' => 0 ], WriteBudget::fromArray( [] )->toArray() );
	}
}
