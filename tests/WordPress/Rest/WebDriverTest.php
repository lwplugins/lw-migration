<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\WebDownloadGuard;
use LightweightPlugins\Migration\Rest\WebDriver;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class WebDriverTest extends MonkeyTestCase {

	private const OTHER = 'ffffffffffffffff';

	private string $dir;

	private JobStore $jobs;

	private SiteLock $lock;

	private int $now = 1000;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db   = FakeWpdb::install();
		$this->dir  = TempDir::create();
		$this->jobs = new JobStore( $this->dir );
		$this->lock = new SiteLock();
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private static function step( \Closure $run ): StepInterface {
		return new class( $run ) implements StepInterface {
			public function __construct( private \Closure $run ) {
			}
			public function run( Job $job, Deadline $deadline ): StepResult {
				return ( $this->run )( $job );
			}
		};
	}

	/**
	 * @param array<string, StepInterface> $steps   Steps by key.
	 * @param bool                         $release Whether the finish hook releases the site lock.
	 */
	private function driver( array $steps, bool $release = true, float $seconds = 0.0 ): WebDriver {
		$lock   = $this->lock;
		$runner = new Runner(
			$this->jobs,
			WebDownloadGuard::wrap( $steps ),
			new MemorySecretStore(),
			[],
			static function ( Job $job ) use ( $lock, $release ): void {
				if ( $release ) {
					$lock->release( $job->id );
				}
			},
			static fn( Job $job ): bool => ! empty( $job->state['swapped'] )
		);

		return new WebDriver( $this->jobs, $this->lock, static fn( string $type ): Runner => $runner, fn(): int => $this->now, $seconds );
	}

	/**
	 * @param array<int, string>   $steps Step keys.
	 * @param array<string, mixed> $state Job state.
	 */
	private function job( string $type, array $steps, array $state = [], int $created = 1 ): Job {
		$job        = $this->jobs->create( $type, $steps, [], $created );
		$job->state = $state;
		$this->jobs->save( $job );

		return $job;
	}

	public function test_a_tick_refreshes_the_lock_and_runs_a_slice(): void {
		$job = $this->job( 'export', [ 'wait' ] );
		$this->assertTrue( $this->lock->acquire( $job->id, 1 ) );

		$after = $this->driver( [ 'wait' => self::step( static fn(): StepResult => StepResult::more( 50 ) ) ] )->tick( $job );

		$this->assertSame( Job::RUNNING, $after->status );
		$this->assertSame( 50, $after->progress );
		$this->assertSame( 1000 + SiteLock::TTL, $this->lock->expiresAt( 1000 ) );
	}

	public function test_an_expired_lock_is_taken_again_to_resume(): void {
		$job = $this->job( 'export', [ 'a' ] );
		$this->assertTrue( $this->lock->acquire( self::OTHER, 1 ) );
		$this->now = 5000;

		$after = $this->driver( [ 'a' => self::step( static fn(): StepResult => StepResult::more( 10 ) ) ] )->tick( $job );

		$this->assertSame( Job::RUNNING, $after->status );
		$this->assertSame( $job->id, $this->lock->holder( 5000 ) );
	}

	public function test_another_live_job_is_a_conflict_and_the_job_is_untouched(): void {
		$job = $this->job( 'export', [ 'a' ] );
		$this->assertTrue( $this->lock->acquire( self::OTHER, 900 ) );

		try {
			$this->driver( [ 'a' => self::step( static fn(): StepResult => StepResult::done() ) ] )->tick( $job );
			$this->fail( 'Ticked while another job holds the site.' );
		} catch ( RestException $e ) {
			$this->assertSame( 409, $e->status );
			$this->assertSame( ErrorMap::SITE_BUSY, $e->errorCode );
			$this->assertSame( SiteBusyException::describe( 900 + SiteLock::TTL ), $e->getMessage(), 'The same refusal as starting a job, with the expiry of the other lock.' );
			$this->assertStringContainsString( gmdate( 'Y-m-d H:i', 900 + SiteLock::TTL ), $e->getMessage() );
		}
		$this->assertSame( Job::PENDING, $this->jobs->load( $job->id )->status );
		$this->assertSame( self::OTHER, $this->lock->holder( 1000 ) );
	}

	public function test_a_job_left_behind_before_a_newer_job_ran_is_failed_instead_of_resumed(): void {
		$ran = false;
		$old         = $this->job( 'import', [ 'a' ] );
		$newer       = $this->job( 'export', [ 'a' ], [], 3000 );
		$newer->status = Job::DONE;
		$this->jobs->save( $newer );
		$this->now = 5000;
		$steps     = [
			'a' => self::step(
				static function () use ( &$ran ): StepResult {
					$ran = true;
					return StepResult::done();
				}
			),
		];

		$after = $this->driver( $steps )->tick( $old );

		$this->assertSame( Job::FAILED, $after->status );
		$this->assertStringContainsString( 'Another backup or restore ran on this site after this one stopped', $after->error );
		$this->assertFalse( $ran );
		$this->assertNull( $this->lock->holder( 5000 ), 'The failed job freed the site.' );
	}

	public function test_past_the_point_of_no_return_a_job_left_behind_always_resumes(): void {
		$old         = $this->job( 'import', [ 'post_import' ], [ 'swapped' => true ] );
		$newer       = $this->job( 'export', [ 'a' ], [], 3000 );
		$newer->status = Job::DONE;
		$this->jobs->save( $newer );
		$this->now = 5000;

		$after = $this->driver( [ 'post_import' => self::step( static fn(): StepResult => StepResult::more( 30 ) ) ] )->tick( $old );

		$this->assertSame( Job::RUNNING, $after->status );
		$this->assertSame( $old->id, $this->lock->holder( 5000 ) );
	}

	public function test_stray_output_is_discarded(): void {
		$job   = $this->job( 'export', [ 'noisy' ] );
		$level = ob_get_level();
		$this->expectOutputString( '' );

		$this->driver(
			[
				'noisy' => self::step(
					static function (): StepResult {
						echo 'Warning: rename(/srv/www/a,/mnt/b): Invalid cross-device link';
						return StepResult::done();
					}
				),
			]
		)->tick( $job );

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_whole_file_download_too_large_for_one_request_fails_the_job(): void {
		$ran   = false;
		$steps = [
			'url_download' => self::step(
				static function () use ( &$ran ): StepResult {
					$ran = true;
					return StepResult::done();
				}
			),
		];
		$big   = $this->job( 'import', [ 'url_download' ], [ 'download' => [ 'ranges' => false, 'size' => null ] ] );

		$failed = $this->driver( $steps )->tick( $big );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'cannot send the file in parts', $failed->error );
		$this->assertFalse( $ran );
		$this->assertNull( $this->lock->holder( 1000 ), 'The failed job freed the site.' );

		$small = $this->job( 'import', [ 'url_download' ], [ 'download' => [ 'ranges' => false, 'size' => 1000 ] ] );
		$this->assertSame( Job::DONE, $this->driver( $steps )->tick( $small )->status );
		$this->assertTrue( $ran );
	}

	public function test_a_post_swap_cancel_is_deferred_and_finished_by_ticks(): void {
		$job    = $this->job( 'import', [ 'post_import' ], [ 'swapped' => true ] );
		$driver = $this->driver( [ 'post_import' => self::step( static fn(): StepResult => StepResult::done() ) ] );

		$cancelled = $driver->runner( 'import' )->cancel( $job->id, 1000 );
		$this->assertFalse( $cancelled->isFinished() );
		$this->assertTrue( Runner::cancelDeferred( $cancelled ) );

		$this->assertSame( Job::DONE, $driver->tick( $this->jobs->load( $job->id ) )->status );
	}

	public function test_a_finished_job_comes_back_as_it_is(): void {
		$job         = $this->job( 'export', [ 'a' ] );
		$job->status = Job::DONE;
		$this->jobs->save( $job );

		$this->assertSame( Job::DONE, $this->driver( [] )->tick( $job )->status );
		$this->assertNull( $this->lock->holder( 1000 ), 'No lock is taken for a finished job.' );
	}

	public function test_a_job_cancelled_while_the_lock_is_taken_lets_it_go(): void {
		$job    = $this->job( 'export', [ 'a' ] );
		$driver = $this->driver( [ 'a' => self::step( static fn(): StepResult => StepResult::done() ) ], false );
		$this->db->beforeWrite = static function () use ( $driver, $job ): void {
			$driver->runner( 'export' )->cancel( $job->id, 1000 ); // Another request, between the status check and the INSERT.
		};

		$after = $driver->tick( $job );

		$this->assertSame( Job::CANCELLED, $after->status );
		$this->assertNull( $this->lock->holder( 1000 ) );
	}

	public function test_a_job_folder_gone_after_the_lock_was_taken_frees_the_site(): void {
		$job = $this->job( 'export', [ 'a' ] );
		$dir = $this->jobs->dir( $job->id );
		$this->db->beforeWrite = static function () use ( $dir ): void {
			TempDir::remove( $dir );
		};

		try {
			$this->driver( [] )->claim( $job->id );
			$this->fail( 'Claimed a job that is gone.' );
		} catch ( JobException $e ) {
			$this->assertNotInstanceOf( RestException::class, $e );
		}
		$this->assertNull( $this->lock->holder( 1000 ) );
	}

	public function test_a_newer_job_that_never_ran_does_not_stop_a_resume(): void {
		$old = $this->job( 'export', [ 'a' ] );
		$this->job( 'import', [ 'a' ], [], 3000 );
		$this->now = 5000;

		$after = $this->driver( [ 'a' => self::step( static fn(): StepResult => StepResult::more( 10 ) ) ] )->tick( $old );

		$this->assertSame( Job::RUNNING, $after->status );
	}

	public function test_a_whole_file_download_found_too_large_in_the_same_tick_fails_before_downloading(): void {
		$ran   = false;
		$steps = [
			'url_resolve'  => self::step(
				static function ( Job $job ): StepResult {
					$job->state['download'] = [
						'size'   => null,
						'ranges' => false,
					];
					return StepResult::done();
				}
			),
			'url_download' => self::step(
				static function () use ( &$ran ): StepResult {
					$ran = true;
					return StepResult::done();
				}
			),
		];
		$job   = $this->job( 'import', [ 'url_resolve', 'url_download' ] );

		$after = $this->driver( $steps, true, 8.0 )->tick( $job );

		$this->assertSame( Job::FAILED, $after->status );
		$this->assertStringContainsString( 'cannot send the file in parts', $after->error );
		$this->assertFalse( $ran );
		$this->assertNull( $this->lock->holder( 1000 ) );
	}

	public function test_a_finished_job_never_keeps_the_site_lock(): void {
		$job         = $this->job( 'export', [ 'a' ] );
		$job->status = Job::DONE;
		$this->jobs->save( $job );
		$this->assertTrue( $this->lock->acquire( $job->id, 1000 ) );

		$claimed = $this->driver( [] )->claim( $job->id );

		$this->assertSame( Job::DONE, $claimed->status );
		$this->assertNull( $this->lock->holder( 1000 ) );
	}

	public function test_a_tick_that_ends_the_job_frees_the_site(): void {
		$job = $this->job( 'export', [ 'a' ] );

		$after = $this->driver( [ 'a' => self::step( static fn(): StepResult => StepResult::done() ) ], false )->tick( $job );

		$this->assertSame( Job::DONE, $after->status );
		$this->assertNull( $this->lock->holder( 1000 ) );
	}

	public function test_a_claim_returns_the_job_as_saved_while_it_holds_the_site(): void {
		$job = $this->job( 'import', [ 'confirm' ] );

		$claimed = $this->driver( [] )->claim( $job->id );

		$this->assertSame( $job->id, $claimed->id );
		$this->assertSame( Job::PENDING, $claimed->status );
		$this->assertSame( $job->id, $this->lock->holder( 1000 ) );
	}
}
