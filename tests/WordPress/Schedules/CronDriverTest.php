<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\WebDriver;
use LightweightPlugins\Migration\Schedules\CronDriver;
use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Notifier;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class CronDriverTest extends MonkeyTestCase {

	private const A = 'aaaaaaaa';

	private const B = 'bbbbbbbb';

	private string $dir;

	private JobStore $jobs;

	private SiteLock $lock;

	private Queue $queue;

	private FakeWpdb $db;

	private int $now = 1767225600;

	private float $clock = 0.0;

	/** @var \Closure fn( Job $job, string $key ): StepResult */
	private \Closure $behaviour;

	/** @var array<int, array{0: string|null, 1: int}> */
	private array $continued = [];

	/** @var array<int, string> Schedule ids in the order their jobs started. */
	private array $started = [];

	/** @var array<int, string> Jobs whose work files were discarded on giving up. */
	private array $discarded = [];

	/** Whether the recorder sees the schedules (false: it records nothing). */
	private bool $recorded = true;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
		$quiet = [ 'when' => 'off' ];
		FakeOptions::install(
			[
				Options::SCHEDULES => [
					[
						'id'      => self::A,
						'title'   => 'A',
						'enabled' => true,
						'notify'  => $quiet,
					],
					[
						'id'      => self::B,
						'title'   => 'B',
						'enabled' => true,
						'notify'  => $quiet,
					],
				],
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$this->dir       = TempDir::create();
		$this->jobs      = new JobStore( $this->dir );
		$this->lock      = new SiteLock();
		$this->queue     = new Queue();
		$this->behaviour = function ( Job $job, string $key ): StepResult {
			if ( 'init' === $key ) {
				$this->started[] = (string) $job->state['schedule']['id'];
			}
			return StepResult::done();
		};
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/** @return array<string, StepInterface> */
	private function steps(): array {
		$steps = [];
		foreach ( ExportPipeline::STEPS as $key ) {
			$steps[ $key ] = new class( fn( Job $job ): StepResult => ( $this->behaviour )( $job, $key ) ) implements StepInterface {
				public function __construct( private \Closure $run ) {
				}
				public function run( Job $job, Deadline $deadline ): StepResult {
					return ( $this->run )( $job );
				}
			};
		}

		return $steps;
	}

	private function recorder(): RunRecorder {
		// Like RunRecorder::fromServices(): a deleted schedule's run is not recorded.
		return new RunRecorder( new Notifier( static fn(): bool => true ), [], fn( string $id ): bool => $this->recorded && null !== ( new Repository() )->find( $id ) );
	}

	/**
	 * @param \Closure|null $site fn(): Site — runs inside start(), before the job is created.
	 */
	private function starter( ?\Closure $site = null ): ScheduleJobs {
		return new ScheduleJobs( $this->jobs, $this->lock, new MemorySecretStore(), $site ?? static fn(): Site => SiteFixture::make( '/s' ), new SealedPassword() );
	}

	/**
	 * @param ScheduleJobs|null $starter Starts the schedules' jobs (default: starter()).
	 * @param SiteLock|null     $lock    Site lock (default: the test's).
	 * @param float             $tick    Seconds per tick (0.0: one step per tick).
	 */
	private function driver( ?ScheduleJobs $starter = null, ?SiteLock $lock = null, float $tick = 0.05 ): CronDriver {
		$lock    ??= $this->lock;
		$calls     = 0;
		$now       = function () use ( &$calls ): int {
			if ( ++$calls > 5000 ) {
				throw new \LogicException( 'The driver loops.' ); // A regression fails instead of hanging.
			}
			return $this->now;
		};
		$recorder  = $this->recorder();
		$runnerFor = fn( string $type ): Runner => new Runner(
			$this->jobs,
			$this->steps(),
			new MemorySecretStore(),
			[],
			function ( Job $job ) use ( $recorder ): void { // What Services::finished does for these jobs.
				$recorder->finished( $job, $this->now );
				$this->lock->release( $job->id );
			},
			static fn( Job $job ): bool => ! empty( $job->state['swapped'] ) // An import past its point of no return.
		);

		return new CronDriver(
			$this->jobs,
			$lock,
			new WebDriver( $this->jobs, $lock, $runnerFor, $now, $tick ),
			$this->queue,
			new Repository( $now ),
			$starter ?? $this->starter(),
			$recorder,
			function ( ?string $jobId, int $delay ): void {
				$this->continued[] = [ $jobId, $delay ];
			},
			$now,
			fn(): float => $this->clock += 1.0,
			function ( Job $job ): void {
				$this->discarded[] = $job->id;
			}
		);
	}

	public function test_a_queued_schedule_runs_to_its_end_and_is_logged(): void {
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( [ self::A ], $this->started );
		$this->assertSame( [ 'cron', 'done' ], [ Log::last( self::A )['trigger'] ?? '', Log::last( self::A )['status'] ?? '' ] );
		$this->assertSame( [], $this->queue->all() );
		$this->assertNull( $this->lock->holder( $this->now ) );
		$this->assertSame( [], $this->continued );
	}

	public function test_due_schedules_start_oldest_first_one_at_a_time(): void {
		$this->queue->add( self::A, $this->now - 10 );
		$this->queue->add( self::B, $this->now - 60 );

		$this->driver()->work( null );

		$this->assertSame( [ self::B, self::A ], $this->started );
		$this->assertSame( 'done', Log::last( self::A )['status'] ?? '' );
		$this->assertSame( 'done', Log::last( self::B )['status'] ?? '' );
	}

	public function test_a_slice_hands_over_with_a_loopback_when_its_budget_is_spent(): void {
		$this->behaviour = static function ( Job $job, string $key ): StepResult {
			if ( 'content' !== $key ) {
				return StepResult::done();
			}
			$job->cursor['n'] = (int) ( $job->cursor['n'] ?? 0 ) + 1; // Progress, so the attempt guard never stops it.
			return StepResult::more( 50 );
		};
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::MORE, $this->driver()->work( 2.0 ) );

		$holder = (string) $this->lock->holder( $this->now );
		$this->assertSame( Job::RUNNING, $this->jobs->load( $holder )->status );
		$this->assertSame( [ [ $holder, 0 ] ], $this->continued, 'The next slice is asked for right away.' );
		$this->assertNull( Log::last( self::A ) );
	}

	public function test_a_browser_job_makes_schedules_wait(): void {
		$other = $this->jobs->create( 'export', ExportPipeline::STEPS, [], $this->now );
		$this->assertTrue( $this->lock->acquire( $other->id, $this->now ) );
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::BUSY, $this->driver()->work( 20.0 ) );

		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
		$this->assertSame( [ self::A ], array_column( $this->queue->all(), 'id' ), 'It still waits.' );
		$this->assertSame( Job::PENDING, $this->jobs->load( $other->id )->status, 'The other job is not touched.' );
	}

	public function test_an_abandoned_scheduled_job_is_finished_before_the_next_starts(): void {
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now - 100 );
		$this->lock->release( $job->id ); // Its driver went away.
		$this->queue->add( self::B, $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( Job::DONE, $this->jobs->load( $job->id )->status );
		$this->assertSame( [ self::A, self::B ], $this->started );
	}

	public function test_a_job_driven_elsewhere_is_left_to_it(): void {
		$job    = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now );
		$handle = $this->jobs->acquire( $job->id ); // Another process is in a tick of it.
		try {
			$this->assertSame( CronDriver::ELSEWHERE, $this->driver()->work( null ) );
		} finally {
			if ( null !== $handle ) {
				$this->jobs->release( $handle );
			}
		}

		$this->assertSame( Job::PENDING, $this->jobs->load( $job->id )->status );
		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
	}

	public function test_a_stale_scheduled_job_is_failed_and_logged(): void {
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now - CronDriver::STALE - 10 );

		$this->driver()->work( null );

		$failed = $this->jobs->load( $job->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'WP-Cron', $failed->error );
		$this->assertSame( 'failed', Log::last( self::A )['status'] ?? '' );
		$this->assertSame( [], $this->started, 'It is not driven any more.' );
	}

	public function test_a_run_that_cannot_start_is_logged_and_the_next_one_goes(): void {
		FakeOptions::$values[ Options::SCHEDULES ][0]['export']   = [ 'encrypt' => true ];
		FakeOptions::$values[ Options::SCHEDULES ][0]['password'] = 'lwm1:not-openable';
		$this->queue->add( self::A, $this->now - 60 );
		$this->queue->add( self::B, $this->now );

		$this->driver()->work( null );

		$this->assertSame( [ '', 'failed' ], [ Log::last( self::A )['job'] ?? null, Log::last( self::A )['status'] ?? '' ] );
		$this->assertStringContainsString( 'password', (string) ( Log::last( self::A )['error'] ?? '' ) );
		$this->assertSame( [ self::B ], $this->started );
		$this->assertSame( [], $this->queue->all() );
	}

	public function test_an_unexpected_start_failure_is_logged_without_naming_wp_content(): void {
		$this->queue->add( self::A, $this->now );

		$this->driver( $this->starter( static fn(): Site => throw new \RuntimeException( 'disk' ) ) )->work( null );

		$this->assertSame( 'failed', Log::last( self::A )['status'] ?? '' );
		$this->assertSame( "The scheduled backup could not start. Check that the plugin's folders and the backups folder are writable.", Log::last( self::A )['error'] ?? '' );
	}

	public function test_a_run_waiting_a_day_is_skipped(): void {
		$this->queue->add( self::A, $this->now - CronDriver::QUEUE_LIMIT - 1 );

		$this->driver()->work( null );

		$this->assertSame( RunRecorder::SKIPPED, Log::last( self::A )['status'] ?? '' );
		$this->assertSame( [], $this->started );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_a_fresh_run_queued_onto_a_day_old_waiting_one_runs(): void {
		$this->queue->add( self::A, $this->now - CronDriver::QUEUE_LIMIT - 120 ); // Queued yesterday; its continuation event was lost.

		$schedule = ( new Repository() )->find( self::A );
		$queued   = Events::enqueue( $this->queue, self::A, $this->now, null, Events::skipped( $this->recorder(), $schedule, $this->now ) ); // Today's event fires.
		$this->driver()->work( null );

		$this->assertTrue( $queued, 'The fresh run takes the old one\'s place.' );
		$this->assertSame( [ self::A ], $this->started );
		$this->assertSame(
			[ [ RunRecorder::CRON, 'done' ], [ RunRecorder::CRON, RunRecorder::SKIPPED ] ],
			array_map( static fn( array $entry ): array => [ $entry['trigger'], $entry['status'] ], Log::all( self::A ) ),
			'The replaced run is logged as skipped once.'
		);
	}

	public function test_a_disabled_or_deleted_schedule_leaves_the_queue(): void {
		FakeOptions::$values[ Options::SCHEDULES ][0]['enabled'] = false;
		$this->queue->add( self::A, $this->now );
		$this->queue->add( 'cccccccc', $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( [], $this->queue->all() );
		$this->assertSame( [], $this->started );
	}

	public function test_nothing_runs_outside_the_main_site(): void {
		$this->network( false );
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );
		$this->assertSame( [ self::A ], array_column( $this->queue->all(), 'id' ) );
		$this->assertSame( [], $this->started );
	}

	public function test_the_main_site_of_a_network_runs_its_schedules(): void {
		$this->network( true );
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );
		$this->assertSame( [ self::A ], $this->started );
		$this->assertSame( [], $this->queue->all() );
	}

	private function network( bool $mainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		Functions\when( 'get_networks' )->justReturn( [ (object) [ 'id' => 1, 'domain' => 'net.test', 'path' => '/' ] ] );
	}

	/**
	 * @dataProvider budgets
	 *
	 * @param float $filtered What the filter returns.
	 * @param int   $limit    max_execution_time.
	 * @param float $expected Budget.
	 */
	public function test_the_web_budget_follows_the_filter_and_leaves_a_tick_inside_half_the_time_limit( float $filtered, int $limit, float $expected ): void {
		Filters\expectApplied( 'lw_migration_cron_seconds' )->once()->andReturn( $filtered );
		$before = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', (string) $limit );
		try {
			$this->assertSame( $expected, CronDriver::budget( false ) );
		} finally {
			ini_set( 'max_execution_time', (string) $before );
		}
	}

	/** @return array<string, array{0: float, 1: int, 2: float}> */
	public static function budgets(): array {
		return [
			'half of 30 s less one 8 s tick' => [ 50.0, 30, 7.0 ],
			'no time limit'                  => [ 50.0, 0, 50.0 ],
			'not a number'                   => [ NAN, 0, CronDriver::BUDGET ],
			'endless'                        => [ INF, 0, CronDriver::BUDGET ],
			'negative'                       => [ -5.0, 0, 1.0 ],
			'a limit too short for a tick'   => [ 50.0, 10, 1.0 ],
		];
	}

	public function test_wp_cli_works_without_a_budget(): void {
		$this->assertNull( CronDriver::budget( true ) );
	}

	public function test_an_abandoned_restore_past_its_point_of_no_return_makes_schedules_wait(): void {
		$import                   = $this->jobs->create( 'import', [ 'swap' ], [], $this->now - 3600 );
		$import->state['swapped'] = true;
		$this->jobs->save( $import ); // Its browser went away; the site lock expired long ago.
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::BUSY, $this->driver()->work( 20.0 ) );

		$this->assertSame( [], $this->started, 'A half-restored site is never backed up.' );
		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
		$this->assertSame( [ self::A ], array_column( $this->queue->all(), 'id' ) );
		$this->assertNull( $this->lock->holder( $this->now ), 'The lock stays free for the restore to resume.' );
	}

	public function test_an_abandoned_restore_before_its_point_of_no_return_does_not_block_schedules(): void {
		$this->jobs->create( 'import', [ 'swap' ], [], $this->now - 3600 );
		$this->queue->add( self::A, $this->now );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( [ self::A ], $this->started );
	}

	public function test_a_job_whose_save_fails_stops_the_call(): void {
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now );
		$tmp = $this->jobs->dir( $job->id ) . '/job.json.tmp';
		mkdir( $tmp ); // job.json cannot be replaced any more: a full disk, as far as the job knows.
		$looped   = false;
		$warnings = 0;
		set_error_handler( // Takes the failed writes' PHP warnings; ends an endless loop, so a regression fails instead of hanging.
			static function () use ( &$looped, &$warnings, $tmp ): bool {
				if ( ++$warnings >= 20 && is_dir( $tmp ) ) {
					$looped = true;
					rmdir( $tmp );
				}
				return true;
			}
		);
		try {
			$result = $this->driver()->work( null );
		} finally {
			restore_error_handler();
			if ( is_dir( $tmp ) ) {
				rmdir( $tmp );
			}
		}

		$this->assertSame( CronDriver::STUCK, $result );
		$this->assertFalse( $looped, 'Tried once, not again and again.' );
		$this->assertSame( $job->id, $this->lock->holder( $this->now ), 'Within the lock time it keeps the site for the next try.' );
		$this->assertSame( Job::PENDING, $this->jobs->load( $job->id )->status );
		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
	}

	public function test_a_stale_job_driven_elsewhere_is_left_to_it(): void {
		$job    = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now - CronDriver::STALE - 10 );
		$handle = $this->jobs->acquire( $job->id );
		try {
			$this->assertSame( CronDriver::ELSEWHERE, $this->driver()->work( null ) );
		} finally {
			if ( null !== $handle ) {
				$this->jobs->release( $handle );
			}
		}

		$this->assertSame( Job::PENDING, $this->jobs->load( $job->id )->status );
		$this->assertNull( Log::last( self::A ) );
	}

	public function test_a_run_leaves_the_queue_before_it_starts_and_waits_again_when_the_site_was_taken(): void {
		$other   = $this->jobs->create( 'export', ExportPipeline::STEPS, [], $this->now );
		$queued  = [];
		$starter = $this->starter(
			function () use ( $other, &$queued ): Site {
				$queued = $this->queue->all();
				$this->lock->acquire( $other->id, $this->now ); // A browser took the site between the check and the start.
				return SiteFixture::make( '/s' );
			}
		);
		$this->queue->add( self::A, $this->now - 60 );

		$this->assertSame( CronDriver::BUSY, $this->driver( $starter )->work( 20.0 ) );

		$this->assertSame( [], $queued, 'Removed before the start: a process dying there never runs it twice.' );
		$this->assertSame( [ [ 'id' => self::A, 'due' => $this->now - 60 ] ], $this->queue->all(), 'Queued again with its due time.' );
		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
	}

	public function test_the_running_schedule_is_named_for_the_due_check(): void {
		$driver = $this->driver();
		$this->assertNull( $driver->runningSchedule() );

		$this->starter()->start( Schedule::fromArray( [ 'id' => self::B, 'enabled' => true ] ), RunRecorder::CRON, $this->now );

		$this->assertSame( self::B, $driver->runningSchedule() );
	}
	public function test_a_job_that_cannot_be_saved_for_the_lock_time_is_given_up_once(): void {
		$t0  = $this->now;
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $t0 );
		$tmp = $this->jobs->dir( $job->id ) . '/job.json.tmp';
		mkdir( $tmp ); // No save succeeds from here on.
		set_error_handler( static fn(): bool => true ); // The failed writes' PHP warnings.
		try {
			$this->now = $t0 + 300;
			$this->assertSame( CronDriver::STUCK, $this->driver()->work( 20.0 ) );
			$this->assertNull( Log::last( self::A ) );

			$this->now = $t0 + SiteLock::TTL;
			$this->queue->add( self::B, $this->now );
			$given = $this->driver()->work( 20.0 );
			$free  = null === $this->lock->holder( $this->now );

			$this->now = $t0 + CronDriver::STALE + 10;
			$later     = $this->driver()->work( 20.0 );
		} finally {
			restore_error_handler();
			rmdir( $tmp );
		}

		$this->assertSame( [ CronDriver::IDLE, CronDriver::IDLE ], [ $given, $later ] );
		$this->assertTrue( $free, 'Its site lock is let go.' );
		$this->assertSame( [ self::B ], $this->started, 'The next schedule runs.' );
		$entries = Log::all( self::A );
		$this->assertCount( 1, $entries, 'Logged (and mailed) once; the stale path never sees it again.' );
		$this->assertSame( [ $job->id, 'failed' ], [ $entries[0]['job'], $entries[0]['status'] ] );
		$this->assertSame( [ $job->id ], $this->discarded, 'Its work files go, so the next run has room.' );
		$this->assertStringContainsString( 'could not be saved', (string) $entries[0]['error'] );
		$this->assertNull( $this->driver()->runningSchedule() );
	}

	public function test_a_long_run_saved_a_moment_ago_is_not_given_up_on_its_first_failed_save(): void {
		$t0              = $this->now;
		$tmp             = '';
		$this->behaviour = function ( Job $job, string $key ) use ( $t0, &$tmp ): StepResult {
			[ $first, $second ] = ExportPipeline::STEPS;
			if ( $first === $key ) {
				$this->now = $t0 + SiteLock::TTL + 100; // A long run from the command line: the next save is an hour after the start.
			} elseif ( $second !== $key ) {
				$tmp = $this->jobs->dir( $job->id ) . '/job.json.tmp';
				mkdir( $tmp ); // From the third step on, no save succeeds.
			}
			return StepResult::done();
		};
		$this->queue->add( self::A, $t0 );
		set_error_handler( static fn(): bool => true ); // The failed writes' PHP warnings.
		try {
			$result = $this->driver( null, null, 0.0 )->work( null );
		} finally {
			restore_error_handler();
			if ( '' !== $tmp && is_dir( $tmp ) ) {
				rmdir( $tmp );
			}
		}

		$this->assertSame( CronDriver::STUCK, $result, 'Saved a moment ago: it waits for the next try.' );
		$this->assertNull( Log::last( self::A ), 'Not logged as given up.' );
		$this->assertSame( [], $this->discarded );
		$this->assertNotNull( $this->lock->holder( $this->now ), 'It keeps the site.' );
	}

	public function test_a_run_another_request_took_is_left_to_it(): void {
		$this->queue->add( self::A, $this->now );
		$this->db->beforeWrite = static function (): void {
			( new Queue() )->take( self::A ); // Another driver read the same queue and took the entry first.
		};

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( [], $this->started );
	}

	public function test_a_run_another_request_started_runs_once(): void {
		$starter = $this->starter(
			function (): Site {
				// Another driver started the same run between our take and our start.
				$this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now );
				return SiteFixture::make( '/s' );
			}
		);
		$this->queue->add( self::A, $this->now - 60 );

		$this->assertSame( CronDriver::BUSY, $this->driver( $starter )->work( 20.0 ) );
		$this->assertSame( [], $this->queue->all(), 'Not queued again behind its own run.' );
		$this->driver()->work( null );

		$this->assertSame( [ self::A ], $this->started );
	}

	public function test_a_failure_asks_for_a_later_call_and_is_passed_on(): void {
		$broken = new SiteLock(
			new class() {
				/** @var string */
				public $options = 'wp_options';

				/**
				 * @param string            $name Method.
				 * @param array<int, mixed> $args Arguments.
				 * @return never
				 */
				public function __call( string $name, array $args ) {
					throw new \RuntimeException( 'The database went away.' );
				}
			}
		);

		try {
			$this->driver( null, $broken )->work( 20.0 );
			$this->fail( 'The failure is passed on.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The database went away.', $e->getMessage() );
		}

		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
	}
	/**
	 * @dataProvider deletions
	 *
	 * @param bool $givenUpFirst Whether it was given up before its schedule was deleted.
	 */
	public function test_a_stuck_job_of_a_deleted_schedule_is_given_up_and_the_next_runs( bool $givenUpFirst ): void {
		$t0  = $this->now;
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $t0 );
		$tmp = $this->jobs->dir( $job->id ) . '/job.json.tmp';
		mkdir( $tmp );
		set_error_handler( static fn(): bool => true ); // The failed writes' PHP warnings.
		try {
			if ( $givenUpFirst ) {
				$this->now = $t0 + SiteLock::TTL;
				$this->driver()->work( 20.0 );
			}
			( new Repository( fn(): int => $this->now ) )->delete( self::A ); // Its run log goes too.
			$this->now = $t0 + SiteLock::TTL + 60;
			$this->queue->add( self::B, $this->now );

			$web = $this->driver()->work( 20.0 );
			$cli = $this->driver()->work( null );
		} finally {
			restore_error_handler();
			rmdir( $tmp );
		}

		$this->assertSame( [ CronDriver::IDLE, CronDriver::IDLE ], [ $web, $cli ] );
		$this->assertSame( [ self::B ], $this->started );
		$this->assertNull( $this->lock->holder( $this->now ) );
	}

	/** @return array<string, array{0: bool}> */
	public static function deletions(): array {
		return [
			'stuck, then deleted'    => [ false ],
			'given up, then deleted' => [ true ],
		];
	}

	public function test_a_job_of_a_deleted_schedule_that_still_saves_is_continued(): void {
		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now - SiteLock::TTL + 60 ); // Last saved 29 minutes ago.
		( new Repository( fn(): int => $this->now ) )->delete( self::A );

		$this->assertSame( CronDriver::IDLE, $this->driver()->work( null ) );

		$this->assertSame( Job::DONE, $this->jobs->load( $job->id )->status );
		$this->assertSame( [ self::A ], $this->started );
	}

	public function test_a_job_given_up_twice_in_one_call_stops_the_call(): void {
		$this->recorded = false; // Its end cannot be recorded, so nothing marks it as over.
		$job            = $this->starter()->start( Schedule::fromArray( [ 'id' => self::A, 'enabled' => true ] ), RunRecorder::CRON, $this->now );
		$tmp            = $this->jobs->dir( $job->id ) . '/job.json.tmp';
		mkdir( $tmp );
		$this->now += SiteLock::TTL;
		set_error_handler( static fn(): bool => true );
		try {
			$result = $this->driver()->work( 20.0 );
		} finally {
			restore_error_handler();
			rmdir( $tmp );
		}

		$this->assertSame( CronDriver::STUCK, $result );
		$this->assertSame( [ [ null, CronDriver::WAIT ] ], $this->continued );
		$this->assertSame( [ $job->id ], $this->discarded );
	}
}
