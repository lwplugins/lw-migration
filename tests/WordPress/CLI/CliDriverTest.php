<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\CliDriver;
use LightweightPlugins\Migration\CLI\Drive;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Tests\Support\BufferOutput;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CliDriverTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @return array<string, StepInterface>
	 */
	private static function steps(): array {
		return [
			'work'    => new class() implements StepInterface {
				public function run( Job $job, Deadline $deadline ): StepResult {
					$job->cursor['n'] = ( $job->cursor['n'] ?? 0 ) + 1;
					if ( 2 === $job->cursor['n'] ) {
						$job->addWarning( 'half way' );
					}
					return $job->cursor['n'] < 3 ? StepResult::more( 33 * $job->cursor['n'], 'Working' ) : StepResult::done( 'Worked' );
				}
			},
			'confirm' => new class() implements StepInterface {
				public function run( Job $job, Deadline $deadline ): StepResult {
					return true === ( $job->state['input']['confirm'] ?? null ) ? StepResult::done( 'Confirmed' ) : StepResult::needsInput( 'confirm' );
				}
			},
		];
	}

	public function test_runs_to_the_end_answering_input(): void {
		$store  = new JobStore( $this->dir );
		$out    = new BufferOutput();
		$ticks  = 0;
		$driver = new CliDriver(
			new Runner( $store, self::steps() ),
			$out,
			static function () use ( &$ticks ): void {
				++$ticks;
			},
			0.0
		);
		$id     = $store->create( 'export', [ 'work', 'confirm' ], [], 1 )->id;

		$job = $driver->run( $id, static fn( Job $job ): ?array => 'confirm' === $job->input['kind'] ? [ 'confirm' => true ] : null );

		$this->assertSame( Job::DONE, $job->status );
		$this->assertContains( 'Warning: half way', $out->lines );
		$this->assertContains( 'Confirmed', $out->lines );
		$this->assertGreaterThan( 1, $ticks );
	}

	public function test_no_answer_cancels(): void {
		$store  = new JobStore( $this->dir );
		$driver = new CliDriver( new Runner( $store, self::steps() ), new BufferOutput(), static function (): void {}, 0.0 );
		$id     = $store->create( 'export', [ 'confirm' ], [], 1 )->id;

		$this->assertSame( Job::CANCELLED, $driver->run( $id )->status );
	}

	public function test_a_declined_question_past_the_point_of_no_return_is_never_called_cancelled(): void {
		$store  = new JobStore( $this->dir );
		$out    = new BufferOutput();
		$runner = new Runner( $store, self::steps(), null, [], null, static fn(): bool => true );
		$driver = new CliDriver( $runner, $out, static function (): void {}, 0.0 );
		$id     = $store->create( 'export', [ 'confirm' ], [], 1 )->id;

		$job = $driver->run( $id );

		$this->assertFalse( $job->isFinished() );
		$this->assertTrue( Runner::cancelDeferred( $job ) );
		$this->assertContains( 'The job was not cancelled: it is past its point of no return and finishes when it is driven again.', $out->lines );
		$this->assertSame( 'The job was not cancelled: it is past its point of no return and finishes when it is driven again.', Drive::endText( $job ) );
		$this->assertStringNotContainsString( 'was cancelled', implode( "\n", $out->lines ) );

		$job->status = Job::CANCELLED;
		$this->assertSame( 'The job was cancelled.', Drive::endText( $job ) );
		$job->status = Job::FAILED;
		$job->error  = 'Disk full.';
		$this->assertSame( 'Disk full.', Drive::endText( $job ) );
	}

	public function test_notices_the_finish_hook_adds_on_cancel_are_printed(): void {
		$store  = new JobStore( $this->dir );
		$out    = new BufferOutput();
		$runner = new Runner(
			$store,
			self::steps(),
			null,
			[],
			static function ( Job $job ): void {
				$job->addWarning( 'Some files were already put in place.' );
			}
		);
		$driver = new CliDriver( $runner, $out, static function (): void {}, 0.0 );
		$id     = $store->create( 'export', [ 'confirm' ], [], 1 )->id;

		$this->assertSame( Job::CANCELLED, $driver->run( $id )->status );
		$this->assertContains( 'Warning: Some files were already put in place.', $out->lines );
	}

	public function test_a_failing_before_tick_stops_before_the_tick(): void {
		$store  = new JobStore( $this->dir );
		$driver = new CliDriver(
			new Runner( $store, self::steps() ),
			new BufferOutput(),
			static function (): void {
				throw new JobException( 'lock lost' );
			},
			0.0
		);
		$id     = $store->create( 'export', [ 'work' ], [], 1 )->id;

		try {
			$driver->run( $id );
			$this->fail( 'Expected the before-tick exception.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'lock lost', $e->getMessage() );
		}
		$this->assertSame( Job::PENDING, $store->load( $id )->status );
	}

	public function test_progress_is_printed_when_it_moved_five_points_or_the_message_changed(): void {
		$store  = new JobStore( $this->dir );
		$out    = new BufferOutput();
		$step   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->cursor['n'] = ( $job->cursor['n'] ?? 0 ) + 1;
				if ( 10 === $job->cursor['n'] ) {
					return StepResult::done( 'Worked' );
				}
				return StepResult::more( 2 * $job->cursor['n'], 7 === $job->cursor['n'] ? 'Almost' : 'Working' );
			}
		};
		$driver = new CliDriver( new Runner( $store, [ 'work' => $step ] ), $out, static function (): void {}, 0.0 );
		$id     = $store->create( 'export', [ 'work' ], [], 1 )->id;

		$this->assertSame( Job::DONE, $driver->run( $id )->status );
		$this->assertSame( [ 'Working (2%)', 'Working (8%)', 'Almost (14%)', 'Working (16%)', 'Worked' ], $out->lines );
	}
}
