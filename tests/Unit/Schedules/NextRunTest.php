<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Schedules;

use LightweightPlugins\Migration\Schedules\NextRun;
use LightweightPlugins\Migration\Schedules\Schedule;
use PHPUnit\Framework\TestCase;

/**
 * Site time zone Europe/Budapest: CEST (+02:00) until 2026-10-25 03:00, CET
 * (+01:00) after; summer time started 2026-03-29 02:00 → 03:00.
 * 2026-10-08 is a Thursday.
 */
final class NextRunTest extends TestCase {

	private static function tz(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Budapest' );
	}

	private static function ts( string $local ): int {
		return ( new \DateTimeImmutable( $local, self::tz() ) )->getTimestamp();
	}

	private static function local( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( self::tz() )->format( 'Y-m-d H:i' );
	}

	private static function zoned( int $ts, ?\DateTimeZone $tz = null ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?? self::tz() )->format( 'Y-m-d H:i P' );
	}

	/**
	 * @param array<int, int> $runs Unix times.
	 * @return array<int, string>
	 */
	private static function zonedAll( array $runs ): array {
		return array_map( static fn( int $ts ): string => self::zoned( $ts ), $runs );
	}

	/**
	 * @param array<string, mixed> $fields Schedule fields.
	 */
	private static function next( array $fields, string $now ): string {
		return self::local( NextRun::after( Schedule::fromArray( array_merge( [ 'id' => '0a1b2c3d' ], $fields ) ), self::ts( $now ), self::tz() ) );
	}

	public function test_daily_runs_today_when_the_time_is_still_ahead_else_tomorrow(): void {
		$this->assertSame( '2026-10-08 12:30', self::next( [ 'hour' => 12, 'minute' => 30 ], '2026-10-08 10:00' ) );
		$this->assertSame( '2026-10-09 03:00', self::next( [ 'hour' => 3 ], '2026-10-08 10:00' ) );
		$this->assertSame( '2026-10-09 10:00', self::next( [ 'hour' => 10 ], '2026-10-08 10:00' ), 'Strictly after now.' );
	}

	public function test_hourly_runs_at_its_minute(): void {
		$hourly = [
			'frequency' => 'hourly',
			'minute'    => 15,
		];
		$this->assertSame( '2026-10-08 10:15', self::next( $hourly, '2026-10-08 10:00' ) );
		$this->assertSame( '2026-10-08 11:15', self::next( $hourly, '2026-10-08 10:15' ) );
		$this->assertSame( '2026-10-08 11:15', self::next( $hourly, '2026-10-08 10:20' ) );
	}

	public function test_every_n_hours_counts_from_the_anchor(): void {
		$this->assertSame(
			'2026-10-08 14:00',
			self::next(
				[
					'frequency' => 'hours',
					'every'     => 6,
					'minute'    => 0,
					'anchor'    => self::ts( '2026-10-08 01:10' ),
				],
				'2026-10-08 10:00'
			),
			'02:00, 08:00, 14:00 …'
		);
	}

	public function test_every_n_days_counts_whole_days_from_the_anchor(): void {
		$this->assertSame(
			'2026-10-10 03:00',
			self::next(
				[
					'frequency' => 'days',
					'every'     => 3,
					'anchor'    => self::ts( '2026-10-01 09:00' ),
				],
				'2026-10-08 10:00'
			),
			'Oct 1, 4, 7, 10 …'
		);
	}

	public function test_weekly_runs_on_its_weekday(): void {
		$this->assertSame( '2026-10-12 03:00', self::next( [ 'frequency' => 'weekly', 'weekday' => 1 ], '2026-10-08 10:00' ) );
		$this->assertSame( '2026-10-15 03:00', self::next( [ 'frequency' => 'weekly', 'weekday' => 4 ], '2026-10-08 10:00' ), 'Today, but the time has passed.' );
	}

	public function test_monthly_clamps_to_the_last_day_of_a_short_month(): void {
		$this->assertSame( '2026-10-31 03:00', self::next( [ 'frequency' => 'monthly', 'monthday' => 31 ], '2026-10-08 10:00' ) );
		$this->assertSame( '2026-11-30 03:00', self::next( [ 'frequency' => 'monthly', 'monthday' => 31 ], '2026-11-05 10:00' ) );
		$this->assertSame( '2026-11-08 03:00', self::next( [ 'frequency' => 'monthly', 'monthday' => 8 ], '2026-10-08 10:00' ) );
		$this->assertSame( '2027-02-28 03:00', self::next( [ 'frequency' => 'monthly', 'monthday' => 30 ], '2027-02-01 10:00' ) );
	}

	public function test_a_time_inside_the_spring_gap_runs_right_after_it(): void {
		$daily = [
			'hour'   => 2,
			'minute' => 30,
		];
		$this->assertSame( '2026-03-29 03:30', self::next( $daily, '2026-03-28 12:00' ) );
		$this->assertSame( '2026-03-30 02:30', self::next( $daily, '2026-03-29 12:00' ) );
	}

	public function test_an_autumn_time_that_happens_twice_runs_once(): void {
		$daily = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'hour'   => 2,
				'minute' => 30,
			]
		);
		$first = NextRun::after( $daily, self::ts( '2026-10-24 12:00' ), self::tz() );

		$this->assertSame( '2026-10-25 02:30 +02:00', self::zoned( $first ) );
		$this->assertSame( '2026-10-26 02:30 +01:00', self::zoned( NextRun::after( $daily, $first, self::tz() ) ) );
	}

	public function test_an_ambiguous_autumn_time_is_always_its_first_occurrence(): void {
		$daily = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'hour'   => 2,
				'minute' => 30,
			]
		);

		$this->assertSame( strtotime( '2026-10-25 00:30:00 UTC' ), NextRun::after( $daily, self::ts( '2026-10-25 01:00' ), self::tz() ) );
		$this->assertSame( '2026-10-26 02:30 +01:00', self::zoned( NextRun::after( $daily, self::ts( '2026-10-25 02:00:00+01:00' ), self::tz() ) ), 'Inside the repeated hour, before the second 02:30.' );
		$this->assertSame( '2026-10-26 02:30 +01:00', self::zoned( NextRun::after( $daily, self::ts( '2026-10-25 02:45:00+01:00' ), self::tz() ) ), 'After a run at the first 02:30.' );
		$this->assertSame(
			'2026-11-25 02:30 +01:00',
			self::zoned(
				NextRun::after(
					Schedule::fromArray(
						[
							'id'        => '0a1b2c3d',
							'frequency' => 'monthly',
							'monthday'  => 25,
							'hour'      => 2,
							'minute'    => 30,
						]
					),
					self::ts( '2026-10-25 02:00:00+01:00' ),
					self::tz()
				)
			)
		);
	}

	public function test_an_every_of_zero_counts_as_one(): void {
		$hours = new Schedule( '0a1b2c3d', '', true, Schedule::HOURS, 0, 15 );
		$days  = new Schedule( '0a1b2c3d', '', true, Schedule::DAYS, 0, 0, 3 );

		$this->assertSame( '2026-10-08 10:15', self::local( NextRun::after( $hours, self::ts( '2026-10-08 10:00' ), self::tz() ) ) );
		$this->assertSame( '2026-10-09 03:00', self::local( NextRun::after( $days, self::ts( '2026-10-08 10:00' ), self::tz() ) ) );
	}

	public function test_now_exactly_at_the_slot_moves_to_the_next_one(): void {
		$this->assertSame( '2026-10-15 03:00', self::next( [ 'frequency' => 'weekly', 'weekday' => 4 ], '2026-10-08 03:00' ) );
		$this->assertSame( '2026-11-08 03:00', self::next( [ 'frequency' => 'monthly', 'monthday' => 8 ], '2026-10-08 03:00' ) );
		$this->assertSame(
			'2026-10-08 14:00',
			self::next(
				[
					'frequency' => 'hours',
					'every'     => 6,
					'minute'    => 0,
					'anchor'    => self::ts( '2026-10-08 01:10' ),
				],
				'2026-10-08 08:00'
			)
		);
	}

	public function test_an_offset_only_zone_keeps_its_half_hour(): void {
		$tz    = new \DateTimeZone( '+05:30' );
		$now   = ( new \DateTimeImmutable( '2026-10-08 10:00', $tz ) )->getTimestamp();
		$daily = Schedule::fromArray( [ 'id' => '0a1b2c3d' ] );
		$hours = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'hours',
				'every'     => 6,
				'minute'    => 0,
				'anchor'    => ( new \DateTimeImmutable( '2026-10-08 01:10', $tz ) )->getTimestamp(),
			]
		);

		$this->assertSame( '2026-10-09 03:00 +05:30', self::zoned( NextRun::after( $daily, $now, $tz ), $tz ) );
		$this->assertSame( '2026-10-08 14:00 +05:30', self::zoned( NextRun::after( $hours, $now, $tz ), $tz ) );
	}

	public function test_weekly_keeps_its_wall_time_across_both_changes(): void {
		$spring = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'weekly',
				'weekday'   => 7,
			]
		);
		$autumn = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'weekly',
				'weekday'   => 7,
				'hour'      => 2,
				'minute'    => 30,
			]
		);

		$this->assertSame( [ '2026-03-29 03:00 +02:00', '2026-04-05 03:00 +02:00' ], self::zonedAll( NextRun::upcoming( $spring, self::ts( '2026-03-22 12:00' ), self::tz(), 2 ) ) );
		$this->assertSame( [ '2026-10-25 02:30 +02:00', '2026-11-01 02:30 +01:00' ], self::zonedAll( NextRun::upcoming( $autumn, self::ts( '2026-10-18 12:00' ), self::tz(), 2 ) ) );
	}

	public function test_every_n_days_keeps_its_wall_time_across_both_changes(): void {
		$spring = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'days',
				'every'     => 2,
				'anchor'    => self::ts( '2026-03-27 09:00' ),
			]
		);
		$autumn = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'days',
				'every'     => 2,
				'anchor'    => self::ts( '2026-10-23 09:00' ),
			]
		);

		$this->assertSame( [ '2026-03-29 03:00 +02:00', '2026-03-31 03:00 +02:00' ], self::zonedAll( NextRun::upcoming( $spring, self::ts( '2026-03-28 12:00' ), self::tz(), 2 ) ) );
		$this->assertSame( [ '2026-10-25 03:00 +01:00', '2026-10-27 03:00 +01:00' ], self::zonedAll( NextRun::upcoming( $autumn, self::ts( '2026-10-24 12:00' ), self::tz(), 2 ) ) );
	}

	public function test_every_n_hours_steps_in_real_hours_across_the_autumn_change(): void {
		$hours = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'hours',
				'every'     => 2,
				'minute'    => 0,
				'anchor'    => self::ts( '2026-10-24 12:10' ),
			]
		);

		$this->assertSame(
			[ '2026-10-25 01:00 +02:00', '2026-10-25 02:00 +01:00', '2026-10-25 04:00 +01:00' ],
			self::zonedAll( NextRun::upcoming( $hours, self::ts( '2026-10-25 00:30' ), self::tz(), 3 ) )
		);
	}

	public function test_hourly_steps_in_real_hours_across_the_autumn_change(): void {
		$hourly = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'frequency' => 'hourly',
				'minute'    => 0,
			]
		);
		$runs   = NextRun::upcoming( $hourly, self::ts( '2026-10-25 00:30' ), self::tz(), 5 );

		$this->assertCount( 5, $runs );
		for ( $i = 1; $i < 5; $i++ ) {
			$this->assertSame( 3600, $runs[ $i ] - $runs[ $i - 1 ] );
		}
	}

	public function test_upcoming_lists_strictly_increasing_times(): void {
		$runs = NextRun::upcoming( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ), self::ts( '2026-10-08 10:00' ), self::tz(), 3 );

		$this->assertSame( [ '2026-10-09 03:00', '2026-10-10 03:00', '2026-10-11 03:00' ], array_map( [ self::class, 'local' ], $runs ) );
	}

	public function test_within_lists_a_week_of_daily_and_weekly_runs(): void {
		$now   = self::ts( '2026-10-08 10:00' );
		$until = $now + 7 * 86400;
		$daily = Schedule::fromArray( [ 'id' => '0a1b2c3d' ] );
		$week  = Schedule::fromArray( [ 'id' => '0a1b2c3d', 'frequency' => Schedule::WEEKLY, 'weekday' => 1 ] );

		$this->assertCount( 7, NextRun::within( $daily, $now, self::tz(), $until, 200 ) );
		$this->assertSame( [ '2026-10-12 03:00' ], array_map( [ self::class, 'local' ], NextRun::within( $week, $now, self::tz(), $until, 200 ) ) );
	}

	public function test_within_lists_hourly_runs_and_honours_the_cap(): void {
		$now    = self::ts( '2026-10-08 10:00' );
		$hourly = Schedule::fromArray( [ 'id' => '0a1b2c3d', 'frequency' => Schedule::HOURLY, 'minute' => 15 ] );

		$this->assertCount( 168, NextRun::within( $hourly, $now, self::tz(), $now + 7 * 86400, 200 ) );
		$this->assertCount( 200, NextRun::within( $hourly, $now, self::tz(), $now + 30 * 86400, 200 ) );
		$this->assertSame( [], NextRun::within( $hourly, $now, self::tz(), $now, 200 ) );
	}

	public function test_within_follows_upcoming_across_the_autumn_change(): void {
		$daily = Schedule::fromArray( [ 'id' => '0a1b2c3d', 'hour' => 2, 'minute' => 30 ] );
		$now   = self::ts( '2026-10-22 12:00' );
		$week  = NextRun::within( $daily, $now, self::tz(), $now + 7 * 86400, 200 );

		$this->assertSame( NextRun::upcoming( $daily, $now, self::tz(), 7 ), $week );
		$this->assertSame( [ '2026-10-23 02:30 +02:00', '2026-10-24 02:30 +02:00', '2026-10-25 02:30 +02:00', '2026-10-26 02:30 +01:00' ], array_slice( self::zonedAll( $week ), 0, 4 ) );
	}
}
