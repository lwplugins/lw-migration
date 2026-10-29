<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Reset\Steps\BackupStep;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class BackupStepTest extends MonkeyTestCase {

	/**
	 * The export steps of a manual backup, faked: "store" records the backup.
	 *
	 * @param \ArrayObject<int, string>    $seen     Receives "key:workSub:kind" per call.
	 * @param array<string, StepInterface> $override Steps replaced by key.
	 * @return array<string, StepInterface>
	 */
	private static function fakeExport( \ArrayObject $seen, array $override = [] ): array {
		$steps = [];
		foreach ( ExportPipeline::stepsFor( true, false ) as $key ) {
			$steps[ $key ] = new class( $key, $seen ) implements StepInterface {
				public function __construct( private string $key, private \ArrayObject $seen ) {
				}

				public function run( Job $job, Deadline $deadline ): StepResult {
					$this->seen->append( $this->key . ':' . ( $job->state['workSub'] ?? '' ) . ':' . ( $job->options['kind'] ?? '' ) );
					if ( 'store' === $this->key ) {
						$job->state['backup'] = [
							'name' => 'pre-reset-20261007-090000.wpress',
							'size' => 10,
						];
					}
					return StepResult::done();
				}
			};
		}

		return array_merge( $steps, $override );
	}

	public function test_the_full_backup_runs_nested_and_is_labelled_with_the_reset_kind(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$labels = [];
		$seen   = new \ArrayObject();
		$step   = new BackupStep(
			self::fakeExport( $seen ),
			static function ( string $name, string $text ) use ( &$labels ): void {
				$labels[ $name ] = $text;
			}
		);
		$job    = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup', 'reset_media' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( ExportPipeline::stepsFor( false, false ), array_map( static fn( string $s ): string => explode( ':', $s )[0], $seen->getArrayCopy() ), 'The whole export, not database-only.' );
		$this->assertStringContainsString( ':reset-backup:pre-reset', $seen[0] );
		$this->assertSame( [ 'pre-reset-20261007-090000.wpress' => 'Before reset — media' ], $labels );
		$this->assertSame( 'pre-reset-20261007-090000.wpress', $job->state['backup']['name'] );
		$this->assertSame( 10, $job->state['backup']['size'] );
		$this->assertSame( 'Backup saved as pre-reset-20261007-090000.wpress.', $result->message() );
	}

	public function test_a_replay_after_the_label_labels_nothing_again(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$labelled = 0;
		$seen     = new \ArrayObject();
		$step     = new BackupStep(
			self::fakeExport( $seen ),
			static function () use ( &$labelled ): void {
				++$labelled;
			}
		);
		$job      = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$calls = count( $seen );

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( 1, $labelled );
		$this->assertCount( $calls, $seen, 'No nested step runs again.' );
	}

	public function test_the_nested_progress_shows_through(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$database = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$n                = (int) ( $job->cursor['n'] ?? 0 ) + 1;
				$job->cursor['n'] = $n;

				return $n < 2 ? StepResult::more( 50, 'Exporting the database: table 1 of 1...' ) : StepResult::done();
			}
		};
		$step     = new BackupStep( self::fakeExport( new \ArrayObject(), [ 'database' => $database ] ), static function (): void {
		} );
		$job      = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
		$texts    = [];
		do {
			$result  = $step->run( $job, new NoDeadline() );
			$texts[] = $result->message();
		} while ( ! $result->isDone() );

		$this->assertSame( 'Backing up the site first...', $texts[0] );
		$this->assertContains( 'Backing up the site first: Exporting the database: table 1 of 1...', $texts );
	}

	public function test_a_backup_that_did_not_store_stops_the_reset(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$silent   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done(); // Stores nothing.
			}
		};
		$labelled = false;
		$step     = new BackupStep(
			self::fakeExport( new \ArrayObject(), [ 'store' => $silent ] ),
			static function () use ( &$labelled ): void {
				$labelled = true;
			}
		);
		$job      = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
		try {
			do {
				$result = $step->run( $job, new NoDeadline() );
			} while ( ! $result->isDone() );
			$this->fail( 'No backup, no reset.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'The backup before the reset was not saved, so nothing was reset.', $e->getMessage() );
			$this->assertFalse( $labelled );
		}
	}

	public function test_a_nested_failure_is_named_as_the_backup_before_the_reset(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$throws = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->addWarning( 'lost' );
				throw new \RuntimeException( 'disk full' );
			}
		};
		$step   = new BackupStep( self::fakeExport( new \ArrayObject(), [ 'init' => $throws ] ) );
		$job    = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The nested failure must reach the Runner.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Backup before the reset: disk full', $e->getMessage() );
			$this->assertSame( 'disk full', $e->getPrevious()?->getMessage() );
		}
		$this->assertSame( [ 'Backup before the reset: lost' ], $job->warnings );
	}

	public function test_the_label_failing_is_only_a_warning(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$step = new BackupStep(
			self::fakeExport( new \ArrayObject() ),
			static function (): void {
				throw new \RuntimeException( 'option write failed' );
			}
		);
		$job  = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'themes', 'backup' => true ], [ 'site' => [] ] );
		do {
			$result = $step->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( 'pre-reset-20261007-090000.wpress', $job->state['backup']['name'] );
		$this->assertSame( [ 'The backup was saved but could not be labelled.' ], $job->warnings );
	}

	public function test_a_missing_nested_step_or_an_input_request_stops_the_reset_in_whole_sentences(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$steps = self::fakeExport( new \ArrayObject() );
		unset( $steps['config'] );
		$asks  = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::needsInput( 'password' );
			}
		};
		$cases = [
			'The backup before the reset cannot run: its step "config" is missing. Nothing was reset.' => [ $steps, 2 ],
			'The backup before the reset asked for input it cannot get, so nothing was reset.' => [ self::fakeExport( new \ArrayObject(), [ 'init' => $asks ] ), 1 ],
		];
		foreach ( $cases as $expected => [ $exportSteps, $runs ] ) {
			$step = new BackupStep( $exportSteps );
			$job  = new Job( str_repeat( 'b', 16 ), 'reset', [ 'reset_backup' ], 0, [], [ 'type' => 'media', 'backup' => true ], [ 'site' => [] ] );
			try {
				for ( $i = 0; $i < $runs; $i++ ) {
					$step->run( $job, new NoDeadline() );
				}
				$this->fail( 'The refusal must reach the Runner.' );
			} catch ( JobException $e ) {
				$this->assertSame( $expected, $e->getMessage() );
			}
		}
	}
}
