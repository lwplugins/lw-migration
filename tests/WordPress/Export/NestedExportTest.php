<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Export\NestedExport;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NestedExportTest extends MonkeyTestCase {

	/**
	 * Fake export steps: "database" needs three calls, "config" warns, "store"
	 * records a backup; $override replaces single keys.
	 *
	 * @param \ArrayObject<int, string>    $seen     Receives "key:workSub:kind" per call.
	 * @param array<string, StepInterface> $override Real or special steps by key.
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
					if ( 'database' === $this->key && ( $job->cursor['n'] ?? 0 ) < 2 ) {
						$job->cursor['n'] = ( $job->cursor['n'] ?? 0 ) + 1;
						return StepResult::more( 10 );
					}
					if ( 'config' === $this->key ) {
						$job->addWarning( 'disk is small' );
					}
					if ( 'store' === $this->key ) {
						$job->state['backup'] = [ 'name' => 'pre-import-20261007-000000.wpress' ];
					}
					return StepResult::done();
				}
			};
		}

		return array_merge( $steps, $override );
	}

	public function test_one_nested_step_per_call_then_null_with_the_backup(): void {
		$seen   = new \ArrayObject();
		$nested = new NestedExport(
			self::fakeExport( $seen ),
			[ 'init', 'config', 'database', 'store' ],
			[ 'kind' => 'pre-reset' ],
			'reset-backup',
			static fn( string $m, ?\Throwable $p ): \RuntimeException => new \RuntimeException( 'Backup: ' . $m, 0, $p ),
			static fn( string $w ): string => 'Backup: ' . $w,
			static fn( string $t ): string => '' === $t ? 'Backing up' : 'Backing up: ' . $t,
			static fn( ?string $missing ): \RuntimeException => new \RuntimeException( 'Refused: ' . ( $missing ?? 'input' ) )
		);
		$job    = new Job( str_repeat( 'a', 16 ), 'reset', [ 'reset_backup' ], 0, [], [], [ 'site' => [ 'homeUrl' => 'https://x.test' ] ] );
		$calls  = 0;
		while ( null !== ( $result = $nested->advance( $job, 'backup', new NoDeadline() ) ) ) {
			$this->assertFalse( $result->isDone() );
			++$calls;
		}
		$this->assertSame( [ 'init:reset-backup:pre-reset', 'config:reset-backup:pre-reset', 'database:reset-backup:pre-reset', 'database:reset-backup:pre-reset', 'database:reset-backup:pre-reset', 'store:reset-backup:pre-reset' ], $seen->getArrayCopy() );
		$this->assertSame( 5, $calls, 'The call that finishes the last nested step returns null at once.' );
		$this->assertSame( 'pre-import-20261007-000000.wpress', NestedExport::backup( $job, 'backup' )['name'] );
		$this->assertSame( [ 'Backup: disk is small' ], $job->warnings );
	}

	public function test_a_nested_failure_or_input_request_becomes_the_callers_exception(): void {
		$throws = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				throw new \RuntimeException( 'disk full' );
			}
		};
		$asks   = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::needsInput( 'password' );
			}
		};
		foreach ( [ [ 'init' => $throws ], [ 'init' => $asks ] ] as $override ) {
			$nested = new NestedExport(
				self::fakeExport( new \ArrayObject(), $override ),
				[ 'init', 'store' ],
				[ 'kind' => 'pre-reset' ],
				'reset-backup',
				static fn( string $m, ?\Throwable $p ): \RuntimeException => new \RuntimeException( 'Backup: ' . $m, 0, $p ),
				static fn( string $w ): string => 'Backup: ' . $w,
				static fn( string $t ): string => 'Backing up',
				static fn( ?string $missing ): \RuntimeException => new \RuntimeException( 'Backup: refused ' . ( $missing ?? 'input' ) )
			);
			$job    = new Job( str_repeat( 'a', 16 ), 'reset', [ 'reset_backup' ], 0, [ 'backup' => [ 'index' => 0, 'cursor' => [ 'kept' => 1 ], 'state' => [ 'workSub' => 'reset-backup' ] ] ] );
			try {
				$nested->advance( $job, 'backup', new NoDeadline() );
				$this->fail( 'A nested failure must throw.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringStartsWith( 'Backup: ', $e->getMessage() );
				$this->assertSame( [ 'kept' => 1 ], $job->cursor['backup']['cursor'], 'The saved cursor is untouched.' );
			}
		}
	}

	public function test_a_missing_nested_step_names_the_step(): void {
		$steps = self::fakeExport( new \ArrayObject() );
		unset( $steps['config'] );
		$nested = new NestedExport(
			$steps,
			[ 'init', 'config' ],
			[ 'kind' => 'pre-reset' ],
			'reset-backup',
			static fn( string $m, ?\Throwable $p ): \RuntimeException => new \RuntimeException( 'Backup: ' . $m, 0, $p ),
			static fn( string $w ): string => $w,
			static fn( string $t ): string => $t,
			static fn( ?string $missing ): \RuntimeException => new \RuntimeException( 'Refused: ' . ( $missing ?? 'input' ) )
		);
		$job    = new Job( str_repeat( 'a', 16 ), 'reset', [ 'reset_backup' ] );
		$nested->advance( $job, 'backup', new NoDeadline() ); // init.

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Refused: config' );
		$nested->advance( $job, 'backup', new NoDeadline() );
	}

	public function test_the_store_names_are_read_from_both_cursor_keys(): void {
		$this->assertNull( NestedExport::storeTemp( [], 'backup' ) );
		$this->assertNull( NestedExport::storeTemp( [ 'backup' => [ 'cursor' => [ 'temp' => 7 ] ] ], 'backup' ) );
		$this->assertSame( '', NestedExport::storeReserving( [ 'safety' => [ 'cursor' => [ 'reserving' => 'x.wpress' ] ] ], 'backup' ) );
		$this->assertSame( 'x.wpress', NestedExport::storeReserving( [ 'safety' => [ 'cursor' => [ 'reserving' => 'x.wpress' ] ] ], 'safety' ) );
		$this->assertSame( [ 'temps' => [], 'reserving' => [] ], NestedExport::namedTemps( [] ) );
	}
}
