<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Export\Steps\CleanStep;
use LightweightPlugins\Migration\Export\Steps\StoreStep;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\SafetyBackupStep;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class SafetyBackupStepTest extends MonkeyTestCase {

	private const NOW = 1791363600; // 2026-10-07 09:00:00 UTC.

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

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
		foreach ( ExportPipeline::stepsFor( true, true ) as $key ) {
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

	/**
	 * Calls the step the way the Runner does: the attempt guard before every
	 * call, the cursor saved (JSON round trip) only after a normal return.
	 *
	 * @param SafetyBackupStep $step Step.
	 * @param Job              $job  Import job.
	 * @return int Calls until done.
	 */
	private static function drive( SafetyBackupStep $step, Job $job ): int {
		$runs = 0;
		do {
			AttemptGuard::record( $job, 'safety_backup' );
			$result      = $step->run( $job, new CountdownDeadline( 0 ) );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		return $runs;
	}

	public function test_runs_the_database_only_export_resumably(): void {
		$job  = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$seen = new \ArrayObject();
		self::drive( new SafetyBackupStep( self::fakeExport( $seen ) ), $job );

		$this->assertSame( [ 'name' => 'pre-import-20261007-000000.wpress' ], $job->state['safetyBackup'] );
		$this->assertSame( 'init:' . ImportFiles::SAFETY . ':pre-import', $seen[0] );
		$this->assertContains( 'Safety backup: disk is small', $job->warnings );
		$this->assertSame( 3, count( array_filter( $seen->getArrayCopy(), static fn( string $s ): bool => str_starts_with( $s, 'database:' ) ) ) );
		$this->assertSame( count( ExportPipeline::DATABASE_ONLY_STEPS ) + 2, count( $seen ) );
	}

	public function test_on_a_network_the_nested_backup_lists_the_network_first(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$job  = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$seen = new \ArrayObject();
		self::drive( new SafetyBackupStep( self::fakeExport( $seen ) ), $job );

		$this->assertSame( [ 'init', 'network', 'config' ], array_slice( array_map( static fn( string $s ): string => explode( ':', $s )[0], $seen->getArrayCopy() ), 0, 3 ) );
		$this->assertSame( [ 'name' => 'pre-import-20261007-000000.wpress' ], $job->state['safetyBackup'] );
	}

	public function test_the_store_temp_is_read_from_one_place(): void {
		$temp = TempName::name();

		$this->assertSame( $temp, SafetyBackupStep::storeTemp( [ SafetyBackupStep::CURSOR => [ 'cursor' => [ 'temp' => $temp ] ] ] ) );
		$this->assertNull( SafetyBackupStep::storeTemp( [] ) );
		$this->assertNull( SafetyBackupStep::storeTemp( [ SafetyBackupStep::CURSOR => [ 'cursor' => [ 'temp' => 7 ] ] ] ) );
	}

	public function test_switched_off(): void {
		$job  = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress', [ 'safetyBackup' => false ] );
		$seen = new \ArrayObject();
		$this->assertTrue( ( new SafetyBackupStep( self::fakeExport( $seen ) ) )->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$this->assertCount( 0, $seen );
		$this->assertArrayNotHasKey( 'safetyBackup', $job->state );
	}

	public function test_each_call_runs_one_nested_step_and_moves_the_cursor(): void {
		$job    = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$seen   = new \ArrayObject();
		$step   = new SafetyBackupStep( self::fakeExport( $seen ) );
		$prints = [];
		do {
			$result      = $step->run( $job, new NoDeadline() );
			$prints[]    = json_encode( $job->cursor );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true );
		} while ( ! $result->isDone() && count( $prints ) < 50 );

		// One nested call per run, so the Runner saves and guards before each one.
		$this->assertCount( count( $seen ), $prints );
		$this->assertSame( $prints, array_values( array_unique( $prints ) ) );
		$this->assertSame( ImportFiles::SAFETY, $job->cursor['safety']['state']['workSub'] );
		$this->assertSame( $job->state['site'], $job->cursor['safety']['state']['site'] );
	}

	public function test_the_nested_progress_and_text_show_through(): void {
		$job      = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$keys     = ExportPipeline::stepsFor( false, true );
		$index    = (int) array_search( 'database', $keys, true );
		$database = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$n                = (int) ( $job->cursor['n'] ?? 0 ) + 1;
				$job->cursor['n'] = $n;

				return $n < 4 ? StepResult::more( 20 * $n, sprintf( 'Exporting the database: table 1 of 1 (%d00 B written)...', $n ) ) : StepResult::done();
			}
		};
		$step     = new SafetyBackupStep( self::fakeExport( new \ArrayObject(), [ 'database' => $database ] ) );
		$seen     = [];
		do {
			$before = (int) ( $job->cursor['safety']['index'] ?? 0 );
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			if ( $index === $before ) {
				$seen[] = [ $result->progress(), $result->message() ];
			}
		} while ( ! $result->isDone() );

		$count = count( $keys );
		$this->assertSame(
			[
				[ intdiv( 100 * $index + 20, $count ), 'Backing up the current database first: Exporting the database: table 1 of 1 (100 B written)...' ],
				[ intdiv( 100 * $index + 40, $count ), 'Backing up the current database first: Exporting the database: table 1 of 1 (200 B written)...' ],
				[ intdiv( 100 * $index + 60, $count ), 'Backing up the current database first: Exporting the database: table 1 of 1 (300 B written)...' ],
				[ intdiv( 100 * ( $index + 1 ), $count ), 'Backing up the current database first...' ],
			],
			$seen,
			'A long nested step never looks stalled to a connected site (RemoteJobStep fingerprints progress and text).'
		);
	}

	public function test_a_nested_step_without_text_keeps_the_plain_text(): void {
		$job    = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$result = ( new SafetyBackupStep( self::fakeExport( new \ArrayObject() ) ) )->run( $job, new CountdownDeadline( 0 ) );

		$this->assertSame( 'Backing up the current database first...', $result->message() );
		$this->assertSame( intdiv( 100, count( ExportPipeline::stepsFor( false, true ) ) ), $result->progress() );
	}

	public function test_a_nested_failure_leaves_the_saved_cursor_unchanged(): void {
		$job      = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$seen     = new \ArrayObject();
		$throwing = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				$job->cursor['half'] = true;
				$job->state['half']  = true;
				$job->addWarning( 'lost' );
				throw new \RuntimeException( 'database gone' );
			}
		};
		$step     = new SafetyBackupStep( self::fakeExport( $seen, [ 'config' => $throwing ] ) );
		$step->run( $job, new NoDeadline() ); // init.
		$before = $job->cursor;

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The nested failure must reach the Runner.' );
		} catch ( ImportException $e ) {
			$this->assertSame( 'Safety backup: database gone', $e->getMessage() );
			$this->assertSame( 'database gone', $e->getPrevious()?->getMessage() );
		}
		$this->assertSame( $before, $job->cursor );
		$this->assertSame( [ 'Safety backup: lost' ], $job->warnings, 'Warnings of the failed nested step are kept.' );
	}

	public function test_a_missing_nested_step_fails_clearly(): void {
		$job   = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$steps = self::fakeExport( new \ArrayObject() );
		unset( $steps['config'] );
		$step = new SafetyBackupStep( $steps );
		$step->run( $job, new NoDeadline() ); // init.

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The backup of the current database cannot run: its step "config" is missing. The import was stopped before anything changed.' );
		$step->run( $job, new NoDeadline() );
	}

	public function test_a_nested_input_request_fails_the_import(): void {
		$job    = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$asking = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::needsInput( 'password' );
			}
		};
		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The backup of the current database asked for input it cannot get, so the import was stopped before anything changed.' );
		( new SafetyBackupStep( self::fakeExport( new \ArrayObject(), [ 'init' => $asking ] ) ) )->run( $job, new NoDeadline() );
	}

	public function test_the_nested_export_must_record_its_backup(): void {
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$nothing = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};
		$step    = new SafetyBackupStep( self::fakeExport( new \ArrayObject(), [ 'store' => $nothing ] ) );
		$this->expectException( ImportException::class );
		self::drive( $step, $job );
	}

	public function test_real_store_publishes_a_safety_backup_and_keeps_older_ones(): void {
		$backups = $this->dir . '/backups';
		mkdir( $backups );
		foreach ( [ 'pre-import-20261001-000000.wpress', 'pre-import-20261002-000000.wpress' ] as $old ) {
			file_put_contents( $backups . '/' . $old, 'old' );
		}
		$finalize = new class( $this->store ) implements StepInterface {
			public function __construct( private JobStore $store ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				file_put_contents( WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE, 'database-only archive' );
				return StepResult::done();
			}
		};
		$real     = [
			'finalize' => $finalize,
			'store'    => new StoreStep( $this->store, $backups, new Retention( 1 ), static fn(): int => self::NOW ),
			'clean'    => new CleanStep( $this->store ),
		];
		$job      = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		$step     = new SafetyBackupStep( self::fakeExport( new \ArrayObject(), $real ) );
		$temp     = null;
		$runs     = 0;
		do {
			AttemptGuard::record( $job, 'safety_backup' );
			$result      = $step->run( $job, new NoDeadline() );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true );
			$temp      ??= $job->cursor['safety']['cursor']['temp'] ?? null;
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$name = BackupName::safety( self::NOW );
		$this->assertTrue( $result->isDone() );
		$this->assertIsString( $temp, 'The store temp lives at cursor.safety.cursor.temp (ImportCleanup reads it).' );
		$this->assertTrue( TempName::is( $temp ) );
		$this->assertFileDoesNotExist( $backups . '/' . $temp );
		$this->assertSame( $name, $job->state['safetyBackup']['name'] );
		$this->assertSame( 'database-only archive', file_get_contents( $backups . '/' . $name ) );
		$this->assertFileExists( $backups . '/pre-import-20261001-000000.wpress' );
		$this->assertFileExists( $backups . '/pre-import-20261002-000000.wpress' );
		$this->assertDirectoryDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::SAFETY );
	}

	public function test_the_safety_backup_never_prunes_the_backup_being_restored(): void {
		$backups = $this->dir . '/backups';
		mkdir( $backups );
		$restored = BackupName::forSite( 'https://new.test', self::NOW - 45 * 86400, 1 );
		$newer    = BackupName::forSite( 'https://new.test', self::NOW - 35 * 86400, 2 );
		file_put_contents( $backups . '/' . $restored, 'the backup being restored' );
		file_put_contents( $backups . '/' . $newer, 'a newer backup' );
		$finalize = new class( $this->store ) implements StepInterface {
			public function __construct( private JobStore $store ) {
			}

			public function run( Job $job, Deadline $deadline ): StepResult {
				file_put_contents( WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE, 'database-only archive' );
				return StepResult::done();
			}
		};
		// Days retention as the live option would give it: both own backups are older than 30 days.
		$real = [
			'finalize' => $finalize,
			'store'    => new StoreStep( $this->store, $backups, new Retention( 0, 0, 30 ), static fn(): int => self::NOW ),
			'clean'    => new CleanStep( $this->store ),
		];
		$job  = ImportJobFixture::make( $this->store, $this->dir, $backups . '/' . $restored, [ 'source' => 'backup', 'location' => $restored ] );

		self::drive( new SafetyBackupStep( self::fakeExport( new \ArrayObject(), $real ) ), $job );

		$this->assertSame( BackupName::safety( self::NOW ), $job->state['safetyBackup']['name'] );
		$this->assertSame( 'the backup being restored', file_get_contents( $backups . '/' . $restored ) );
		$this->assertFileExists( $backups . '/' . $newer );
		$this->assertSame( [], $job->cursor['safety']['state']['pruned'] );
		$this->assertSame( [ 'Safety backup: disk is small' ], $job->warnings, 'Nothing about removed backups.' );
	}

	public function test_a_retried_store_phase_neither_prunes_nor_warns(): void {
		$backups = $this->dir . '/backups';
		mkdir( $backups );
		$name               = BackupName::safety( self::NOW );
		$temp               = TempName::name();
		$job                = ImportJobFixture::make( $this->store, $this->dir, '/unused.wpress' );
		file_put_contents( $backups . '/' . $name, 'db' );
		$job->cursor        = [
			'safety' => [
				'index'  => array_search( 'store', ExportPipeline::DATABASE_ONLY_STEPS, true ),
				'cursor' => [
					'name'  => $name,
					'temp'  => $temp,
					'phase' => 'prune',
				],
				'state'  => [
					'workSub' => ImportFiles::SAFETY,
					'site'    => $job->state['site'],
					'backup'  => [ 'name' => $name ],
				],
			],
		];
		$store              = new StoreStep( $this->store, $backups, new Retention( 1 ), static fn(): int => self::NOW );
		$step               = new SafetyBackupStep( self::fakeExport( new \ArrayObject(), [ 'store' => $store ] ) );
		AttemptGuard::record( $job, 'safety_backup' );
		AttemptGuard::record( $job, 'safety_backup' ); // The previous request died in the prune.

		$old                = BackupName::forSite( 'https://new.test', self::NOW - 400 * 86400, 3 );
		file_put_contents( $backups . '/' . $old, 'old' );

		$step->run( $job, new NoDeadline() );
		$this->assertSame( [], $job->warnings, 'The safety backup never prunes, so a retry has nothing to skip.' );
		$this->assertFileExists( $backups . '/' . $old );
	}
}
