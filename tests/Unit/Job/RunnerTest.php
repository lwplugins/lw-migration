<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private static function counter( int $units ): StepInterface {
		return new class( $units ) implements StepInterface {
			public function __construct( private int $units ) {
			}
			public function run( Job $job, Deadline $deadline ): StepResult {
				$done = (int) ( $job->cursor['done'] ?? 0 );
				while ( $done < $this->units ) {
					++$done;
					$job->cursor['done'] = $done;
					if ( $done < $this->units && $deadline->reached() ) {
						return StepResult::more( (int) ( 100 * $done / $this->units ), "unit {$done}" );
					}
				}
				$job->state['ran'][] = $this->units;
				return StepResult::done();
			}
		};
	}

	public function test_runs_all_steps_in_one_tick_without_deadline(): void {
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'a' => self::counter( 3 ), 'b' => self::counter( 2 ) ] );
		$job    = $store->create( 'export', [ 'a', 'b' ], [], 1 );

		$job = $runner->tick( $job->id, new NoDeadline(), 2 );
		$this->assertSame( Job::DONE, $job->status );
		$this->assertSame( 100, $job->progress );
		$this->assertSame( [ 3, 2 ], $job->state['ran'] );
		$this->assertSame( 2, $job->updatedAt );
	}

	public function test_resumes_across_ticks_and_resets_cursor_between_steps(): void {
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'a' => self::counter( 3 ), 'b' => self::counter( 2 ) ] );
		$id     = $store->create( 'export', [ 'a', 'b' ], [], 1 )->id;

		$ticks = 0;
		do {
			$job = $runner->tick( $id, new CountdownDeadline( 0 ), 1 );
			++$ticks;
		} while ( ! $job->isFinished() && $ticks < 20 );

		$this->assertSame( Job::DONE, $job->status );
		$this->assertGreaterThan( 2, $ticks );
		$this->assertSame( [ 3, 2 ], $job->state['ran'] );
	}

	public function test_a_step_that_needs_a_new_request_ends_the_tick_before_the_deadline(): void {
		$store  = new JobStore( $this->dir );
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$calls               = (int) ( $job->cursor['calls'] ?? 0 ) + 1;
				$job->cursor['calls'] = $calls;
				return 1 === $calls ? StepResult::nextRequest( 50, 'waiting' ) : StepResult::done();
			}
		};
		$runner = new Runner( $store, [ 'a' => $step ] );
		$id     = $store->create( 'export', [ 'a' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( Job::RUNNING, $job->status, 'Not called again in the same tick.' );
		$this->assertSame( 1, $job->cursor['calls'] );
		$this->assertSame( 50, $job->progress );
		$this->assertSame( 'waiting', $job->message );
		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 3 )->status );
	}

	public function test_needs_input_then_provide_input(): void {
		$step = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				if ( ! isset( $job->secrets['password'] ) ) {
					return StepResult::needsInput( 'password', [ 'reason' => 'encrypted' ] );
				}
				return StepResult::done();
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'decrypt' => $step ] );
		$id     = $store->create( 'import', [ 'decrypt' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 1 );
		$this->assertSame( Job::NEEDS_INPUT, $job->status );
		$this->assertSame( [ 'kind' => 'password', 'data' => [ 'reason' => 'encrypted' ] ], $job->input );

		$this->assertSame( Job::NEEDS_INPUT, $runner->tick( $id, new NoDeadline(), 2 )->status );

		$runner->provideInput( $id, [ 'password' => 'hunter2-secret' ], 3 );
		$this->assertStringNotContainsString( 'hunter2-secret', (string) file_get_contents( $store->dir( $id ) . '/job.json' ) );
		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 4 )->status );
		$this->assertStringNotContainsString( 'hunter2-secret', (string) file_get_contents( $store->dir( $id ) . '/job.json' ) );
	}

	public function test_only_allowed_input_keys_are_accepted(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return isset( $job->state['input']['confirm'] ) ? StepResult::done() : StepResult::needsInput( 'confirm', [] );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'ask' => $step ] );
		$id     = $store->create( 'import', [ 'ask' ], [], 1 )->id;
		$runner->tick( $id, new NoDeadline(), 1 );

		try {
			$runner->provideInput( $id, [ 'confirm' => true, 'token' => 'leaked-secret' ], 2 );
			$this->fail( 'An unknown input key is refused.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Unknown input.', $e->getMessage() );
		}
		$this->assertStringNotContainsString( 'leaked-secret', (string) file_get_contents( $store->dir( $id ) . '/job.json' ) );
		$this->assertSame( Job::NEEDS_INPUT, $store->load( $id )->status );
		$this->assertSame( [ 'confirm', 'safetyBackup', 'upload', 'sites' ], Runner::INPUT_KEYS );

		$runner->provideInput( $id, [ 'confirm' => true, 'sites' => [ 0 => 'https://new.test/shop' ] ], 3 );
		$this->assertSame( [ 'confirm' => true, 'sites' => [ 0 => 'https://new.test/shop' ] ], $store->load( $id )->state['input'], 'Addresses of a network restore arrive next to the confirmation.' );
		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 4 )->status );
	}

	public function test_secrets_are_deleted_when_job_ends(): void {
		$secrets = new MemorySecretStore();
		$store   = new JobStore( $this->dir );
		$runner  = new Runner( $store, [ 'a' => self::counter( 1 ) ], $secrets );
		$id      = $store->create( 'import', [ 'a' ], [], 1 )->id;
		$secrets->put( $id, [ 'password' => 'x' ] );
		$runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( [], $secrets->get( $id ) );

		$id2 = $store->create( 'import', [ 'a' ], [], 1 )->id;
		$secrets->put( $id2, [ 'password' => 'x' ] );
		$runner->cancel( $id2, 2 );
		$this->assertSame( [], $secrets->get( $id2 ) );
	}

	public function test_finished_and_waiting_jobs_are_not_resaved(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::needsInput( 'password' );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'a' => $step ] );
		$id     = $store->create( 'import', [ 'a' ], [], 1 )->id;
		$runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( 2, $runner->tick( $id, new NoDeadline(), 9 )->updatedAt );
		$this->assertSame( 2, $store->load( $id )->updatedAt );

		$id2 = $store->create( 'export', [ 'a' ], [], 1 )->id;
		$runner->cancel( $id2, 3 );
		$this->assertSame( 3, $runner->tick( $id2, new NoDeadline(), 9 )->updatedAt );
	}

	public function test_cursor_reset_between_steps_and_lock_released_after_failure(): void {
		$probe = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->state['cursor_seen'] = $job->cursor;
				return StepResult::done();
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'a' => self::counter( 2 ), 'p' => $probe ] );
		$id     = $store->create( 'export', [ 'a', 'p' ], [], 1 )->id;
		$job    = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( [], $job->state['cursor_seen'] );

		$bad = new Runner( $store, [] );
		$id2 = $store->create( 'export', [ 'nope' ], [], 1 )->id;
		$this->assertSame( Job::FAILED, $bad->tick( $id2, new NoDeadline(), 2 )->status );
		$lock = $store->acquire( $id2 );
		$this->assertNotNull( $lock );
		$store->release( $lock );
	}

	public function test_exception_marks_job_failed(): void {
		$step = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				throw new \RuntimeException( 'disk full' );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'x' => $step ] );
		$id     = $store->create( 'export', [ 'x' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( 'disk full', $job->error );
		$this->assertSame( Job::FAILED, $store->load( $id )->status );
	}

	public function test_unknown_step_fails_job(): void {
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [] );
		$id     = $store->create( 'export', [ 'missing' ], [], 1 )->id;
		$this->assertSame( Job::FAILED, $runner->tick( $id, new NoDeadline(), 1 )->status );
	}

	public function test_cancel_and_busy_lock(): void {
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'a' => self::counter( 1 ) ] );
		$id     = $store->create( 'export', [ 'a' ], [], 1 )->id;

		$lock = $store->acquire( $id );
		$this->assertSame( Job::PENDING, $runner->tick( $id, new NoDeadline(), 2 )->status );
		$store->release( $lock );

		$this->assertSame( Job::CANCELLED, $runner->cancel( $id, 3 )->status );
		$this->assertSame( Job::CANCELLED, $runner->tick( $id, new NoDeadline(), 4 )->status );
	}

	public function test_a_cancel_past_the_point_of_no_return_is_deferred_and_the_job_finishes(): void {
		$store    = new JobStore( $this->dir );
		$finished = [];
		$runner   = new Runner(
			$store,
			[
				'swap' => self::counter( 1 ),
				'rest' => self::counter( 2 ),
			],
			null,
			[],
			static function ( Job $job ) use ( &$finished ): void {
				$finished[] = $job->status;
			},
			static fn( Job $job ): bool => ! empty( $job->state['ran'] ) // Past the first step.
		);
		$id       = $store->create( 'import', [ 'swap', 'rest' ], [], 1 )->id;

		$this->assertSame( Job::CANCELLED, ( clone $runner )->cancel( $store->create( 'import', [ 'swap', 'rest' ], [], 1 )->id, 2 )->status, 'Before that point a cancel cancels.' );
		$runner->tick( $id, new CountdownDeadline( 0 ), 2 ); // Only "swap" ran.

		$job = $runner->cancel( $id, 3 );
		$this->assertSame( Job::RUNNING, $job->status );
		$this->assertFalse( $runner->defersCancel( $store->create( 'import', [ 'swap' ], [], 1 ) ) );
		$this->assertTrue( Runner::cancelDeferred( $job ) );
		$this->assertSame( [ Job::CANCELLED ], $finished, 'The finish hook (lock release) waits for the end.' );

		$job = $runner->tick( $id, new NoDeadline(), 4 );
		$this->assertSame( Job::DONE, $job->status );
		$this->assertSame( [ 1, 2 ], $job->state['ran'] );
		$this->assertSame( [ Job::CANCELLED, Job::DONE ], $finished );
		$this->assertFalse( $runner->defersCancel( $job ), 'A finished job has nothing to defer.' );
	}

	public function test_a_step_that_returns_more_before_the_deadline_runs_again_in_the_same_tick(): void {
		$store = new JobStore( $this->dir );
		$step  = new class( $store ) implements StepInterface {
			public function __construct( private JobStore $store ) {
			}
			public function run( Job $job, Deadline $deadline ): StepResult {
				$saved                = json_decode( (string) file_get_contents( $this->store->dir( $job->id ) . '/job.json' ), true );
				$job->state['disk'][] = $saved['cursor']['n'] ?? 0;
				$job->cursor['n']     = ( $job->cursor['n'] ?? 0 ) + 1;

				return $job->cursor['n'] < 4 ? StepResult::more( 25 * $job->cursor['n'] ) : StepResult::done();
			}
		};
		$runner = new Runner( $store, [ 'boundary' => $step ] );
		$id     = $store->create( 'import', [ 'boundary' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( Job::DONE, $job->status );
		$this->assertSame( [ 0, 1, 2, 3 ], $job->state['disk'], 'The job (with its cursor) is saved after every step call.' );
		$this->assertSame( Job::DONE, $store->load( $id )->status );
	}

	public function test_a_step_that_returns_more_at_the_deadline_ends_the_tick(): void {
		$calls = new \ArrayObject();
		$step  = new class( $calls ) implements StepInterface {
			public function __construct( private \ArrayObject $calls ) {
			}
			public function run( Job $job, Deadline $deadline ): StepResult {
				$this->calls->append( 1 );

				return StepResult::more( 10 );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 's' => $step ] );
		$id     = $store->create( 'import', [ 's' ], [], 1 )->id;

		$job = $runner->tick( $id, new CountdownDeadline( 2 ), 2 );
		$this->assertSame( Job::RUNNING, $job->status );
		$this->assertCount( 3, $calls );
		$this->assertSame( 10, $store->load( $id )->progress );
	}

	public function test_failure_message_is_stored_as_short_valid_utf8(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				throw new \RuntimeException( "Server said \xff\xfe" . str_repeat( 'x', 5000 ) );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'x' => $step ] );
		$id     = $store->create( 'import', [ 'x' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( 1, preg_match( '//u', $job->error ) );
		$this->assertLessThanOrEqual( 1024, strlen( $job->error ) );
		$this->assertStringStartsWith( 'Server said ??', $job->error );
		$this->assertSame( $job->error, $store->load( $id )->error );
	}
}
