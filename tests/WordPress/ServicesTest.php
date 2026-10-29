<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Link\LinkPipeline;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Link\Steps\PullDownloadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Reset\ResetType;
use LightweightPlugins\Migration\Reset\Steps\NotAsRoot;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudRetentionStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\LocalCleanupStep;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\Steps\FinishStep;
use LightweightPlugins\Migration\Storage\Steps\MoveStep;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\SafeWeb;
use LightweightPlugins\Migration\Tests\Support\ScriptedPeer;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class ServicesTest extends MonkeyTestCase {

	private const ROOT = 'lw-migration-0123456789ab';

	private Services $services;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install( [ Options::STORAGE_DIR => self::ROOT ] );
		FakeOptions::install(
			[
				Options::RETENTION => [
					'count' => 3,
					'days'  => 7,
				],
			]
		);
		$this->services = new Services();
	}

	protected function tearDown(): void {
		TempDir::remove( WP_CONTENT_DIR );
		parent::tearDown();
	}

	public function test_storage_reads_the_backup_folder_on_every_call(): void {
		$db = $GLOBALS['wpdb'];
		$this->assertSame( WP_CONTENT_DIR . '/' . self::ROOT . '/backups', $this->services->storage()->backupsDir() );

		$db->rows[ Options::BACKUP_DIR ]  = [
			'value'    => '{"path":"/h/chosen","moving":null,"marker":"0123456789abcdef"}',
			'autoload' => 'off',
		];
		$db->rows[ Options::STORAGE_DIR ] = [
			'value'    => 'lw-migration-ffffffffffff',
			'autoload' => 'off',
		];

		$storage = $this->services->storage();
		$this->assertSame( '/h/chosen', $storage->backupsDir() );
		$this->assertSame( WP_CONTENT_DIR . '/' . self::ROOT, $storage->root(), 'The root name is read once.' );
		$this->assertSame( WP_CONTENT_DIR . '/' . self::ROOT . '/jobs/0123456789abcdef', $this->services->jobs()->dir( '0123456789abcdef' ) );
	}

	public function test_the_file_mover_uses_hard_links_unless_the_filter_turns_them_off(): void {
		$this->assertTrue( $this->services->fileMover()->links() );

		Filters\expectApplied( 'lw_migration_move_links' )->once()->andReturn( false );
		$this->assertFalse( $this->services->fileMover()->links() );
	}

	public function test_the_wired_store_step_never_creates_a_missing_chosen_folder(): void {
		$chosen = dirname( WP_CONTENT_DIR ) . '/lwm-chosen-' . bin2hex( random_bytes( 4 ) ) . '/backups';
		$GLOBALS['wpdb']->rows[ Options::BACKUP_DIR ] = [
			'value'    => (string) wp_json_encode( [ 'path' => $chosen, 'moving' => null, 'marker' => '0123456789abcdef' ] ),
			'autoload' => 'off',
		];
		$job = JobFixture::export( $this->services->jobs(), WP_CONTENT_DIR . '/site' );
		file_put_contents( $this->services->jobs()->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, 'archive' );

		try {
			$this->services->steps( 'export' )['store']->run( $job, new NoDeadline() );
			$this->fail( 'A missing chosen folder must fail the store step.' );
		} catch ( ExportException $e ) {
			$this->assertSame( FolderMessages::of( FolderRefusedException::MISSING ), $e->getMessage() );
		}
		$this->assertDirectoryDoesNotExist( dirname( $chosen ) );
		$this->assertFileExists( $this->services->jobs()->dir( $job->id ) . '/' . ExportFiles::ARCHIVE );
	}

	public function test_redact_paths_hide_the_chosen_folders(): void {
		$GLOBALS['wpdb']->rows[ Options::BACKUP_DIR ] = [
			'value'    => '{"path":"/h/chosen","moving":"/h/next","marker":"0123456789abcdef"}',
			'autoload' => 'off',
		];

		$paths = $this->services->redactPaths();
		$this->assertContains( '/h/chosen', $paths );
		$this->assertContains( '/h/next', $paths );
		$this->assertContains( WP_CONTENT_DIR . '/' . self::ROOT, $paths );
	}

	public function test_jobs_and_backups_live_in_the_protected_storage_root(): void {
		$root = WP_CONTENT_DIR . '/' . self::ROOT;
		$this->assertSame( $root, $this->services->storage()->root() );
		$job = $this->services->jobs()->create( 'export', ExportPipeline::STEPS, [], 1 );
		$this->assertSame( $root . '/jobs/' . $job->id, $this->services->jobs()->dir( $job->id ) );
		$this->assertSame( 3, $this->services->retention()->maxCount );
		$this->assertSame( 7, $this->services->retention()->maxDays );
		$this->assertSame( array_merge( ExportPipeline::stepsFor( true, false ), CloudPipeline::EXPORT_STEPS, [ CloudDownloadStep::KEY ] ), array_keys( $this->services->steps( 'export' ) ), 'Every export step is registered, the network one and the cloud ones too.' );
	}

	/**
	 * Stores a scheduled export of schedule 0a1b2c3d (keep 1) through the wired store step
	 * (which also protects the default backups folder: only the backups are compared).
	 *
	 * @return array{pruned: array<int, string>, files: array<int, string>}
	 */
	private function storeScheduledBackup(): array {
		$backups = $this->services->storage()->backupsDir();
		mkdir( $backups, 0755, true );
		$older = BackupName::forSchedule( 'https://old.test', 1767225600 - 3600, 1, '0a1b2c3d' );
		file_put_contents( $backups . '/' . $older, 'x' );
		$job                    = JobFixture::export( $this->services->jobs(), WP_CONTENT_DIR . '/site' );
		$job->state['schedule'] = [
			'id'        => '0a1b2c3d',
			'retention' => [
				'count' => 1,
				'bytes' => 0,
				'days'  => 0,
			],
		];
		file_put_contents( $this->services->jobs()->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, 'archive' );
		$step = $this->services->steps( 'export' )['store'];
		for ( $calls = 0; $calls < 10 && ! $step->run( $job, new NoDeadline() )->isDone(); $calls++ ) {
			continue;
		}

		return [
			'pruned' => $job->state['pruned'] ?? [],
			'files'  => array_values( array_diff( preg_grep( '/\.wpress\z/', (array) scandir( $backups ) ), [ $job->state['backup']['name'] ?? '' ] ) ),
		];
	}

	public function test_a_scheduled_run_prunes_while_its_schedule_exists(): void {
		FakeOptions::$values[ Options::SCHEDULES ] = [ [ 'id' => '0a1b2c3d' ] ];

		$stored = $this->storeScheduledBackup();

		$this->assertCount( 1, $stored['pruned'], 'The wired lookup finds the stored schedule.' );
		$this->assertSame( [], $stored['files'] );
	}

	public function test_a_scheduled_run_of_a_deleted_schedule_keeps_its_backups_and_writes_no_default(): void {
		$stored = $this->storeScheduledBackup();

		$this->assertSame( [], $stored['pruned'] );
		$this->assertCount( 1, $stored['files'] );
		$this->assertArrayNotHasKey( Options::SCHEDULES, FakeOptions::$values, 'The lookup never creates the default schedule (R7).' );
	}

	public function test_cloud_steps_are_registered_for_exports_imports_and_cloud_jobs(): void {
		$services = $this->services;

		foreach ( [ 'export', 'import', CloudJobs::TYPE ] as $type ) {
			$steps = $services->steps( $type );
			foreach ( [ CloudUploadStep::KEY, CloudRetentionStep::KEY, LocalCleanupStep::KEY, CloudDownloadStep::KEY ] as $key ) {
				$this->assertArrayHasKey( $key, $steps, $type . ' ' . $key );
			}
		}
	}

	public function test_an_export_that_fails_in_its_upload_marks_the_pending_copy_failed(): void {
		$jobs                 = $this->services->jobs();
		$job                  = $jobs->create( 'export', CloudPipeline::EXPORT_STEPS, [], 1 );
		$job->state['backup'] = [
			'name' => 'a.wpress',
			'path' => WP_CONTENT_DIR . '/gone/a.wpress', // the local backup vanished mid-upload
			'size' => 7,
		];
		$job->state['cloud']  = CloudPlan::manual( [ 'aaaa1111' ], true )->toArray();
		$job->cursor          = [
			'index'   => 0,
			'session' => ( new UploadSession( 's3', 'a.wpress', 7, 5, 5, 'u1', [] ) )->toArray(),
		];
		$jobs->save( $job );
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::PENDING, 1 );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$after = $this->services->runner( 'export', new MemorySecretStore() )->tick( $job->id, new NoDeadline(), 2 );

		$this->assertSame( Job::FAILED, $after->status );
		$copies = json_decode( (string) $GLOBALS['wpdb']->value( Options::CLOUD_COPIES ), true );
		$this->assertSame( CloudCopies::FAILED, $copies['a.wpress']['aaaa1111']['status'] );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_a_cancelled_cloud_download_loses_its_temp_and_frees_the_site(): void {
		$backups = $this->services->storage()->backupsDir();
		mkdir( $backups, 0777, true );
		file_put_contents( $backups . '/.lwm-0123456789abcdef.part', 'half a download' );
		file_put_contents( $backups . '/b.wpress', 'kept' );
		$jobs        = $this->services->jobs();
		$job         = $jobs->create( CloudJobs::TYPE, [ CloudDownloadStep::KEY ], [ 'action' => CloudJobs::FETCH ], 1 );
		$job->cursor = [
			'temp'   => '.lwm-0123456789abcdef.part',
			'offset' => 5,
		];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$cancelled = $this->services->runner( CloudJobs::TYPE, new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertSame( Job::CANCELLED, $cancelled->status );
		$this->assertFileDoesNotExist( $backups . '/.lwm-0123456789abcdef.part' );
		$this->assertSame( 'kept', file_get_contents( $backups . '/b.wpress' ) );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_unknown_job_type_is_refused(): void {
		$this->expectException( JobException::class );
		$this->services->steps( 'teleport' );
	}

	public function test_steps_of_a_reset_job(): void {
		$steps = $this->services->steps( ResetJobs::TYPE );
		$this->assertIsArray( $steps );
		$this->assertSame( [], array_diff( array_keys( $steps ), ResetType::STEP_KEYS ), 'Only reset step keys.' );
		$this->assertArrayHasKey( ResetType::MEDIA_STEP, $steps );
		foreach ( $steps as $key => $step ) {
			$this->assertInstanceOf( NotAsRoot::class, $step, $key . ' refuses a file reset as root.' );
		}
	}

	public function test_a_reset_past_its_swap_marker_defers_a_cancel(): void {
		$jobs   = $this->services->jobs();
		$job    = $jobs->create( ResetJobs::TYPE, ResetType::steps( 'database', false ), [ 'type' => 'database', 'backup' => false ], 1 );
		$export = $jobs->create( 'export', ExportPipeline::STEPS, [], 1 );
		$runner = $this->services->runner( ResetJobs::TYPE, new MemorySecretStore() );
		$this->assertFalse( $runner->defersCancel( $job ) );
		touch( $jobs->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED );
		touch( $jobs->dir( $export->id ) . '/' . ImportFiles::SWAP_STARTED );
		$this->assertTrue( $runner->defersCancel( $job ) );
		$this->assertTrue( ResetJobs::defersCancel( $this->services, $job ) );
		$this->assertFalse( ResetJobs::defersCancel( $this->services, $export ), 'Only a reset job.' );
	}

	public function test_a_cancelled_export_loses_its_work_files_and_frees_the_site(): void {
		$jobs = $this->services->jobs();
		$job  = $jobs->create( 'export', ExportPipeline::STEPS, [], 1 );
		$dir  = $jobs->dir( $job->id );
		mkdir( $dir . '/safety' );
		foreach ( [ ExportFiles::ARCHIVE, ExportFiles::LIST, ExportFiles::LIST . '.dirs', ExportFiles::DATABASE, 'safety/' . ExportFiles::ARCHIVE ] as $file ) {
			file_put_contents( $dir . '/' . $file, 'x' );
		}
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );
		$job->stepIndex = (int) array_search( 'store', ExportPipeline::STEPS, true );
		$job->cursor    = [
			'name' => 'old-test-20260101-000000-001.wpress',
			'temp' => '.lwm-0123456789abcdef.part',
		];
		$jobs->save( $job );
		$backups = $this->services->storage()->backupsDir();
		mkdir( $backups, 0755, true );
		file_put_contents( $backups . '/.lwm-0123456789abcdef.part', 'half a copy' );
		file_put_contents( $backups . '/keep.wpress', 'x' );

		$cancelled = $this->services->runner( 'export', new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertSame( Job::CANCELLED, $cancelled->status );
		$this->assertSame( Job::CANCELLED, $jobs->load( $job->id )->status, 'The terminal state is saved.' );
		foreach ( [ ExportFiles::ARCHIVE, ExportFiles::LIST, ExportFiles::LIST . '.dirs', ExportFiles::DATABASE, 'safety' ] as $file ) {
			$this->assertFileDoesNotExist( $dir . '/' . $file );
		}
		$this->assertNull( $this->services->lock()->holder( 3 ) );
		$this->assertFileDoesNotExist( $backups . '/.lwm-0123456789abcdef.part', 'The temp copy of a cancelled store is removed.' );
		$this->assertFileExists( $backups . '/keep.wpress' );
	}

	public function test_move_steps_are_wired(): void {
		$steps = $this->services->steps( MoveJobs::TYPE );
		$this->assertSame( MoveJobs::STEPS, array_keys( $steps ) );
		$this->assertInstanceOf( MoveStep::class, $steps['move'] );
		$this->assertInstanceOf( FinishStep::class, $steps['finish'] );
	}

	public function test_cancel_removes_only_the_temp_in_flight(): void {
		$base = TempDir::create();
		try {
			$from = $base . '/old';
			$to   = $base . '/new';
			mkdir( $from );
			mkdir( $to );
			file_put_contents( $from . '/a.wpress', 'original' );
			file_put_contents( $to . '/b.wpress', 'moved before' );
			file_put_contents( $to . '/.lwm-0123456789abcdef.part', 'half a copy' );
			$jobs = $this->services->jobs();
			$job  = $jobs->create(
				MoveJobs::TYPE,
				MoveJobs::STEPS,
				[
					'fromDir' => $from,
					'toDir'   => $to,
				],
				1
			);
			$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );
			$job->cursor = [
				'checked' => true,
				'name'    => 'a.wpress',
				'temp'    => '.lwm-0123456789abcdef.part',
				'phase'   => 'copy',
				'copy'    => [ 'offset' => 4 ],
			];
			$jobs->save( $job );

			$cancelled = $this->services->runner( MoveJobs::TYPE, new MemorySecretStore() )->cancel( $job->id, 2 );

			$this->assertSame( Job::CANCELLED, $cancelled->status );
			$this->assertFileDoesNotExist( $to . '/.lwm-0123456789abcdef.part' );
			$this->assertSame( 'original', file_get_contents( $from . '/a.wpress' ) );
			$this->assertSame( 'moved before', file_get_contents( $to . '/b.wpress' ) );
			$this->assertNull( $this->services->lock()->holder( 3 ) );
		} finally {
			TempDir::remove( $base );
		}
	}

	public function test_a_finished_export_frees_the_site_through_the_runner_tick(): void {
		$jobs = $this->services->jobs();
		$job  = $jobs->create( 'export', [ 'clean' ], [], 1 );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$done = $this->services->runner( 'export', new MemorySecretStore() )->tick( $job->id, new NoDeadline(), 2 );

		$this->assertSame( Job::DONE, $done->status, $done->error );
		$this->assertSame( Job::DONE, $jobs->load( $job->id )->status );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_the_end_of_a_scheduled_job_is_logged_and_a_manual_run_sends_no_mail(): void {
		FakeOptions::$values[ Options::SCHEDULES ] = [
			[
				'id'    => '0a1b2c3d',
				'title' => 'Nightly',
			],
		];
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'wp_mail' )->never();
		$jobs                   = $this->services->jobs();
		$job                    = $jobs->create( 'export', [ 'clean' ], [], 1 );
		$job->state['schedule'] = [
			'id'        => '0a1b2c3d',
			'title'     => 'Nightly',
			'trigger'   => 'manual',
			'retention' => [],
			'notify'    => [
				'when'  => 'always',
				'email' => '',
			],
		];
		$job->state['backup']   = [
			'name' => 'old-test-20260101-000000-001-0a1b2c3d.wpress',
			'size' => 5,
		];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$done = $this->services->runner( 'export', new MemorySecretStore() )->tick( $job->id, new NoDeadline(), 2 );

		$this->assertSame( Job::DONE, $done->status, $done->error );
		$entry = (array) Log::last( '0a1b2c3d' );
		$this->assertSame( [ $job->id, 'manual', 'done', 'old-test-20260101-000000-001-0a1b2c3d.wpress', '' ], [ $entry['job'], $entry['trigger'], $entry['status'], $entry['backup'], $entry['mail'] ] );
		$this->assertNull( $this->services->lock()->holder( 3 ), 'The lock is released after the record.' );
	}

	/**
	 * Runs a cron run of schedule 0a1b2c3d (mail always) to its end through the Runner.
	 */
	private function finishScheduledRun(): Job {
		FakeOptions::$values[ Options::SCHEDULES ] = [
			[
				'id'    => '0a1b2c3d',
				'title' => 'Nightly',
			],
		];
		FakeOptions::$values['admin_email']        = 'admin@site.test';
		foreach ( [ 'sanitize_text_field', 'wp_specialchars_decode' ] as $function ) {
			Functions\when( $function )->returnArg();
		}
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/admin.php?page=lw-migration#schedules' );
		Functions\when( 'wp_date' )->justReturn( '2026-01-01 00:00' );
		Functions\when( 'size_format' )->justReturn( '5 B' );
		$jobs                   = $this->services->jobs();
		$job                    = $jobs->create( 'export', [ 'clean' ], [], 1 );
		$job->state['schedule'] = [
			'id'        => '0a1b2c3d',
			'title'     => 'Nightly',
			'trigger'   => 'cron',
			'retention' => [],
			'notify'    => [
				'when'  => 'always',
				'email' => '',
			],
		];
		$job->state['backup']   = [
			'name' => 'old-test-20260101-000000-001-0a1b2c3d.wpress',
			'size' => 5,
		];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		return $this->services->runner( 'export', new MemorySecretStore() )->tick( $job->id, new NoDeadline(), 2 );
	}

	public function test_a_mailer_that_throws_leaves_the_job_done_frees_the_site_and_is_noted(): void {
		$lock = $this->services->lock();
		$held = [];
		Functions\when( 'wp_mail' )->alias(
			static function () use ( $lock, &$held ): bool {
				$held[] = $lock->holder( 2 );
				throw new \RuntimeException( 'SMTP down at ' . WP_CONTENT_DIR . '/x' );
			}
		);

		$done = $this->finishScheduledRun();

		$this->assertSame( Job::DONE, $done->status, $done->error );
		$this->assertSame( Job::DONE, $this->services->jobs()->load( $done->id )->status );
		$this->assertNotNull( $held[0] ?? null, 'The mail goes out while the site is still held.' );
		$this->assertSame( 'failed', ( (array) Log::last( '0a1b2c3d' ) )['mail'] );
		$this->assertNull( $lock->holder( 3 ) );
		$log = (string) file_get_contents( $this->services->jobs()->dir( $done->id ) . '/job.log' );
		$this->assertStringContainsString( 'SMTP down at', $log );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $log );
	}

	public function test_a_recorder_that_throws_leaves_the_job_done_frees_the_site_and_is_noted(): void {
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ): bool {
				if ( str_starts_with( (string) $name, Options::SCHEDULE_LOG_PREFIX ) ) {
					throw new \RuntimeException( 'Cannot write the run log.' );
				}
				FakeOptions::$values[ $name ] = $value;
				return true;
			}
		);

		$done = $this->finishScheduledRun();

		$this->assertSame( Job::DONE, $done->status, $done->error );
		$this->assertSame( Job::DONE, $this->services->jobs()->load( $done->id )->status );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
		$this->assertStringContainsString( 'Cannot write the run log.', (string) file_get_contents( $this->services->jobs()->dir( $done->id ) . '/job.log' ) );
	}

	public function test_a_runner_runs_the_steps_its_decorator_hands_back(): void {
		$job  = $this->services->jobs()->create( 'export', [ 'clean' ], [], 1 );
		$seen = [];
		$stop = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				throw new ExportException( 'Stopped by the decorated step.' );
			}
		};

		$after = $this->services->runner(
			'export',
			new MemorySecretStore(),
			static function ( array $steps ) use ( &$seen, $stop ): array {
				$seen = array_keys( $steps );

				return array_merge( $steps, [ 'clean' => $stop ] );
			}
		)->tick( $job->id, new NoDeadline(), 2 );

		$this->assertSame( array_merge( ExportPipeline::stepsFor( true, false ), CloudPipeline::EXPORT_STEPS, [ CloudDownloadStep::KEY ] ), $seen );
		$this->assertSame( [ Job::FAILED, 'Stopped by the decorated step.' ], [ $after->status, $after->error ] );
	}

	public function test_finishing_a_job_never_releases_another_jobs_lock(): void {
		$this->assertTrue( $this->services->lock()->acquire( 'aaaaaaaaaaaaaaaa', 1 ) );
		$other = new Job( 'bbbbbbbbbbbbbbbb', 'export', [], 0, [], [], [], Job::DONE );
		$this->services->finished( $other );
		$this->assertSame( 'aaaaaaaaaaaaaaaa', $this->services->lock()->holder( 2 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function endedJobs(): array {
		return [
			'a finished restore'  => [ 'import', Job::DONE, true ],
			'a finished reset'    => [ ResetJobs::TYPE, Job::DONE, true ],
			'a cancelled reset'   => [ ResetJobs::TYPE, Job::CANCELLED, true ],
			'a finished backup'   => [ 'export', Job::DONE, false ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'endedJobs' )]
	public function test_a_restore_or_reset_that_ends_forgets_the_size_estimate( string $type, string $status, bool $forgets ): void {
		$GLOBALS['wpdb']->rows[ Options::EXPORT_ESTIMATE ] = [
			'value'    => '{"result":{"measuredAt":1}}',
			'autoload' => 'off',
		];

		$this->services->finished( new Job( 'bbbbbbbbbbbbbbbb', $type, [], 0, [], [], [], $status ) );

		$this->assertSame( $forgets, null === $GLOBALS['wpdb']->value( Options::EXPORT_ESTIMATE ), 'The site changed under the estimate: it is measured again.' );
	}

	public function test_failures_never_show_the_plugin_folder_in_the_job_or_its_log(): void {
		$plugin = dirname( LW_MIGRATION_FILE );
		$jobs   = $this->services->jobs();
		$runner = $this->services->runner( 'export', new MemorySecretStore() );
		$thrown = $jobs->create( 'export', [ 'no-such-step' ], [], 1 );
		$failed = $jobs->create( 'export', ExportPipeline::STEPS, [], 1 );

		$runner->tick( $thrown->id, new NoDeadline(), 2 );
		$runner->fail( $failed->id, 'Cannot read ' . $plugin . '/includes/Plugin.php', 2 );

		foreach ( [ $thrown->id, $failed->id ] as $id ) {
			$this->assertSame( Job::FAILED, $jobs->load( $id )->status );
			$json = (string) file_get_contents( $jobs->dir( $id ) . '/job.json' );
			$log  = (string) file_get_contents( $jobs->dir( $id ) . '/job.log' );
			$this->assertStringNotContainsString( $plugin, $json );
			$this->assertStringNotContainsString( str_replace( '/', '\\/', $plugin ), $json );
			$this->assertStringNotContainsString( $plugin, $log );
		}
		$this->assertStringContainsString( ' at includes/Job/Runner.php:', (string) file_get_contents( $jobs->dir( $thrown->id ) . '/job.log' ) );
		$this->assertSame( 'Cannot read includes/Plugin.php', $jobs->load( $failed->id )->error );
	}

	public function test_import_steps_are_wired_for_every_source(): void {
		$steps = $this->services->steps( 'import' );
		$keys  = [];
		foreach ( [ 'url', 'file', 'upload', 'link', 'cloud' ] as $source ) {
			$keys = array_merge( $keys, ImportPipeline::stepsFor( $source ), ImportPipeline::stepsFor( $source, true ) );
		}
		$keys = array_merge( $keys, CloudPipeline::EXPORT_STEPS ); // Registered for every job type that may run them (CloudPipeline).
		$keys = array_values( array_unique( $keys ) );
		sort( $keys );
		$wired = array_keys( $steps );
		sort( $wired );

		$this->assertSame( $keys, $wired );
		$this->assertInstanceOf( SwapStep::class, $steps[ SwapStep::KEY ] );
		$this->assertInstanceOf( RemoteJobStep::class, $steps[ RemoteJobStep::PULL_KEY ] );
		$this->assertInstanceOf( PullDownloadStep::class, $steps[ PullDownloadStep::KEY ] );
	}

	/** An import job that stopped after the swap, holding the site lock. */
	private function swappedImport(): Job {
		$jobs                      = $this->services->jobs();
		$job                       = $jobs->create( 'import', ImportPipeline::stepsFor( 'file' ), [ 'source' => 'file', 'location' => '/a.wpress' ], 1 );
		$job->stepIndex            = (int) array_search( 'post_import', $job->steps, true );
		$job->state['site']        = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$job->state['swapped']     = true;
		$job->state['safetyBackup'] = [ 'name' => 'pre-import-20260101-000000-001.wpress' ];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		return $job;
	}

	/**
	 * A sites import on a network that created sites 7 and 8 and reuses site 5, stopped before the swap.
	 *
	 * @param array<int, int> $deleted Receives the ids WordPress was asked to delete.
	 */
	private function importThatCreatedSites( array &$deleted ): Job {
		$job                        = $this->swappedImport();
		$job->state['swapped']      = false;
		$job->stepIndex             = (int) array_search( 'content', $job->steps, true );
		$job->state['target']       = [ 'domain' => 'new.test' ];
		$job->state['kind']         = 'sites';
		$job->state['blogs']        = [
			[
				'newId'   => 5,
				'url'     => 'https://new.test/b2',
				'created' => false,
			],
			[
				'newId'   => 7,
				'url'     => 'https://new.test/b3',
				'created' => true,
			],
			[
				'newId'   => 8,
				'url'     => 'https://new.test/b4',
				'created' => true,
			],
		];
		$job->state['createdSites'] = [ 7, 8 ];
		$this->services->jobs()->save( $job );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_delete_site' )->alias(
			static function ( int $id ) use ( &$deleted ) {
				$deleted[] = $id;
				return (object) [ 'id' => $id ];
			}
		);

		return $job;
	}

	public function test_a_failed_import_deletes_the_sites_it_created_and_only_those(): void {
		$deleted = [];
		$job     = $this->importThatCreatedSites( $deleted );

		$this->services->runner( 'import', new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$saved = $this->services->jobs()->load( $job->id );
		$this->assertSame( [ 7, 8 ], $deleted, 'The reused site 5 is never deleted.' );
		$this->assertContains( 'The new sites this restore had created were removed again.', $saved->warnings );
		$this->assertSame( [], $saved->state['createdSites'] );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_a_failed_import_after_the_swap_keeps_its_sites(): void {
		$deleted               = [];
		$job                   = $this->importThatCreatedSites( $deleted );
		$job->state['swapped'] = true;
		$this->services->jobs()->save( $job );

		$this->services->runner( 'import', new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$this->assertSame( [], $deleted );
		$this->assertSame( [ 7, 8 ], $this->services->jobs()->load( $job->id )->state['createdSites'] );
	}

	public function test_a_failed_import_whose_swap_marker_exists_keeps_its_sites(): void {
		$deleted = [];
		$job     = $this->importThatCreatedSites( $deleted );
		file_put_contents( $this->services->jobs()->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->services->runner( 'import', new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$this->assertSame( [], $deleted, 'The swap may have run: the sites may hold the restored data (ruling R2).' );
	}

	public function test_a_failed_import_saves_the_cleanup_notice_and_frees_the_site(): void {
		$job  = $this->swappedImport();
		$jobs = $this->services->jobs();

		$this->services->runner( 'import', new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$saved = $jobs->load( $job->id );
		$this->assertSame( Job::FAILED, $saved->status );
		$this->assertStringContainsString( 'pre-import-20260101-000000-001.wpress', implode( ' ', $saved->warnings ), 'Warnings added by the finish hook reach job.json.' );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_the_site_is_freed_even_when_the_import_cleanup_fails(): void {
		$job                     = $this->swappedImport();
		$jobs                    = $this->services->jobs();
		$job->state['swapped']   = false;
		$job->state['tmpPrefix'] = 'lwtab12_';
		$jobs->save( $job );
		file_put_contents( $jobs->dir( $job->id ) . '/' . ImportFiles::DATABASE, 'x' );

		$this->services->runner( 'import', new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertSame( Job::CANCELLED, $jobs->load( $job->id )->status );
		$this->assertNull( $this->services->lock()->holder( 3 ), 'No database connection to drop the tables, but the lock is released.' );
		$this->assertFileDoesNotExist( $jobs->dir( $job->id ) . '/' . ImportFiles::DATABASE, 'The work files still go.' );
		$this->assertStringContainsString( 'Cleanup after the job failed', (string) file_get_contents( $jobs->dir( $job->id ) . '/job.log' ) );
	}

	public function test_a_cancel_after_the_swap_is_deferred_and_keeps_the_site_lock(): void {
		$job  = $this->swappedImport();
		$jobs = $this->services->jobs();

		$cancelled = $this->services->runner( 'import', new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertFalse( $cancelled->isFinished() );
		$this->assertTrue( Runner::cancelDeferred( $jobs->load( $job->id ) ) );
		$this->assertSame( $job->id, $this->services->lock()->holder( 3 ), 'The remaining steps still run under the lock.' );
	}

	public function test_a_cancel_after_the_swap_marker_was_written_is_deferred(): void {
		$job                   = $this->swappedImport();
		$jobs                  = $this->services->jobs();
		$job->state['swapped'] = false;
		$job->stepIndex        = (int) array_search( SwapStep::KEY, $job->steps, true );
		$jobs->save( $job );
		file_put_contents( $jobs->dir( $job->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->assertFalse( $this->services->runner( 'import', new MemorySecretStore() )->cancel( $job->id, 2 )->isFinished() );
		$this->assertSame( $job->id, $this->services->lock()->holder( 3 ) );
	}

	public function test_a_failed_url_import_forgets_the_address_tokens(): void {
		$jobs                      = $this->services->jobs();
		$job                       = $jobs->create( 'import', ImportPipeline::stepsFor( 'url' ), [ 'source' => 'url', 'location' => 'https://files.test/a.wpress?token=tok3n' ], 1 );
		$job->state['site']        = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$job->state['download']    = [ 'url' => 'https://cdn.files.test/a.wpress?sig=s1gn' ];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$this->services->runner( 'import', new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$saved = (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' );
		$this->assertStringNotContainsString( 'tok3n', $saved );
		$this->assertStringNotContainsString( 's1gn', $saved );
		$this->assertSame( 'https://files.test/a.wpress', $jobs->load( $job->id )->options['location'], 'The display form stays for the job view.' );
	}

	public function test_a_url_import_that_still_runs_keeps_its_address(): void {
		$jobs                   = $this->services->jobs();
		$job                    = $jobs->create( 'import', ImportPipeline::stepsFor( 'url' ), [ 'source' => 'url', 'location' => 'https://files.test/a.wpress?token=tok3n' ], 1 );
		$job->stepIndex         = (int) array_search( 'post_import', $job->steps, true );
		$job->state['site']     = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$job->state['swapped']  = true;
		$job->state['download'] = [ 'url' => 'https://cdn.files.test/a.wpress?sig=s1gn' ];
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		$this->assertFalse( $this->services->runner( 'import', new MemorySecretStore() )->cancel( $job->id, 2 )->isFinished() );

		$saved = $jobs->load( $job->id );
		$this->assertSame( 'https://files.test/a.wpress?token=tok3n', $saved->options['location'], 'A deferred cancel leaves a running job whole.' );
		$this->assertSame( 'https://cdn.files.test/a.wpress?sig=s1gn', $saved->state['download']['url'] );
	}

	public function test_push_steps_are_wired(): void {
		$this->assertSame( PushJobs::steps(), array_keys( $this->services->steps( PushJobs::TYPE ) ) );
	}

	/**
	 * A push waiting on the other site's restore, holding the site lock.
	 *
	 * @param array<string, mixed> $remote state.remote.
	 */
	private function push( array $remote ): Job {
		$jobs               = $this->services->jobs();
		$job                = $jobs->create( PushJobs::TYPE, PushJobs::steps(), [], 1 );
		$job->stepIndex     = (int) array_search( RemoteJobStep::PUSH_KEY, $job->steps, true );
		$job->status        = Job::RUNNING;
		$job->state['site'] = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$job->state['link'] = [
			'id'      => ScriptedPeer::OUT,
			'name'    => 'Site B',
			'address' => ScriptedPeer::ADDRESS,
		];
		$job->state['remote'] = $remote;
		$jobs->save( $job );
		file_put_contents( $jobs->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, 'archive' );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		return $job;
	}

	/**
	 * The outgoing link of the pushes and a transport that answers the release.
	 *
	 * @param mixed $answer The other site's answer (an array, or a FakeWpError).
	 * @return \ArrayObject<int, array{0: string, 1: array<string, mixed>}> The requests sent.
	 */
	private function otherSite( $answer ): \ArrayObject {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'get_temp_dir' )->justReturn( WP_CONTENT_DIR . '/' );
		ScriptedPeer::stubHttp();
		$sent = new \ArrayObject();
		$key  = random_bytes( 32 );
		SafeWeb::install(
			static function ( string $url, array $args ) use ( $sent, $answer, $key ) {
				$sent[] = [ $url, $args ];
				if ( $answer instanceof FakeWpError ) {
					return $answer;
				}
				$body = (string) json_encode( $answer );
				file_put_contents( (string) $args['filename'], $body );

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'x-lwm-response-signature' => Signer::answer( ScriptedPeer::OUT, $key, (string) $args['headers'][ Signer::HEADER_NONCE ], 200, $body ) ],
					'body'     => '',
				];
			}
		);
		( new Links() )->add(
			[
				'id'         => ScriptedPeer::OUT,
				'direction'  => Links::OUT,
				'address'    => ScriptedPeer::ADDRESS,
				'name'       => 'Site B',
				'allow'      => [ 'push' ],
				'createdBy'  => [ 'id' => 1, 'login' => 'admin' ],
				'pairedFrom' => '',
			],
			$key,
			time(),
			true
		);

		return $sent;
	}

	public function test_the_point_of_no_return_of_a_push_follows_the_link_pipeline(): void {
		$runner = $this->services->runner( PushJobs::TYPE, new MemorySecretStore() );
		$job    = new Job( '0123456789abcdef', PushJobs::TYPE, PushJobs::steps(), 8, [], [], [ 'remote' => [ 'job' => 'bbbbbbbbbbbbbbbb' ] ], Job::RUNNING );

		$this->assertFalse( $runner->defersCancel( $job ) );
		$job->state['remote']['confirmed'] = true;
		$this->assertSame( LinkPipeline::pastNoReturn( $job ), $runner->defersCancel( $job ) );
		$this->assertTrue( $runner->defersCancel( $job ) );
	}

	public function test_a_cancel_after_the_forwarded_confirmation_is_deferred(): void {
		$job = $this->push(
			[
				'job'       => 'bbbbbbbbbbbbbbbb',
				'confirmed' => true,
				'status'    => Job::RUNNING,
			]
		);

		$cancelled = $this->services->runner( PushJobs::TYPE, new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertFalse( $cancelled->isFinished() );
		$this->assertTrue( Runner::cancelDeferred( $this->services->jobs()->load( $job->id ) ) );
		$this->assertSame( $job->id, $this->services->lock()->holder( 3 ), 'The push drives the other site to its end.' );
		$this->assertFileExists( $this->services->jobs()->dir( $job->id ) . '/' . ExportFiles::ARCHIVE );
	}

	public function test_a_failed_push_releases_the_other_sites_job_and_loses_its_archive(): void {
		$sent = $this->otherSite(
			[
				'released'       => true,
				'cancelDeferred' => true,
			]
		);
		$job  = $this->push(
			[
				'job'    => 'bbbbbbbbbbbbbbbb',
				'status' => Job::RUNNING,
			]
		);

		$failed = $this->services->runner( PushJobs::TYPE, new MemorySecretStore() )->fail( $job->id, 'Stopped.', 2 );

		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertCount( 1, $sent );
		[ $url, $args ] = $sent[0];
		$this->assertStringEndsWith( '?rest_route=/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb', $url );
		$this->assertSame( [ 'DELETE', 10 ], [ $args['method'], $args['timeout'] ] );
		$this->assertArrayHasKey( 'X-LWM-Signature', $args['headers'] );
		$this->assertFileDoesNotExist( $this->services->jobs()->dir( $job->id ) . '/' . ExportFiles::ARCHIVE );
		$this->assertContains( 'The other site had already started replacing its content; open its LW Migration screen to finish it.', $this->services->jobs()->load( $job->id )->warnings, 'Saved by the finish hook.' );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_a_release_that_fails_still_ends_the_push_and_a_done_push_releases_nothing(): void {
		$sent = $this->otherSite( new FakeWpError( 'cURL error 7: refused' ) );
		$job  = $this->push( [ 'job' => 'bbbbbbbbbbbbbbbb' ] );

		$cancelled = $this->services->runner( PushJobs::TYPE, new MemorySecretStore() )->cancel( $job->id, 2 );

		$this->assertSame( Job::CANCELLED, $cancelled->status );
		$this->assertCount( 1, $sent );
		$this->assertSame( [], $this->services->jobs()->load( $job->id )->warnings );
		$this->assertNull( $this->services->lock()->holder( 3 ) );

		$done         = $this->push( [ 'job' => 'cccccccccccccccc' ] );
		$done->status = Job::DONE;
		$this->services->finished( $done );
		$this->assertCount( 1, $sent, 'A finished push leaves the other site alone.' );
		$this->assertFileExists( $this->services->jobs()->dir( $done->id ) . '/' . ExportFiles::ARCHIVE );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	/**
	 * A pull (an import over the link) after the other site's export, holding the site lock.
	 *
	 * @param array<string, mixed> $remote state.remote.
	 */
	private function pull( array $remote ): Job {
		$jobs                 = $this->services->jobs();
		$job                  = $jobs->create(
			'import',
			ImportPipeline::stepsFor( 'link' ),
			[
				'source'       => 'link',
				'location'     => ScriptedPeer::OUT,
				'safetyBackup' => true,
			],
			1
		);
		$job->stepIndex       = (int) array_search( PullDownloadStep::KEY, $job->steps, true );
		$job->status          = Job::RUNNING;
		$job->state['site']   = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$job->state['link']   = [
			'id'      => ScriptedPeer::OUT,
			'name'    => 'Site B',
			'address' => ScriptedPeer::ADDRESS,
		];
		$job->state['remote'] = $remote;
		$jobs->save( $job );
		$this->assertTrue( $this->services->lock()->acquire( $job->id, 1 ) );

		return $job;
	}

	public function test_a_failed_or_cancelled_pull_releases_the_other_sites_export(): void {
		$sent   = $this->otherSite( [ 'released' => true ] );
		$failed = $this->pull( [ 'job' => 'bbbbbbbbbbbbbbbb' ] );

		$this->assertSame( Job::FAILED, $this->services->runner( 'import', new MemorySecretStore() )->fail( $failed->id, 'Stopped.', 2 )->status );
		$this->assertCount( 1, $sent );
		[ $url, $args ] = $sent[0];
		$this->assertStringEndsWith( '?rest_route=/lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb', $url );
		$this->assertSame( [ 'DELETE', 10 ], [ $args['method'], $args['timeout'] ] );
		$this->assertArrayHasKey( 'X-LWM-Signature', $args['headers'] );
		$this->assertNull( $this->services->lock()->holder( 3 ) );

		$cancelled = $this->pull( [ 'job' => 'cccccccccccccccc' ] );
		$this->assertSame( Job::CANCELLED, $this->services->runner( 'import', new MemorySecretStore() )->cancel( $cancelled->id, 2 )->status );
		$this->assertCount( 2, $sent );
		$this->assertStringEndsWith( '/jobs/cccccccccccccccc', $sent[1][0] );
		$this->assertNull( $this->services->lock()->holder( 3 ) );
	}

	public function test_a_done_or_already_released_pull_leaves_the_other_site_alone(): void {
		$sent = $this->otherSite( new FakeWpError( 'cURL error 7: refused' ) );

		$done         = $this->pull( [ 'job' => 'bbbbbbbbbbbbbbbb' ] );
		$done->status = Job::DONE;
		$this->services->finished( $done );
		$this->assertCount( 0, $sent, 'A finished pull released the other site in its download step.' );
		$this->assertNull( $this->services->lock()->holder( 3 ) );

		$released = $this->pull(
			[
				'job'      => 'cccccccccccccccc',
				'released' => true,
			]
		);
		$this->services->runner( 'import', new MemorySecretStore() )->fail( $released->id, 'Restore failed.', 2 );
		$this->assertCount( 0, $sent, 'Its archive was already released.' );

		$failing = $this->pull( [ 'job' => 'dddddddddddddddd' ] );
		$this->assertSame( Job::FAILED, $this->services->runner( 'import', new MemorySecretStore() )->fail( $failing->id, 'Stopped.', 2 )->status );
		$this->assertCount( 1, $sent, 'A release that fails is tried once.' );
		$this->assertNull( $this->services->lock()->holder( 3 ), 'and still frees the lock.' );
	}
}
