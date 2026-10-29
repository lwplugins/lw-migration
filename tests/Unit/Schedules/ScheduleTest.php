<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Schedules;

use LightweightPlugins\Migration\Schedules\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

	public function test_a_stored_row_is_normalised_into_range(): void {
		$s = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'title'     => "  Nightly\x07 run ",
				'enabled'   => true,
				'frequency' => 'hours',
				'every'     => 500,
				'minute'    => -5,
				'hour'      => 99,
				'weekday'   => 0,
				'monthday'  => 40,
				'retention' => [ 'count' => -1, 'bytes' => '5', 'days' => 99999999 ],
				'notify'    => [ 'when' => 'sometimes', 'email' => ' a@b.test ' ],
				'unknown'   => 1,
			]
		);

		$this->assertSame( 'Nightly run', $s->title );
		$this->assertTrue( $s->enabled );
		$this->assertSame( [ 48, 0, 23, 1, 31 ], [ $s->every, $s->minute, $s->hour, $s->weekday, $s->monthday ] );
		$this->assertSame( [ 'count' => 0, 'bytes' => 5, 'days' => 36500 ], $s->retention );
		$this->assertSame( [ Schedule::NOTIFY_FAILURE, 'a@b.test' ], [ $s->notifyWhen, $s->notifyEmail ] );
		$this->assertArrayNotHasKey( 'unknown', $s->toArray() );
	}

	public function test_enabled_needs_a_real_true_and_a_missing_value_gets_its_default(): void {
		$s = Schedule::fromArray(
			[
				'id'      => '0a1b2c3d',
				'enabled' => '1',
			]
		);

		$this->assertFalse( $s->enabled );
		$this->assertSame( [ Schedule::DAILY, 1, 0, 3 ], [ $s->frequency, $s->every, $s->minute, $s->hour ] );
		$this->assertSame( [ 'count' => 7, 'bytes' => 0, 'days' => 0 ], $s->retention );
	}

	public function test_an_invalid_id_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Schedule::fromArray( [ 'id' => '../etc/x' ] );
	}

	public function test_every_is_one_for_the_fixed_frequencies(): void {
		$this->assertSame(
			1,
			Schedule::fromArray(
				[
					'id'        => '0a1b2c3d',
					'frequency' => 'weekly',
					'every'     => 7,
				]
			)->every
		);
		$this->assertSame(
			2,
			Schedule::fromArray(
				[
					'id'        => '0a1b2c3d',
					'frequency' => 'days',
					'every'     => 1,
				]
			)->every
		);
	}

	public function test_the_server_decides_the_kind_and_the_file_switch(): void {
		$s = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'export' => [
					'kind'    => 'pre-import',
					'noFiles' => true,
					'noMedia' => true,
				],
			]
		);

		$this->assertSame( [ 'noMedia' => true ], $s->export );
	}

	public function test_a_row_survives_a_round_trip(): void {
		$row = Schedule::fromArray(
			[
				'id'        => 'ffffffff',
				'title'     => 'Weekly',
				'enabled'   => true,
				'frequency' => 'weekly',
				'weekday'   => 7,
				'hour'      => 22,
				'minute'    => 45,
				'anchor'    => 100,
				'export'    => [ 'compression' => 'gzip' ],
				'password'  => 'lwm1:sealed',
				'notify'    => [
					'when'  => 'always',
					'email' => 'a@b.test',
				],
				'createdAt' => 5,
				'updatedAt' => 6,
			]
		)->toArray();

		$this->assertSame( $row, Schedule::fromArray( $row )->toArray() );
		$this->assertSame( [ 'when' => 'always', 'email' => 'a@b.test' ], $row['notify'] );
	}

	public function test_same_timing_ignores_title_export_and_notification(): void {
		$a = Schedule::fromArray(
			[
				'id'      => '0a1b2c3d',
				'enabled' => true,
			]
		);
		$b = Schedule::fromArray(
			[
				'id'      => '0a1b2c3d',
				'enabled' => true,
				'title'   => 'Other',
				'export'  => [ 'noMedia' => true ],
				'notify'  => [ 'when' => 'off' ],
			]
		);
		$c = Schedule::fromArray(
			[
				'id'      => '0a1b2c3d',
				'enabled' => true,
				'hour'    => 4,
			]
		);

		$this->assertTrue( $a->sameTiming( $b ) );
		$this->assertFalse( $a->sameTiming( $c ) );
		$this->assertFalse( $a->sameTiming( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ) ), 'Switching it on or off is a timing change.' );
	}

	public function test_new_ids_match_the_pattern(): void {
		$this->assertMatchesRegularExpression( Schedule::ID_PATTERN, Schedule::newId() );
		$this->assertNotSame( Schedule::newId(), Schedule::newId() );
	}

	public function test_destinations_are_kept_unique_valid_and_clamped_and_keep_local_defaults_on(): void {
		$s = Schedule::fromArray(
			[
				'id'           => '0a1b2c3d',
				'destinations' => [
					[
						'id'        => 'aaaa1111',
						'retention' => [ 'count' => 3, 'days' => -2 ],
					],
					[ 'id' => 'aaaa1111' ],
					[ 'id' => '../x' ],
					'junk',
					[ 'id' => 'bbbb2222' ],
				],
			]
		);

		$this->assertSame(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => [ 'count' => 3, 'bytes' => 0, 'days' => 0 ],
				],
				[
					'id'        => 'bbbb2222',
					'retention' => [ 'count' => 7, 'bytes' => 0, 'days' => 0 ],
				],
			],
			$s->destinations
		);
		$this->assertTrue( $s->keepLocal );
		$this->assertFalse( Schedule::fromArray( [ 'id' => '0a1b2c3d', 'keepLocal' => false ] )->keepLocal );
		$this->assertTrue( Schedule::fromArray( [ 'id' => '0a1b2c3d', 'keepLocal' => 'no' ] )->keepLocal, 'Only a real false switches it off.' );
	}
}
