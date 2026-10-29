<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RunnerGuardTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	private static function stuck( \ArrayObject $calls ): StepInterface {
		return new class( $calls ) implements StepInterface {
			public function __construct( private \ArrayObject $calls ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				$this->calls->append( 1 );
				return StepResult::more( 10 );
			}
		};
	}

	public function test_no_progress_in_one_tick_fails_after_max_attempts(): void {
		$calls  = new \ArrayObject();
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 's' => self::stuck( $calls ) ] );
		$id     = $store->create( 'export', [ 's' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );

		$this->assertSame( Job::FAILED, $job->status );
		$this->assertCount( Runner::MAX_ATTEMPTS, $calls );
		$this->assertStringContainsString( 'without making progress', $job->error );
	}

	public function test_a_crash_loop_across_ticks_is_stopped(): void {
		$calls  = new \ArrayObject();
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 's' => self::stuck( $calls ) ] );
		$id     = $store->create( 'export', [ 's' ], [], 1 )->id;

		for ( $i = 0; $i < Runner::MAX_ATTEMPTS; $i++ ) {
			$this->assertSame( Job::RUNNING, $runner->tick( $id, new CountdownDeadline( 0 ), 2 )->status );
		}
		$this->assertSame( Job::FAILED, $runner->tick( $id, new CountdownDeadline( 0 ), 3 )->status );
		$this->assertCount( Runner::MAX_ATTEMPTS, $calls, 'The sixth start is refused before the step runs.' );
	}

	public function test_attempt_is_saved_before_the_step_runs(): void {
		$store = new JobStore( $this->dir );
		$step  = new class( $store ) implements StepInterface {
			public function __construct( private JobStore $store ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				$saved                  = json_decode( (string) file_get_contents( $this->store->dir( $job->id ) . '/job.json' ), true );
				$job->state['on_disk'] = $saved['attempt']['count'] ?? null;
				return StepResult::done();
			}
		};
		$runner = new Runner( $store, [ 'p' => $step ] );
		$id     = $store->create( 'export', [ 'p' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$this->assertSame( 1, $job->state['on_disk'] );
		$this->assertSame( [], $job->attempt, 'Reset once the step is done.' );
	}

	public function test_progressing_step_is_never_stopped(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->cursor['n'] = ( $job->cursor['n'] ?? 0 ) + 1;
				return $job->cursor['n'] < 20 ? StepResult::more( $job->cursor['n'] ) : StepResult::done();
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'p' => $step ] );
		$id     = $store->create( 'export', [ 'p' ], [], 1 )->id;

		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 2 )->status );
	}

	public function test_wrong_password_reprompt_clears_the_stale_secret(): void {
		$step    = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return 'right' === ( $job->secrets['password'] ?? null ) ? StepResult::done() : StepResult::needsInput( 'password' );
			}
		};
		$secrets = new MemorySecretStore();
		$store   = new JobStore( $this->dir );
		$runner  = new Runner( $store, [ 'd' => $step ], $secrets );
		$id      = $store->create( 'import', [ 'd' ], [], 1 )->id;

		$runner->tick( $id, new NoDeadline(), 2 );
		$runner->provideInput( $id, [ 'password' => 'wrong' ], 3 );
		$this->assertSame( Job::NEEDS_INPUT, $runner->tick( $id, new NoDeadline(), 4 )->status );
		$this->assertArrayNotHasKey( 'password', $secrets->get( $id ) );

		$runner->provideInput( $id, [ 'password' => 'right' ], 5 );
		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 6 )->status );
	}

	public function test_non_secret_input_goes_to_job_state(): void {
		$step    = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return true === ( $job->state['input']['confirm'] ?? null ) ? StepResult::done() : StepResult::needsInput( 'confirm', [ 'summary' => 'x' ] );
			}
		};
		$secrets = new MemorySecretStore();
		$store   = new JobStore( $this->dir );
		$runner  = new Runner( $store, [ 'c' => $step ], $secrets );
		$id      = $store->create( 'import', [ 'c' ], [], 1 )->id;

		$this->assertSame( Job::NEEDS_INPUT, $runner->tick( $id, new NoDeadline(), 2 )->status );
		$runner->provideInput( $id, [ 'confirm' => true ], 3 );
		$this->assertSame( [], $secrets->get( $id ) );
		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 4 )->status );
	}

	public function test_finish_hook_runs_once_for_done_failed_and_cancelled(): void {
		$seen   = new \ArrayObject();
		$fail   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				throw new \RuntimeException( 'boom' );
			}
		};
		$ok     = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'ok' => $ok, 'bad' => $fail ], null, [], static function ( Job $job ) use ( $seen ): void {
			$seen->append( $job->status );
		} );
		$a = $store->create( 'export', [ 'ok' ], [], 1 )->id;
		$b = $store->create( 'export', [ 'bad' ], [], 1 )->id;
		$c = $store->create( 'export', [ 'ok' ], [], 1 )->id;

		$runner->tick( $a, new NoDeadline(), 2 );
		$runner->tick( $a, new NoDeadline(), 3 );
		$runner->tick( $b, new NoDeadline(), 2 );
		$runner->cancel( $c, 2 );
		$runner->cancel( $c, 3 );

		$this->assertSame( [ Job::DONE, Job::FAILED, Job::CANCELLED ], $seen->getArrayCopy() );
	}

	public function test_error_is_redacted_and_details_are_logged(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->addWarning( 'w1' );
				throw new \RuntimeException( 'Cannot read /srv/site/wp-content/a.txt' );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'x' => $step ], null, [ '/srv/site/', '/srv/site/wp-content' ] );
		$id     = $store->create( 'export', [ 'x' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$log = (string) file_get_contents( $store->dir( $id ) . '/job.log' );

		$this->assertSame( 'Cannot read a.txt', $job->error );
		$this->assertStringContainsString( 'RuntimeException: Cannot read a.txt at ', $log );
		$this->assertStringNotContainsString( '/srv/site', $log, 'job.log is redacted like the error.' );
		$this->assertStringContainsString( 'RuntimeException', $log );
		$this->assertStringContainsString( 'WARNING w1', $log );
	}

	public function test_every_warning_is_logged_beyond_the_stored_hundred(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				for ( $i = 0; $i < 150; $i++ ) {
					$job->addWarning( "w{$i}" );
				}
				return StepResult::done();
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'x' => $step ] );
		$id     = $store->create( 'export', [ 'x' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );
		$log = (string) file_get_contents( $store->dir( $id ) . '/job.log' );

		$this->assertCount( 100, $job->warnings );
		$this->assertSame( 150, substr_count( $log, ' WARNING w' ) );
		$this->assertStringContainsString( 'WARNING w149', $log );
		$this->assertStringNotContainsString( 'newWarnings', (string) file_get_contents( $store->dir( $id ) . '/job.json' ) );
	}

	public function test_password_is_not_logged(): void {
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				if ( ! isset( $job->secrets['password'] ) ) {
					return StepResult::needsInput( 'password' );
				}
				throw new \RuntimeException( 'later failure' );
			}
		};
		$store  = new JobStore( $this->dir );
		$runner = new Runner( $store, [ 'x' => $step ] );
		$id     = $store->create( 'import', [ 'x' ], [], 1 )->id;
		$runner->tick( $id, new NoDeadline(), 2 );
		$runner->provideInput( $id, [ 'password' => 'hunter2-secret' ], 3 );
		$runner->tick( $id, new NoDeadline(), 4 );

		foreach ( [ 'job.json', 'job.log' ] as $file ) {
			$this->assertStringNotContainsString( 'hunter2-secret', (string) file_get_contents( $store->dir( $id ) . '/' . $file ) );
		}
	}

	public function test_lock_is_released_when_deleting_the_secrets_fails(): void {
		$secrets = new class() implements SecretStore {
			public function put( string $jobId, array $values ): void {
			}

			public function get( string $jobId ): array {
				return [];
			}

			public function delete( string $jobId ): void {
				throw new \RuntimeException( 'secret store down' );
			}
		};
		$ok      = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};
		$store   = new JobStore( $this->dir );
		$runner  = new Runner( $store, [ 'ok' => $ok ], $secrets );
		$id      = $store->create( 'export', [ 'ok' ], [], 1 )->id;

		$this->assertSame( Job::DONE, $runner->tick( $id, new NoDeadline(), 2 )->status );
		$this->assertSame( Job::DONE, $store->load( $id )->status );
		$this->assertStringContainsString( 'secret store down', (string) file_get_contents( $store->dir( $id ) . '/job.log' ) );
		$lock = $store->acquire( $id );
		$this->assertNotNull( $lock );
		$store->release( $lock );
	}

	public function test_finish_hook_sees_the_saved_terminal_job(): void {
		$seen   = new \ArrayObject();
		$store  = new JobStore( $this->dir );
		$ok     = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};
		$runner = new Runner( $store, [ 'ok' => $ok ], null, [], static function ( Job $job ) use ( $seen, $store ): void {
			$seen->append( $store->load( $job->id )->status );
		} );
		$a = $store->create( 'export', [ 'ok' ], [], 1 )->id;
		$b = $store->create( 'export', [ 'ok' ], [], 1 )->id;

		$runner->tick( $a, new NoDeadline(), 2 );
		$runner->cancel( $b, 2 );

		$this->assertSame( [ Job::DONE, Job::CANCELLED ], $seen->getArrayCopy() );
	}

	public function test_warnings_reach_the_log_before_the_next_step_call(): void {
		$store = new JobStore( $this->dir );
		$step  = new class( $store ) implements StepInterface {
			public function __construct( private JobStore $store ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->cursor['n'] = ( $job->cursor['n'] ?? 0 ) + 1;
				if ( 1 === $job->cursor['n'] ) {
					$job->addWarning( 'first call' );
					return StepResult::more( 50 );
				}
				$file                  = $this->store->dir( $job->id ) . '/job.log';
				$job->state['logged'] = is_file( $file ) ? (string) file_get_contents( $file ) : '';
				$job->state['memory'] = $job->newWarnings;
				return StepResult::done();
			}
		};
		$runner = new Runner( $store, [ 'w' => $step ] );
		$id     = $store->create( 'export', [ 'w' ], [], 1 )->id;

		$job = $runner->tick( $id, new NoDeadline(), 2 );

		$this->assertStringContainsString( 'WARNING first call', $job->state['logged'] );
		$this->assertSame( [], $job->state['memory'] );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $store->dir( $id ) . '/job.log' ), 'WARNING first call' ) );
	}
}
