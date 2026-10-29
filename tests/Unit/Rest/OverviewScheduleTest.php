<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Rest\OverviewSchedule;
use LightweightPlugins\Migration\Schedules\Schedule;
use PHPUnit\Framework\TestCase;

final class OverviewScheduleTest extends TestCase {

	/**
	 * @dataProvider provide_frequencies
	 */
	public function test_the_interval_is_the_longest_regular_gap_between_two_runs( string $frequency, int $every, int $seconds ): void {
		$schedule = new Schedule( '0a1b2c3d', 'Nightly', true, $frequency, $every );

		$this->assertSame( $seconds, OverviewSchedule::interval( $schedule ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: int}>
	 */
	public static function provide_frequencies(): array {
		return [
			'hourly'         => [ Schedule::HOURLY, 1, 3600 ],
			'every 6 hours'  => [ Schedule::HOURS, 6, 6 * 3600 ],
			'daily'          => [ Schedule::DAILY, 1, 86400 ],
			'every 3 days'   => [ Schedule::DAYS, 3, 3 * 86400 ],
			'weekly'         => [ Schedule::WEEKLY, 1, 7 * 86400 ],
			'monthly'        => [ Schedule::MONTHLY, 1, 31 * 86400 ],
			'every below 1'  => [ Schedule::DAYS, 0, 86400 ],
			'unknown is day' => [ 'yearly', 1, 86400 ],
		];
	}
}
