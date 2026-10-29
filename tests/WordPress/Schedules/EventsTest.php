<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The cron table is modelled in memory: $planned holds the schedule events
 * (id => time), $continueAt the fallback continuation event.
 */
final class EventsTest extends MonkeyTestCase {

	private const NOW = 1767225600;

	/** @var array<string, int> */
	private array $planned = [];

	private ?int $continueAt = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		FakeOptions::install(
			[
				Options::SCHEDULES => [
					[
						'id'      => 'aaaaaaaa',
						'enabled' => true,
						'hour'    => 5,
					],
					[ 'id' => 'bbbbbbbb' ],
				],
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_next_scheduled' )->alias(
			fn( string $hook, array $args = [] ) => Events::HOOK === $hook ? ( $this->planned[ $args[0] ?? '' ] ?? false ) : ( $this->continueAt ?? false )
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook, array $args = [] ): int {
				unset( $this->planned[ $args[0] ?? '' ] );
				return 1;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook, array $args = [] ): bool {
				if ( Events::HOOK === $hook ) {
					$this->planned[ $args[0] ] = $time;
				} else {
					$this->assertSame( [ Events::CONTINUE_HOOK, [] ], [ $hook, $args ] );
					$this->continueAt = $time;
				}
				return true;
			}
		);
	}

	public function test_a_due_event_plans_the_next_run_and_queues_this_one_once(): void {
		$repository = new Repository();
		$queue      = new Queue();

		$this->assertTrue( Events::due( $repository, $queue, 'aaaaaaaa', self::NOW ) );
		$this->assertFalse( Events::due( $repository, $queue, 'aaaaaaaa', self::NOW + 60 ), 'A run still waiting is not queued twice.' );

		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => self::NOW ] ], $queue->all() );
		$this->assertSame( self::NOW + 5 * 3600, $this->planned['aaaaaaaa'], 'Next run: today 05:00 UTC.' );
	}

	public function test_an_unknown_or_disabled_schedule_loses_its_event_and_never_runs(): void {
		$this->planned = [
			'bbbbbbbb' => self::NOW,
			'ffffffff' => self::NOW,
		];
		$queue         = new Queue();

		$this->assertFalse( Events::due( new Repository(), $queue, 'bbbbbbbb', self::NOW ) );
		$this->assertFalse( Events::due( new Repository(), $queue, 'ffffffff', self::NOW ), 'Another site\'s schedule, carried in by an import.' );

		$this->assertSame( [], $this->planned );
		$this->assertSame( [], $queue->all() );
	}

	public function test_healing_restores_missing_events_and_drops_those_of_disabled_schedules(): void {
		$this->planned = [ 'bbbbbbbb' => self::NOW ];

		Events::heal( ( new Repository() )->existing(), self::NOW, static fn(): bool => false );

		$this->assertSame( [ 'aaaaaaaa' => self::NOW + 5 * 3600 ], $this->planned );
	}

	/**
	 * @dataProvider continuations
	 *
	 * @param bool     $left     Whether a run waits or a scheduled job is unfinished.
	 * @param int|null $planned  The continuation event already planned.
	 * @param bool     $expected Whether healing plans a new one.
	 */
	public function test_healing_asks_for_a_lost_continuation_while_work_is_left( bool $left, ?int $planned, bool $expected ): void {
		$this->continueAt = $planned;
		$asked            = false;

		Events::heal(
			( new Repository() )->existing(),
			self::NOW,
			static function () use ( $left, &$asked ): bool {
				$asked = true;
				return $left;
			}
		);

		$this->assertSame( $expected, null !== $this->continueAt && $planned !== $this->continueAt );
		$this->assertSame( null === $planned, $asked, 'Only asked while no continuation is planned.' );
	}

	/** @return array<string, array{0: bool, 1: int|null, 2: bool}> */
	public static function continuations(): array {
		return [
			'work left, continuation lost' => [ true, null, true ],
			'nothing left'                 => [ false, null, false ],
			'a continuation is planned'    => [ true, self::NOW + 60, false ],
		];
	}

	public function test_healing_the_site_looks_for_left_work_in_the_queue(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		( new Queue() )->add( 'aaaaaaaa', self::NOW - 600 ); // Its continuation event was lost.

		Events::healAll();

		$this->assertNotNull( $this->continueAt );
	}

	public function test_a_new_site_time_zone_moves_the_events_of_enabled_schedules(): void {
		FakeOptions::$values[ Options::SCHEDULES ][] = [
			'id'      => 'cccccccc',
			'enabled' => true,
			'hour'    => 6,
		];
		$this->planned = [
			'aaaaaaaa' => self::NOW + 5 * 3600, // 05:00 UTC.
			'bbbbbbbb' => self::NOW + 600,      // Switched off: healing takes it.
			'cccccccc' => self::NOW - 60,       // Due already: it fires and plans its next run itself.
		];
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Budapest' ) ); // UTC+1 in January.

		Events::resync( ( new Repository() )->existing(), self::NOW );

		ksort( $this->planned );
		$this->assertSame(
			[
				'aaaaaaaa' => self::NOW + 4 * 3600, // 05:00 in Budapest.
				'cccccccc' => self::NOW - 60,
			],
			$this->planned
		);
	}

	public function test_healing_never_rewrites_a_broken_storage_folder_name(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$db = FakeWpdb::install( [ Options::STORAGE_DIR => '../not-a-folder-name' ] );

		Events::healAll();

		$this->assertSame( '../not-a-folder-name', $db->value( Options::STORAGE_DIR ), 'An admin read writes nothing.' );
		$this->assertNull( $this->continueAt );
	}

	public function test_health_reports_late_events_and_a_switched_off_cron(): void {
		$schedules     = ( new Repository() )->existing();
		$this->planned = [ 'aaaaaaaa' => self::NOW - Events::LATE_AFTER + 1 ];
		$this->assertSame( [ 'disabled' => false, 'late' => false ], Events::health( $schedules, self::NOW, false ) );

		$this->planned = [ 'aaaaaaaa' => self::NOW - Events::LATE_AFTER - 1 ];
		$this->assertSame( [ 'disabled' => true, 'late' => true ], Events::health( $schedules, self::NOW, true ) );
	}

	public function test_one_fallback_continuation_at_a_time(): void {
		Events::continueIn( 60 );
		$first = $this->continueAt;
		Events::continueIn( 300 );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $this->continueAt, 'A pending continuation is not moved or doubled.' );
	}

	public function test_nothing_is_planned_outside_the_main_site(): void {
		$this->network( false );

		Events::sync( $this->enabled(), self::NOW );
		Events::healAll();
		Events::fire( 'aaaaaaaa' );
		Events::continueWork();
		Events::retime();

		$this->assertSame( [], $this->planned );
		$this->assertNull( $this->continueAt );
	}

	public function test_the_main_site_of_a_network_plans_its_schedules(): void {
		$this->network( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		Events::sync( $this->enabled(), self::NOW );
		$this->assertSame( [ 'aaaaaaaa' ], array_keys( $this->planned ) );

		$this->planned = [];
		Events::healAll();
		$this->assertSame( [ 'aaaaaaaa' ], array_keys( $this->planned ) );
	}

	private function enabled(): Schedule {
		return Schedule::fromArray(
			[
				'id'      => 'aaaaaaaa',
				'enabled' => true,
			]
		);
	}

	private function network( bool $mainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
	}

	public function test_a_due_event_of_the_running_schedule_coalesces_with_its_run(): void {
		$queue = new Queue();

		$this->assertFalse( Events::due( new Repository(), $queue, 'aaaaaaaa', self::NOW, 'aaaaaaaa' ), 'Its run outlasted the interval: no second run right after it.' );
		$this->assertTrue( Events::due( new Repository(), $queue, 'aaaaaaaa', self::NOW, 'bbbbbbbb' ), 'Another schedule\'s run makes it wait in the queue.' );

		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => self::NOW ] ], $queue->all() );
		$this->assertSame( self::NOW + 5 * 3600, $this->planned['aaaaaaaa'], 'The next run is planned either way.' );
	}

	public function test_admin_ajax_requests_do_not_heal(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Events::healAll();
		$this->assertSame( [], $this->planned, 'No schedule query on every heartbeat.' );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Events::healAll();
		$this->assertSame( [ 'aaaaaaaa' ], array_keys( $this->planned ) );
	}
	public function test_planning_the_next_run_is_apart_from_queueing_this_one(): void {
		$this->planned = [ 'ffffffff' => self::NOW ];

		$this->assertSame( 'aaaaaaaa', Events::plan( new Repository(), 'aaaaaaaa', self::NOW )?->id );
		$this->assertNull( Events::plan( new Repository(), 'ffffffff', self::NOW ) );

		$this->assertSame( [ 'aaaaaaaa' => self::NOW + 5 * 3600 ], $this->planned );
		$this->assertSame( [], ( new Queue() )->all(), 'Planning queues nothing.' );
		$this->assertTrue( Events::enqueue( new Queue(), 'aaaaaaaa', self::NOW, null ) );
		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => self::NOW ] ], ( new Queue() )->all() );
	}

	public function test_cron_requests_heal_and_other_requests_leave_it_to_admin_init(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Events::healOnCron();
		$this->assertSame( [], $this->planned );

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Events::healOnCron();
		$this->assertSame( [ 'aaaaaaaa' ], array_keys( $this->planned ) );
	}
}
