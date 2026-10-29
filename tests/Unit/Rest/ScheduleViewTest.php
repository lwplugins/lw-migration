<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Rest\ScheduleView;
use LightweightPlugins\Migration\Schedules\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleViewTest extends TestCase {

	private static function schedule( array $fields = [] ): Schedule {
		return Schedule::fromArray(
			array_merge(
				[
					'id'       => '0a1b2c3d',
					'title'    => 'Nightly',
					'enabled'  => true,
					'export'   => [
						'encrypt'      => true,
						'noMedia'      => true,
						'excludeFiles' => [ 'cache', 5 ],
						'replace'      => [ [ 'a', 'b' ], 'x' ],
					],
					'password' => 'lwm1:SEALED-VALUE',
				],
				$fields
			)
		);
	}

	public function test_the_item_never_carries_the_password(): void {
		$item = ScheduleView::of( self::schedule(), 2000, [ 2000, 3000 ], null, false );

		$this->assertStringNotContainsString( 'SEALED-VALUE', (string) json_encode( $item ) );
		$this->assertArrayNotHasKey( 'password', $item );
		$this->assertTrue( $item['hasPassword'] );
	}

	public function test_the_export_options_come_back_complete_for_the_form(): void {
		$export = ScheduleView::of( self::schedule(), null, [], null, false )['export'];

		$this->assertSame( array_merge( array_values( ExportOptions::FLAGS ), [ 'encrypt', 'excludeFiles', 'excludeTables', 'includeTables', 'replace', 'compression', 'sites' ] ), array_keys( $export ) );
		$this->assertSame( [], $export['sites'], 'No choice stored: the whole network (always on a single site).' );
		$this->assertTrue( $export['noMedia'] );
		$this->assertFalse( $export['noCache'] );
		$this->assertSame( [ 'cache' ], $export['excludeFiles'] );
		$this->assertSame( [ [ 'a', 'b' ] ], $export['replace'] );
		$this->assertSame( '', $export['compression'] );
	}

	public function test_the_chosen_sites_of_a_network_schedule_come_back_as_ids(): void {
		$export = ScheduleView::of( self::schedule( [ 'export' => [ 'sites' => [ 2, '3', 'x', -1, [ 4 ] ] ] ] ), null, [], null, false )['export'];

		$this->assertSame( [ 2 ], $export['sites'] );
	}

	public function test_a_disabled_schedule_has_no_next_run_and_the_last_run_is_summarised(): void {
		$last = [
			'job'       => 'abc',
			'trigger'   => 'cron',
			'status'    => 'failed',
			'startedAt' => 1,
			'endedAt'   => 2,
			'backup'    => '',
			'size'      => 0,
			'error'     => 'Disk full',
			'warnings'  => 0,
			'mail'      => 'sent',
		];
		$item = ScheduleView::of( self::schedule( [ 'enabled' => false ] ), 2000, [ 2000 ], $last, true );

		$this->assertNull( $item['nextRun'] );
		$this->assertSame( [], $item['upcoming'] );
		$this->assertSame(
			[
				'status'  => 'failed',
				'endedAt' => 2,
				'backup'  => '',
				'size'    => 0,
				'trigger' => 'cron',
				'error'   => 'Disk full',
				'cloud'   => [],
			],
			$item['lastRun']
		);
		$this->assertTrue( $item['running'] );
		$this->assertSame( [ 'when' => 'failure', 'email' => '' ], $item['notify'] );
	}

	public function test_the_cloud_part_comes_back_with_the_last_runs_destinations(): void {
		$schedule = self::schedule(
			[
				'destinations' => [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => 3 ] ] ],
				'keepLocal'    => false,
			]
		);
		$last     = [
			'status'  => 'partial',
			'endedAt' => 2,
			'backup'  => 'a.wpress',
			'size'    => 3,
			'trigger' => 'cron',
			'cloud'   => [ [ 'name' => 'One', 'status' => 'failed', 'error' => 'Denied.', 'secret' => 'x' ], 'junk' ],
		];

		$item = ScheduleView::of( $schedule, null, [], $last, false );

		$this->assertSame( [ [ 'id' => 'aaaa1111', 'retention' => [ 'count' => 3, 'bytes' => 0, 'days' => 0 ] ] ], $item['destinations'] );
		$this->assertFalse( $item['keepLocal'] );
		$this->assertSame( [ [ 'name' => 'One', 'status' => 'failed', 'error' => 'Denied.' ] ], $item['lastRun']['cloud'] );
		$this->assertTrue( ScheduleView::of( self::schedule(), null, [], null, false )['keepLocal'] );
	}

	public function test_a_last_run_without_an_error_field_has_an_empty_error(): void {
		$last = [
			'status'  => 'done',
			'endedAt' => 2,
			'backup'  => 'a.wpress',
			'size'    => 3,
			'trigger' => 'manual',
		];

		$item = ScheduleView::of( self::schedule(), null, [], $last, false );

		$this->assertSame( '', $item['lastRun']['error'] );
	}

	public function test_the_week_is_kept_while_on_and_empty_when_off(): void {
		$this->assertSame( [ 5, 9 ], ScheduleView::of( self::schedule(), null, [], null, false, [ 5, 9 ] )['week'] );
		$this->assertSame( [], ScheduleView::of( self::schedule( [ 'enabled' => false ] ), null, [], null, false, [ 5, 9 ] )['week'] );
		$this->assertSame( [], ScheduleView::of( self::schedule(), null, [], null, false )['week'] );
	}

	public function test_the_history_is_the_last_14_oldest_first_with_three_fields(): void {
		$log = [];
		for ( $i = 20; $i >= 1; $i-- ) { // Newest first, like Log::all().
			$log[] = [
				'trigger' => 1 === $i % 2 ? 'cron' : 'manual',
				'status'  => 5 === $i ? 'failed' : 'done',
				'endedAt' => $i,
				'backup'  => 'x.wpress',
				'size'    => 7,
			];
		}
		$history = ScheduleView::of( self::schedule(), null, [], $log[0], false, [], $log )['history'];

		$this->assertCount( 14, $history );
		$this->assertSame( range( 7, 20 ), array_column( $history, 'endedAt' ) );
		$this->assertSame( [ 'status', 'endedAt', 'trigger' ], array_keys( $history[0] ) );
		$this->assertSame( [ 'status' => 'done', 'endedAt' => 7, 'trigger' => 'cron' ], $history[0] );
	}

	public function test_an_empty_log_has_an_empty_history(): void {
		$this->assertSame( [], ScheduleView::of( self::schedule(), null, [], null, false )['history'] );
	}
}
