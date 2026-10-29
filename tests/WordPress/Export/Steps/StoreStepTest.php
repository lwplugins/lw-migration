<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Labels;
use LightweightPlugins\Migration\Backups\Retention;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\StoreStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class StoreStepTest extends MonkeyTestCase {

	private const NOW = 1767225600;

	private string $dir;

	private string $backups;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir     = TempDir::create();
		$this->backups = $this->dir . '/backups';
		$this->store   = new JobStore( $this->dir . '/jobs' );
		mkdir( $this->backups );
		FakeOptions::install();
		FakeWpdb::install(); // Retention revokes the links of a deleted backup.
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param Retention|null $retention      Global pruning rules.
	 * @param \Closure|null  $scheduleExists fn( string $id ): bool; null = the step's default.
	 */
	private function step( ?Retention $retention = null, ?\Closure $scheduleExists = null ): StoreStep {
		return new StoreStep( $this->store, $this->backups, $retention ?? new Retention(), static fn(): int => self::NOW, $scheduleExists );
	}

	/**
	 * A step that copies (as into another file system) in 1 kB slices; with
	 * $links false its folder has no hard links either (FAT).
	 */
	private function copyingStep( bool $links = true, ?Retention $retention = null ): StoreStep {
		return new StoreStep( $this->store, $this->backups, $retention ?? new Retention(), static fn(): int => self::NOW, null, null, new FileMover( false, 1024 ), $links ? null : static fn( string $from, string $to ): bool => false );
	}

	/** Runs the step until the copy is done and recorded, before anything is published. */
	private function runUntilCopied( StoreStep $step, Job $job ): void {
		for ( $calls = 1; ! isset( $job->cursor['moved'] ); $calls++ ) {
			$this->assertLessThan( 100, $calls, 'The copy finishes.' );
			$this->assertFalse( $step->run( $job, new CountdownDeadline( 2 ) )->isDone() );
		}
	}

	/**
	 * Old backups of schedule 0a1b2c3d (two), of another schedule and a manual one.
	 *
	 * @return array{mineOld: string, mineOlder: string, theirs: string, manual: string}
	 */
	private function scheduledBackups(): array {
		$names = [
			'mineOld'   => BackupName::forSchedule( 'https://old.test', self::NOW - 7200, 1, '0a1b2c3d' ),
			'mineOlder' => BackupName::forSchedule( 'https://old.test', self::NOW - 10800, 1, '0a1b2c3d' ),
			'theirs'    => BackupName::forSchedule( 'https://old.test', self::NOW - 20000, 1, 'ffffffff' ),
			'manual'    => BackupName::forSite( 'https://old.test', self::NOW - 30000, 1 ),
		];
		foreach ( $names as $name ) {
			file_put_contents( $this->backups . '/' . $name, 'x' );
		}

		return $names;
	}

	private function scheduledJob(): Job {
		$job                    = $this->job();
		$job->state['schedule'] = [
			'id'        => '0a1b2c3d',
			'retention' => [
				'count' => 2,
				'bytes' => 0,
				'days'  => 0,
			],
		];

		return $job;
	}

	/**
	 * @param array<string, mixed> $options Export options.
	 */
	private function job( array $options = [], string $bytes = 'archive' ): Job {
		$job = JobFixture::export( $this->store, $this->dir, $options );
		file_put_contents( $this->source( $job ), $bytes );

		return $job;
	}

	private function source( Job $job ): string {
		return $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
	}

	/** Calls the step like the Runner until it is done; the cursor survives between calls. */
	private function runToEnd( StoreStep $step, Job $job ): int {
		for ( $calls = 1; $calls <= 10; $calls++ ) {
			if ( $step->run( $job, new NoDeadline() )->isDone() ) {
				return $calls;
			}
		}
		$this->fail( 'The store step did not finish.' );
	}

	/** @return array<int, string> */
	private function backupFiles(): array {
		return array_values( array_diff( (array) scandir( $this->backups ), [ '.', '..' ] ) );
	}

	public function test_store_saves_the_names_first_then_publishes_without_leaving_a_temp(): void {
		$job  = $this->job();
		$step = $this->step();

		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone(), 'The first call only picks the names.' );
		$name = (string) $job->cursor['name'];
		$this->assertMatchesRegularExpression( '/^old-test-20260101-000000-\d{3}\.wpress\z/', $name );
		$this->assertMatchesRegularExpression( '/^\.lwm-[a-f0-9]{16}\.part\z/', (string) $job->cursor['temp'] );
		$this->assertFileExists( $this->source( $job ) );

		$this->runToEnd( $step, $job );
		$this->assertFileDoesNotExist( $this->source( $job ) );
		$this->assertSame( [ $name ], $this->backupFiles() );
		$this->assertSame( 'archive', file_get_contents( $this->backups . '/' . $name ) );
		$this->assertSame(
			[
				'name' => $name,
				'path' => $this->backups . '/' . $name,
				'size' => 7,
			],
			$job->state['backup']
		);
		$this->assertSame( [], $job->state['pruned'] );
	}

	public function test_a_stale_temp_from_an_earlier_attempt_is_removed_first(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		file_put_contents( $this->backups . '/' . $job->cursor['temp'], 'half a copy from a request that died' );

		$this->runToEnd( $step, $job );

		$this->assertSame( [ $job->state['backup']['name'] ], $this->backupFiles(), 'The stale temp is gone.' );
		$this->assertSame( 'archive', file_get_contents( $job->state['backup']['path'] ) );
	}

	public function test_store_never_overwrites_an_existing_backup(): void {
		$job = $this->job( [ 'kind' => 'pre-import' ], 'new' );
		file_put_contents( $this->backups . '/pre-import-20260101-000000.wpress', 'old' );
		$step = $this->step();

		$step->run( $job, new NoDeadline() );
		$this->assertSame( 'pre-import-20260101-000001.wpress', $job->cursor['name'], 'A taken name is skipped.' );

		file_put_contents( $this->backups . '/pre-import-20260101-000001.wpress', 'appeared meanwhile' );
		$this->runToEnd( $step, $job );

		$this->assertSame( 'pre-import-20260101-000002.wpress', $job->state['backup']['name'], 'A name taken after it was picked is picked again.' );
		$this->assertSame( 'new', file_get_contents( $this->backups . '/pre-import-20260101-000002.wpress' ) );
		$this->assertSame( 'appeared meanwhile', file_get_contents( $this->backups . '/pre-import-20260101-000001.wpress' ) );
		$this->assertSame( 'old', file_get_contents( $this->backups . '/pre-import-20260101-000000.wpress' ) );
		$this->assertCount( 3, $this->backupFiles(), 'No temp is left behind.' );
	}

	public function test_a_pre_reset_backup_gets_its_name_and_prunes_nothing(): void {
		$old = BackupName::forSite( 'https://old.test', self::NOW - 400 * 86400, 1 );
		file_put_contents( $this->backups . '/' . $old, 'an older manual backup' );
		$job = $this->job( [ 'kind' => 'pre-reset' ] );

		$this->runToEnd( $this->step( new Retention( 1, 1, 1 ) ), $job );

		$this->assertMatchesRegularExpression( '/^pre-reset-\d{8}-\d{6}\.wpress\z/', (string) $job->state['backup']['name'] );
		$this->assertSame( 'archive', file_get_contents( $this->backups . '/' . $job->state['backup']['name'] ) );
		$this->assertFileExists( $this->backups . '/' . $old, 'The backup before a reset never prunes.' );
		$this->assertSame( [], $job->state['pruned'] );
	}

	public function test_during_an_unfinished_move_a_name_in_the_moving_folder_is_taken_too(): void {
		mkdir( $this->dir . '/moving' );
		file_put_contents( $this->dir . '/moving/pre-import-20260101-000000.wpress', 'moved there' );
		$job  = $this->job( [ 'kind' => 'pre-import' ], 'new' );
		$step = new StoreStep( $this->store, $this->backups, new Retention(), static fn(): int => self::NOW, null, null, null, null, $this->dir . '/moving' );

		$this->runToEnd( $step, $job );

		$this->assertSame( 'pre-import-20260101-000001.wpress', $job->state['backup']['name'], 'Never a name the move would collide with.' );
		$this->assertSame( 'moved there', file_get_contents( $this->dir . '/moving/pre-import-20260101-000000.wpress' ) );
	}

	public function test_a_request_that_died_after_the_copy_publishes_once(): void {
		$job  = $this->job();
		$step = $this->step();
		$this->runUntilCopied( $step, $job );
		$this->assertSame( [ $job->cursor['temp'] ], $this->backupFiles(), 'Only the hidden temp: no partial file under a backup name.' );

		$this->runToEnd( $step, $job );

		$this->assertSame( [ $job->state['backup']['name'] ], $this->backupFiles() );
		$this->assertSame( 'archive', file_get_contents( $job->state['backup']['path'] ) );
	}

	public function test_a_request_that_died_between_link_and_unlink_publishes_once(): void {
		$job  = $this->job();
		$step = $this->step();
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];
		link( $this->backups . '/' . $job->cursor['temp'], $this->backups . '/' . $name );

		$this->runToEnd( $step, $job );

		$this->assertSame( $name, $job->state['backup']['name'], 'The published name is kept, not re-picked.' );
		$this->assertSame( [ $name ], $this->backupFiles() );
	}

	public function test_store_fails_when_the_archive_is_gone(): void {
		$job = JobFixture::export( $this->store, $this->dir );
		$job->cursor = [
			'name' => 'old-test-20260101-000000-001.wpress',
			'temp' => '.lwm-0123456789abcdef.part',
		];
		$this->expectException( ExportException::class );
		$this->step()->run( $job, new NoDeadline() );
	}

	public function test_a_read_only_backups_folder_fails_without_a_php_warning(): void {
		$job = $this->job();
		chmod( $this->backups, 0555 );
		try {
			$this->runToEnd( $this->step(), $job );
			$this->fail( 'The store step should fail.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'Could not move the finished backup into the backups folder.', $e->getMessage() );
		} finally {
			chmod( $this->backups, 0755 );
		}
		$this->assertFileExists( $this->source( $job ), 'The archive stays in the work folder.' );
		$this->assertSame( [], $this->backupFiles() );
	}

	public function test_a_failing_prune_is_a_warning_and_the_backup_stays_recorded(): void {
		file_put_contents( $this->backups . '/old-site-20200101-000000-001.wpress', 'x' );
		touch( $this->backups . '/old-site-20200101-000000-001.wpress', 1000 );
		FakeOptions::install( [ Options::LABELS => [ 'old-site-20200101-000000-001.wpress' => 'Label' ] ] );
		$backups = $this->backups;
		Functions\when( 'update_option' )->alias(
			static function () use ( $backups ): bool {
				throw new \RuntimeException( 'Database went away at ' . $backups . '/old-site-20200101-000000-001.wpress.' . str_repeat( 'x', 2000 ) );
			}
		);
		$job = $this->job();

		$this->runToEnd( $this->step( new Retention( 1 ) ), $job );

		$this->assertFileExists( $job->state['backup']['path'] );
		$this->assertLessThan( 1024, strlen( $job->warnings[0] ), 'The message is clipped.' );
		$this->assertSame( 1, $job->warningCount );
		$this->assertSame( [ 'old-site-20200101-000000-001.wpress' ], $job->state['pruned'], 'What was deleted before the failure is reported.' );
		$this->assertStringContainsString( 'Database went away at [backups]/old-site-20200101-000000-001.wpress.', $job->warnings[0] );
		$this->assertStringNotContainsString( $this->backups, $job->warnings[0], 'Server paths are redacted.' );
		$this->assertSame( $job->warnings, $job->newWarnings, 'The Runner writes it to job.log.' );
	}

	public function test_a_prune_that_keeps_dying_is_skipped_instead_of_failing_the_job(): void {
		file_put_contents( $this->backups . '/old-site-20200101-000000-001.wpress', 'x' );
		touch( $this->backups . '/old-site-20200101-000000-001.wpress', 1000 );
		$job  = $this->job();
		$step = $this->step( new Retention( 1 ) );
		for ( $calls = 0; 'prune' !== ( $job->cursor['phase'] ?? '' ); $calls++ ) {
			$this->assertLessThan( 5, $calls, 'The store step reaches its prune phase.' );
			$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		}
		// The Runner counted a second start of the prune phase: the first one died.
		$job->attempt = [
			'print' => 'x',
			'count' => 2,
		];

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertFileExists( $this->backups . '/old-site-20200101-000000-001.wpress' );
		$this->assertSame( [], $job->state['pruned'] );
		$this->assertSame( 1, $job->warningCount );
		$this->assertFileExists( $job->state['backup']['path'] );
	}

	public function test_a_published_backup_is_recorded_before_any_other_work_on_resume(): void {
		$job  = $this->job( [], 'published' );
		$step = $this->copyingStep();
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];
		link( $this->backups . '/' . $job->cursor['temp'], $this->backups . '/' . $name );
		unlink( $this->backups . '/' . $job->cursor['temp'] );
		$job->state['workSub'] = 'Not Valid'; // Makes the rest of the call fail.

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The broken work folder must fail the call.' );
		} catch ( JobException $e ) {
			$this->assertSame(
				[
					'name' => $name,
					'path' => $this->backups . '/' . $name,
					'size' => 9,
				],
				$job->state['backup'],
				'A job that fails from here on still names its complete backup.'
			);
		}
	}

	public function test_a_name_holding_another_file_on_a_reused_inode_is_never_recorded(): void {
		$job  = $this->job( [], random_bytes( 4096 ) );
		$step = $this->copyingStep();
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];
		rename( $this->backups . '/' . $job->cursor['temp'], $this->backups . '/' . $name );
		// Same device and inode under the name, other bytes (FAT reuses a freed start cluster at once).
		file_put_contents( $this->backups . '/' . $name, 'foreign' );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Another file under the name must stop the store.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'The saved copy of the backup did not match the original. Start the backup again.', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'backup', $job->state );
		$this->assertSame( 4096, filesize( $this->source( $job ) ), 'The original stays.' );
	}

	public function test_a_scheduled_backup_gets_the_suffix_and_prunes_with_its_own_rules(): void {
		$old  = $this->scheduledBackups();
		$job  = $this->scheduledJob();
		$seen = [];

		$this->runToEnd(
			$this->step(
				new Retention( 1 ),
				static function ( string $id ) use ( &$seen ): bool {
					$seen[] = $id;
					return true;
				}
			),
			$job
		);

		$name = (string) $job->state['backup']['name'];
		$this->assertMatchesRegularExpression( '/^old-test-20260101-000000-\d{3}-0a1b2c3d\.wpress\z/', $name );
		$this->assertSame( [ $old['mineOlder'] ], $job->state['pruned'], 'Its own rules (2 backups), not the global one (1), and only its own backups.' );
		$this->assertEqualsCanonicalizing( [ $name, $old['mineOld'], $old['theirs'], $old['manual'] ], $this->backupFiles() );
		$this->assertSame( [ '0a1b2c3d' ], $seen, 'The schedule is looked up when pruning starts.' );
	}

	public function test_a_run_whose_schedule_was_deleted_prunes_nothing(): void {
		$old = $this->scheduledBackups();
		$job = $this->scheduledJob();

		$this->runToEnd( $this->step( new Retention( 1 ), static fn( string $id ): bool => false ), $job );

		$this->assertSame( [], $job->state['pruned'] );
		$this->assertSame( 0, $job->warningCount, 'A deleted schedule is no failure.' );
		$this->assertEqualsCanonicalizing( array_merge( [ (string) $job->state['backup']['name'] ], array_values( $old ) ), $this->backupFiles(), 'Its backups stay.' );
	}

	public function test_without_a_schedule_lookup_scheduled_backups_are_never_pruned(): void {
		$old = $this->scheduledBackups();
		$job = $this->scheduledJob();

		$this->runToEnd( $this->step( new Retention( 1 ) ), $job );

		$this->assertSame( [], $job->state['pruned'] );
		$this->assertCount( 5, $this->backupFiles() );
		$this->assertSame( 0, $job->warningCount );
		$this->assertContains( $old['manual'], $this->backupFiles() );
	}

	public function test_a_manual_backup_never_prunes_scheduled_ones(): void {
		$scheduled = BackupName::forSchedule( 'https://old.test', self::NOW - 7200, 1, '0a1b2c3d' );
		$manual    = BackupName::forSite( 'https://old.test', self::NOW - 7200, 1 );
		foreach ( [ $scheduled, $manual ] as $name ) {
			file_put_contents( $this->backups . '/' . $name, 'x' );
		}
		$job = $this->job();

		$this->runToEnd( $this->step( new Retention( 1 ), static fn( string $id ): bool => true ), $job );

		$this->assertSame( [ $manual ], $job->state['pruned'] );
		$this->assertFileExists( $this->backups . '/' . $scheduled );
	}

	/**
	 * Final review item 3: while a move is unfinished both folders hold our
	 * backups; retention judges them together and removes a pruned backup
	 * from both, so no copy of it stays listed.
	 */
	public function test_during_an_unfinished_move_retention_prunes_in_both_folders(): void {
		$moving = $this->dir . '/moving';
		mkdir( $moving );
		$old   = BackupName::forSite( 'https://old.test', self::NOW - 7200, 1 );
		$older = BackupName::forSite( 'https://old.test', self::NOW - 9000, 1 );
		file_put_contents( $this->backups . '/' . $old, 'old' );
		file_put_contents( $moving . '/' . $old, 'old' ); // Copied by the move, original not removed yet.
		file_put_contents( $moving . '/' . $older, 'older' ); // Already moved.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Labels::set( $old, 'before the redesign' );
		Labels::set( $older, 'launch' );
		$job  = $this->job();
		$step = new StoreStep( $this->store, $this->backups, new Retention( 1 ), static fn(): int => self::NOW, null, null, null, null, $moving );

		$this->runToEnd( $step, $job );

		$this->assertEqualsCanonicalizing( [ $old, $older ], $job->state['pruned'] );
		foreach ( [ $this->backups . '/' . $old, $moving . '/' . $old, $moving . '/' . $older ] as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->assertSame( [ $job->state['backup']['name'] ], $this->backupFiles() );
		$this->assertSame( '', Labels::get( $old ) );
		$this->assertSame( '', Labels::get( $older ) );
	}

	public function test_a_job_with_a_schedule_but_no_valid_id_prunes_nothing(): void {
		$old = $this->scheduledBackups();
		foreach ( [ [ 'id' => '../x' ], [], 'junk' ] as $schedule ) {
			$job                    = $this->job();
			$job->state['schedule'] = $schedule;
			$before                 = $this->backupFiles();

			$this->runToEnd( $this->step( new Retention( 1 ), static fn( string $id ): bool => true ), $job );

			$this->assertSame( [], $job->state['pruned'], 'Neither the manual nor any schedule scope.' );
			$this->assertSame( 0, $job->warningCount );
			$this->assertEqualsCanonicalizing( array_merge( $before, [ (string) $job->state['backup']['name'] ] ), $this->backupFiles() );
		}
		$this->assertContains( $old['manual'], $this->backupFiles() );
	}

	public function test_a_name_given_up_after_a_collision_is_not_left_recorded(): void {
		$job = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$taken = (string) $job->cursor['name'];
		file_put_contents( $this->backups . '/' . $taken, 'someone else' );

		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone(), 'The archive goes into the temp first.' );
		$this->assertArrayNotHasKey( 'backup', $job->state, 'A file under the name is not ours while the copy is under way.' );
		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertNotSame( $taken, $job->cursor['name'] );
		$this->assertArrayNotHasKey( 'backup', $job->state, 'The other file is never recorded as this backup.' );
		$this->runToEnd( $step, $job );
		$this->assertSame( 'archive', file_get_contents( $job->state['backup']['path'] ) );
	}

	public function test_a_backup_is_copied_in_slices_into_another_file_system(): void {
		$bytes = random_bytes( 10240 );
		$job   = $this->job( [], $bytes );
		$step  = $this->copyingStep();
		$more  = 0;
		while ( ! isset( $job->state['backup'] ) ) {
			$this->assertLessThan( 100, $more, 'The store step publishes.' );
			$this->assertFileExists( $this->source( $job ), 'The work archive stays until the backup is published.' );
			$this->assertFalse( $step->run( $job, new CountdownDeadline( 2 ) )->isDone() );
			++$more;
		}
		$this->assertGreaterThan( 4, $more, 'Several calls copy and verify the slices.' );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ $job->state['backup']['name'] ], $this->backupFiles(), 'No temp is left.' );
		$this->assertSame( $bytes, file_get_contents( $job->state['backup']['path'] ) );
		$this->assertFileDoesNotExist( $this->source( $job ) );
		$this->assertArrayNotHasKey( 'copy', $job->cursor );
	}

	public function test_a_missing_chosen_folder_fails_without_writing_anywhere(): void {
		$chosen = $this->dir . '/disk/backups';
		$job    = $this->job();
		$before = $this->tree();
		$step   = new StoreStep(
			$this->store,
			$chosen,
			new Retention(),
			static fn(): int => self::NOW,
			null,
			static function (): void {
				throw new ExportException( 'The backups folder is missing.' );
			}
		);

		try {
			$this->runToEnd( $step, $job );
			$this->fail( 'The store step must fail.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'The backups folder is missing.', $e->getMessage() );
		}
		$this->assertDirectoryDoesNotExist( $this->dir . '/disk' );
		$this->assertSame( $before, $this->tree(), 'Nothing was created: no folder, no temp, no default backups folder.' );
		$this->assertSame( 'archive', file_get_contents( $this->source( $job ) ) );
	}

	/** @return array<int, string> Every path below the test folder. */
	private function tree(): array {
		$paths = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $path => $info ) {
			$paths[] = (string) $path;
		}
		sort( $paths );

		return $paths;
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function links(): array {
		return [
			'hard link' => [ true ],
			'copy'      => [ false ],
		];
	}

	/**
	 * @dataProvider links
	 */
	public function test_a_staged_backup_is_owner_only_whatever_the_umask( bool $links ): void {
		$this->assertSame( 0600, $this->publishedMode( $links, 0022, null ) );
		$this->assertSame( 0600, $this->publishedMode( $links, 0, null ), 'A permissive umask does not widen it.' );
	}

	/**
	 * @dataProvider filtered
	 */
	public function test_the_file_mode_filter_may_add_group_read_only( bool $links, int $filter, int $expected ): void {
		$this->assertSame( $expected, $this->publishedMode( $links, 0022, $filter ) );
	}

	/**
	 * @return array<string, array{0: bool, 1: int, 2: int}>
	 */
	public static function filtered(): array {
		$cases = [];
		foreach ( [ 'hard link' => true, 'copy' => false ] as $label => $links ) {
			$cases[ $label . ', group read' ]           = [ $links, 0640, 0640 ];
			$cases[ $label . ', never more than that' ] = [ $links, 0666, 0640 ];
			$cases[ $label . ', owner keeps its bits' ] = [ $links, 0, 0600 ];
		}

		return $cases;
	}

	/**
	 * @param bool     $links  Hard links stand in for the copy.
	 * @param int      $umask  The umask during the run.
	 * @param int|null $filter What the lw_migration_backup_file_mode filter returns; null = untouched.
	 */
	private function publishedMode( bool $links, int $umask, ?int $filter ): int {
		$old = umask( $umask );
		if ( null !== $filter ) {
			\Brain\Monkey\Filters\expectApplied( 'lw_migration_backup_file_mode' )->andReturn( $filter );
		}
		try {
			$job = $this->job();
			chmod( $this->source( $job ), 0600 );
			$step = new StoreStep( $this->store, $this->backups, new Retention(), static fn(): int => self::NOW, null, null, new FileMover( $links ) );

			$this->runToEnd( $step, $job );
		} finally {
			umask( $old );
		}

		clearstatcache();

		return fileperms( $job->state['backup']['path'] ) & 0777;
	}

	/**
	 * @dataProvider links
	 */
	public function test_a_crash_after_publishing_removes_the_original_once( bool $links ): void {
		$job  = $this->job( [], random_bytes( 4096 ) );
		$step = new StoreStep( $this->store, $this->backups, new Retention(), static fn(): int => self::NOW, null, null, new FileMover( $links, 1024 ) );
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];
		$temp = $this->backups . '/' . $job->cursor['temp'];
		$copy = (string) file_get_contents( $temp );
		// The request published the backup and died before the original went and the cursor was saved.
		link( $temp, $this->backups . '/' . $name );
		unlink( $temp );

		$this->runToEnd( $step, $job );

		$this->assertSame( $name, $job->state['backup']['name'] );
		$this->assertSame( [ $name ], $this->backupFiles() );
		$this->assertSame( $copy, file_get_contents( $this->backups . '/' . $name ) );
		$this->assertFileDoesNotExist( $this->source( $job ) );
	}

	public function test_a_work_archive_that_changed_after_the_copy_is_never_published_or_removed(): void {
		$job  = $this->job( [], random_bytes( 4096 ) );
		$step = $this->copyingStep();
		$this->runUntilCopied( $step, $job );
		file_put_contents( $this->source( $job ), 'changed', FILE_APPEND );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'A changed original must stop the store.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'The saved copy of the backup did not match the original. Start the backup again.', $e->getMessage() );
		}
		$this->assertSame( [ $job->cursor['temp'] ], $this->backupFiles(), 'Nothing was published.' );
		$this->assertSame( 4103, filesize( $this->source( $job ) ) );
	}

	public function test_an_original_replaced_after_publishing_is_kept(): void {
		$job  = $this->job( [], random_bytes( 4096 ) );
		$step = $this->copyingStep();
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];
		link( $this->backups . '/' . $job->cursor['temp'], $this->backups . '/' . $name );
		unlink( $this->backups . '/' . $job->cursor['temp'] );
		unlink( $this->source( $job ) );
		file_put_contents( $this->source( $job ), 'another file under the work name' );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Another file under the original name must stop the store.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'The saved copy of the backup did not match the original. Start the backup again.', $e->getMessage() );
		}
		$this->assertSame( 'another file under the work name', file_get_contents( $this->source( $job ) ) );
		$this->assertSame( $name, $job->state['backup']['name'] ?? null, 'The failed job still names its published backup.' );
		$this->assertSame( 4096, filesize( $this->backups . '/' . $name ) );
	}

	public function test_without_hard_links_the_name_is_saved_before_it_is_reserved(): void {
		$job  = $this->job( [], 'new' );
		$step = $this->copyingStep( false );
		$this->runUntilCopied( $step, $job );
		$name = (string) $job->cursor['name'];

		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $name, $job->cursor['reserving'], 'The name is saved first.' );
		$this->assertSame( [ $job->cursor['temp'] ], $this->backupFiles(), 'Nothing is created under it yet.' );

		$this->runToEnd( $step, $job );

		$this->assertSame( [ $name ], $this->backupFiles() );
		$this->assertSame( 'new', file_get_contents( $job->state['backup']['path'] ) );
	}

	public function test_a_request_that_died_after_reserving_publishes_once_and_retention_ignores_empty_files(): void {
		$older = BackupName::forSite( 'https://old.test', self::NOW - 7200, 1 );
		file_put_contents( $this->backups . '/' . $older, 'older' );
		$empty = BackupName::forSite( 'https://old.test', self::NOW - 60, 1 );
		touch( $this->backups . '/' . $empty ); // An empty reservation another dead store left.
		$job  = $this->job( [], 'new' );
		$step = $this->copyingStep( false, new Retention( 2 ) );
		$this->runUntilCopied( $step, $job );
		$step->run( $job, new NoDeadline() );
		$name = (string) $job->cursor['reserving'];
		touch( $this->backups . '/' . $name ); // The reservation was made, the request died before the rename.

		$this->runToEnd( $step, $job );

		$this->assertSame( $name, $job->state['backup']['name'], 'The empty reservation is ours again.' );
		$this->assertSame( 'new', file_get_contents( $this->backups . '/' . $name ) );
		$this->assertSame( [], $job->state['pruned'], 'The empty file counts for nothing: the older backup stays.' );
		$this->assertFileExists( $this->backups . '/' . $older );
		$this->assertEqualsCanonicalizing( [ $name, $older, $empty ], $this->backupFiles() );
	}

	public function test_without_hard_links_a_name_taken_meanwhile_is_never_replaced(): void {
		$job  = $this->job( [], 'new' );
		$step = $this->copyingStep( false );
		$this->runUntilCopied( $step, $job );
		$step->run( $job, new NoDeadline() );
		$taken = (string) $job->cursor['reserving'];
		file_put_contents( $this->backups . '/' . $taken, 'someone else' );

		$this->runToEnd( $step, $job );

		$this->assertNotSame( $taken, $job->state['backup']['name'] );
		$this->assertSame( 'someone else', file_get_contents( $this->backups . '/' . $taken ) );
		$this->assertSame( 'new', file_get_contents( $job->state['backup']['path'] ) );
		$this->assertCount( 2, $this->backupFiles(), 'No temp and no reservation is left behind.' );
	}
}
