<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Import\Steps\UploadStep;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\JobsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\WebDriver;
use LightweightPlugins\Migration\Rest\WebLimits;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class JobsApiTest extends MonkeyTestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/wpress/plain.wpress';

	private const BACKUP = 'site-old-20261001-101500-001.wpress';

	private string $dir;

	private JobStore $jobs;

	private SiteLock $lock;

	private MemorySecretStore $secrets;

	private JobsApi $api;

	private int $now = 100;

	/** Why the backups folder cannot be used now (null = it can). */
	private ?string $folderProblem = null;

	/** Runs once when the runner factory is next called (another request acting in between). */
	private ?\Closure $meanwhile = null;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		Functions\when( 'size_format' )->alias( static fn( $bytes ) => $bytes . ' B' );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/own' );
		mkdir( $this->dir . '/ext' );
		copy( self::FIXTURE, $this->dir . '/own/' . self::BACKUP );
		$this->jobs    = new JobStore( $this->dir . '/jobs' );
		$this->lock    = new SiteLock();
		$this->secrets = new MemorySecretStore();
		$this->api     = new JobsApi(
			$this->jobs,
			$this->lock,
			new Repository( $this->dir . '/own', $this->dir . '/ext' ),
			$this->secrets,
			new WebDriver( $this->jobs, $this->lock, fn( string $type ): Runner => $this->runnerFor(), fn(): int => $this->now, 30.0 ),
			fn(): array => [
				'site'       => SiteFixture::make( $this->dir . '/site' ),
				'admin'      => [
					'id'    => 1,
					'login' => 'admin',
				],
				'permalinks' => true,
				'free'       => null,
			],
			fn(): int => $this->now,
			[ $this->dir ],
			function (): void {
				if ( null !== $this->folderProblem ) {
					throw new FolderRefusedException( $this->folderProblem );
				}
			}
		);
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function runnerFor(): Runner {
		$runner = $this->runner();
		if ( null !== $this->meanwhile ) {
			$meanwhile       = $this->meanwhile;
			$this->meanwhile = null;
			$meanwhile( $runner );
		}

		return $runner;
	}

	/** Every step done at once, except a real upload step and a confirmation. */
	private function runner(): Runner {
		$done    = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return StepResult::done();
			}
		};
		$confirm = new class() implements StepInterface {
			public function run( Job $job, Deadline $deadline ): StepResult {
				return true === ( $job->state['input']['confirm'] ?? null ) ? StepResult::done() : StepResult::needsInput( 'confirm', [ 'from' => 'https://old.test' ] );
			}
		};
		$steps                    = array_fill_keys( array_merge( ExportPipeline::STEPS, ImportPipeline::COMMON, [ 'source', 'url_resolve', 'url_download' ] ), $done );
		$steps['confirm']         = $confirm;
		$steps[ UploadStep::KEY ] = new UploadStep( $this->jobs );
		$lock                     = $this->lock;

		return new Runner(
			$this->jobs,
			$steps,
			$this->secrets,
			[],
			static function ( Job $job ) use ( $lock ): void {
				$lock->release( $job->id );
			},
			static fn( Job $job ): bool => ! empty( $job->state['swapped'] )
		);
	}

	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	private function backupImport(): Job {
		return $this->api->create(
			[
				'type'   => 'import',
				'source' => 'backup',
				'name'   => self::BACKUP,
				'folder' => 'own',
			]
		);
	}

	private function uploadImport( int $size ): Job {
		$job = $this->api->create(
			[
				'type'   => 'import',
				'source' => 'upload',
				'name'   => 'site.wpress',
				'size'   => $size,
			]
		);
		$this->api->tick( $job->id );

		return $job;
	}

	private function uploadTarget( Job $job ): string {
		return $this->jobs->dir( $job->id ) . '/' . ImportFiles::UPLOAD;
	}

	public function test_an_export_starts_with_allowlisted_options_and_a_hidden_password(): void {
		$job = $this->api->create(
			[
				'type'     => 'export',
				'options'  => [
					'noMedia' => true,
					'encrypt' => true,
				],
				'password' => 'hunter2-secret',
			]
		);

		$saved = $this->jobs->load( $job->id );
		$this->assertSame( 'export', $saved->type );
		$this->assertTrue( $saved->options['noMedia'] );
		$this->assertSame( 'backup', $saved->options['kind'] );
		$this->assertSame( [ 'password' => 'hunter2-secret' ], $this->secrets->get( $job->id ) );
		$this->assertStringNotContainsString( 'hunter2', (string) file_get_contents( $this->jobs->dir( $job->id ) . '/job.json' ) );
		$this->assertSame( $job->id, $this->lock->holder( 101 ) );
		$this->assertSame( $job->id, $this->api->active()->id );
	}

	public function test_an_encrypted_export_needs_its_password(): void {
		$refused = $this->refusal(
			fn() => $this->api->create(
				[
					'type'    => 'export',
					'options' => [ 'encrypt' => true ],
				]
			)
		);

		$this->assertSame( [ 400, ErrorMap::INVALID, 'An encrypted backup needs a password.' ], [ $refused->status, $refused->errorCode, $refused->getMessage() ] );
		$this->assertSame( [], $this->jobs->ids() );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_request_refusals_share_one_code_and_name_their_field(): void {
		$type = $this->refusal( fn() => $this->api->create( [ 'type' => 'reset' ] ) );
		$this->assertSame( [ 400, ErrorMap::INVALID, [ 'field' => 'type' ] ], [ $type->status, $type->errorCode, $type->data ] );
		$source = $this->refusal( fn() => $this->api->create( [ 'type' => 'import', 'source' => 'file' ] ) );
		$this->assertSame( [ 400, ErrorMap::INVALID, [ 'field' => 'source' ] ], [ $source->status, $source->errorCode, $source->data ] );
	}

	public function test_a_refusal_of_the_job_constructor_keeps_its_translated_text(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( false );

		$export = $this->refusal( fn() => $this->api->create( [ 'type' => 'export' ] ) );
		$import = $this->refusal( fn() => $this->backupImport() );

		$this->assertSame( [ 400, ErrorMap::INVALID, 'Backups of a network run on its main site.' ], [ $export->status, $export->errorCode, $export->getMessage() ] );
		$this->assertSame( [ 400, ErrorMap::INVALID, 'Restores into a network run on its main site.' ], [ $import->status, $import->errorCode, $import->getMessage() ] );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_a_backup_or_a_safety_backup_never_starts_without_a_usable_backups_folder(): void {
		$this->folderProblem = FolderRefusedException::MISSING;
		$text                = FolderMessages::of( FolderRefusedException::MISSING );

		$export = $this->refusal( fn() => $this->api->create( [ 'type' => 'export' ] ) );
		$import = $this->refusal( fn() => $this->backupImport() );

		$this->assertSame( [ 409, 'lw_migration_backup_dir_unusable', $text ], [ $export->status, $export->errorCode, $export->getMessage() ] );
		$this->assertSame( [ 409, 'lw_migration_backup_dir_unusable', $text ], [ $import->status, $import->errorCode, $import->getMessage() ] );
		$this->assertSame( [], $this->jobs->ids() );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_a_restore_without_a_safety_backup_does_not_need_the_backups_folder(): void {
		$this->folderProblem = FolderRefusedException::MISSING;

		$job = $this->api->create(
			[
				'type'         => 'import',
				'source'       => 'backup',
				'name'         => self::BACKUP,
				'folder'       => 'own',
				'safetyBackup' => false,
			]
		);

		$this->assertSame( 'import', $this->jobs->load( $job->id )->type );
	}

	public function test_a_server_file_is_never_an_import_source(): void {
		$this->assertSame( 400, $this->refusal( fn() => $this->api->create( [ 'type' => 'import', 'source' => 'file', 'location' => '/etc/passwd.wpress' ] ) )->status );
		$this->assertSame( 400, $this->refusal( fn() => $this->api->create( [ 'type' => 'reset' ] ) )->status );
		$this->assertSame( [], $this->jobs->ids(), 'Nothing was created.' );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_an_upload_arrives_in_chunks_then_the_import_runs(): void {
		$archive = (string) file_get_contents( self::FIXTURE );
		$size    = strlen( $archive );
		$job     = $this->api->create(
			[
				'type'   => 'import',
				'source' => 'upload',
				'name'   => 'site.wpress',
				'size'   => $size,
			]
		);

		$waiting = $this->api->view( $this->api->tick( $job->id ) );
		$this->assertSame( [ 'kind' => 'upload', 'received' => 0, 'size' => $size, 'chunkBytes' => WebLimits::chunkBytes() ], $waiting['input'] );
		$this->assertSame( [ 'kind' => 'upload', 'name' => 'site.wpress', 'size' => $size ], $waiting['source'] );

		$this->assertSame( 5000, $this->api->view( $this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) ) )['input']['received'] );
		$again = $this->refusal( fn() => $this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) ) );
		$this->assertSame( [ 409, 'lw_migration_upload_offset', [ 'received' => 5000 ] ], [ $again->status, $again->errorCode, $again->data ] );

		$running = $this->api->upload( $job->id, 5000, substr( $archive, 5000 ) );
		$this->assertSame( Job::RUNNING, $running->status );
		$this->assertSame( $archive, file_get_contents( $this->uploadTarget( $job ) ) );

		$confirm = $this->api->tick( $job->id );
		$this->assertSame( 'confirm', $this->api->view( $confirm )['input']['kind'] );
		$this->assertSame( Job::RUNNING, $this->api->answer( $job->id, [ 'confirm' => true ] )->status );
		$this->assertSame( Job::DONE, $this->api->tick( $job->id )->status );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_a_resent_last_chunk_starts_the_import_when_the_upload_is_already_complete(): void {
		$archive = (string) file_get_contents( self::FIXTURE );
		$job     = $this->uploadImport( strlen( $archive ) );
		// An earlier request landed every byte, then found the job busy and could not hand it over.
		UploadReceiver::append( $this->uploadTarget( $job ), strlen( $archive ), 0, $archive );

		$running = $this->api->upload( $job->id, 5000, substr( $archive, 5000 ) );

		$this->assertSame( Job::RUNNING, $running->status );
		$this->assertSame( Job::RUNNING, $this->jobs->load( $job->id )->status );
		$this->assertSame( 'confirm', $this->api->view( $this->api->tick( $job->id ) )['input']['kind'] );
	}

	public function test_a_last_chunk_resent_after_the_handover_answers_with_the_job(): void {
		$archive = (string) file_get_contents( self::FIXTURE );
		$job     = $this->uploadImport( strlen( $archive ) );
		$this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) );
		$this->api->upload( $job->id, 5000, substr( $archive, 5000 ) );
		$saved = (string) file_get_contents( $this->jobs->dir( $job->id ) . '/job.json' );

		$again = $this->api->upload( $job->id, 5000, substr( $archive, 5000 ) );

		$this->assertSame( Job::RUNNING, $again->status );
		$this->assertSame( $saved, file_get_contents( $this->jobs->dir( $job->id ) . '/job.json' ), 'Nothing was written.' );
		$this->assertSame( 409, $this->refusal( fn() => $this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) ) )->status, 'Only the last chunk.' );
	}

	public function test_a_full_upload_that_cannot_be_renamed_is_handed_over_and_fails_truthfully(): void {
		$archive = (string) file_get_contents( self::FIXTURE );
		$job     = $this->uploadImport( strlen( $archive ) );
		$this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) );
		mkdir( $this->uploadTarget( $job ) ); // Something occupies the upload's name: the rename fails.

		$handed = $this->api->upload( $job->id, 5000, substr( $archive, 5000 ) );
		$this->assertSame( Job::RUNNING, $handed->status );

		$failed = $this->api->tick( $job->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'could not be saved', $failed->error );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_not_an_archive_fails_the_job_and_frees_the_site(): void {
		$job = $this->uploadImport( 20000 );

		$refused = $this->refusal( fn() => $this->api->upload( $job->id, 0, str_repeat( 'x', 5000 ) ) );
		$this->assertSame( [ 400, 'lw_migration_not_wpress' ], [ $refused->status, $refused->errorCode ] );
		$this->assertSame( Job::FAILED, $this->jobs->load( $job->id )->status );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_chunks_only_while_an_upload_is_awaited_and_never_too_large(): void {
		$export = $this->api->create( [ 'type' => 'export' ] );
		$this->assertSame( 409, $this->refusal( fn() => $this->api->upload( $export->id, 0, 'x' ) )->status );
		$this->api->cancel( $export->id );

		$upload = $this->uploadImport( 20000 );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 100000 );
		$limit = WebLimits::chunkBytes();
		$this->assertSame( 100000 - 65536, $limit );
		$tooLarge = $this->refusal( fn() => $this->api->upload( $upload->id, 0, str_repeat( 'x', $limit + 1 ) ) );
		$this->assertSame( [ 413, 'lw_migration_chunk_too_large', [ 'chunkBytes' => $limit ] ], [ $tooLarge->status, $tooLarge->errorCode, $tooLarge->data ] );
		$this->assertSame( 409, $this->refusal( fn() => $this->api->upload( $upload->id, 5, str_repeat( 'x', $limit ) ) )->status, 'A chunk of exactly the announced size is taken (and then checked for its offset).' );
	}

	public function test_a_chunk_for_a_job_cancelled_meanwhile_is_refused_and_never_written(): void {
		$archive = (string) file_get_contents( self::FIXTURE );
		$job     = $this->uploadImport( strlen( $archive ) );
		$this->lock->release( $job->id ); // The site is free again, so this chunk takes the lock to resume.
		$this->meanwhile = static function ( Runner $runner ) use ( $job ): void {
			$runner->cancel( $job->id, 3000 ); // Another request cancels right after this one claimed the site.
		};

		$refused = $this->refusal( fn() => $this->api->upload( $job->id, 0, substr( $archive, 0, 5000 ) ) );

		$this->assertSame( [ 409, 'lw_migration_not_uploading' ], [ $refused->status, $refused->errorCode ] );
		$this->assertSame( 0, UploadReceiver::received( $this->uploadTarget( $job ) ) );
		$this->assertFileDoesNotExist( $this->uploadTarget( $job ) . UploadReceiver::PART_SUFFIX );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_a_declined_confirmation_cancels_and_answers_are_checked(): void {
		$job = $this->backupImport();
		$this->assertSame( 409, $this->refusal( fn() => $this->api->answer( $job->id, [ 'confirm' => true ] ) )->status, 'Not waiting yet.' );
		$this->api->tick( $job->id );

		$this->assertSame( 409, $this->refusal( fn() => $this->api->answer( $job->id, [ 'password' => 'x' ] ) )->status );
		$this->assertSame( Job::CANCELLED, $this->api->answer( $job->id, [ 'confirm' => false ] )->status );
		$this->assertNull( $this->lock->holder( 101 ) );
	}

	public function test_a_cancel_after_the_point_of_no_return_is_reported_deferred(): void {
		$job                   = $this->backupImport();
		$job->state['swapped'] = true;
		$job->stepIndex        = (int) array_search( 'post_import', $job->steps, true );
		$this->jobs->save( $job );

		$result = $this->api->cancel( $job->id );
		$this->assertTrue( $result['cancelDeferred'] );
		$this->assertFalse( $result['job']->isFinished() );
		$this->assertTrue( $this->api->view( $result['job'] )['pastNoReturn'] );
		$this->assertSame( Job::DONE, $this->api->tick( $job->id )->status, 'Ticks finish it.' );

		$other  = $this->backupImport();
		$normal = $this->api->cancel( $other->id );
		$this->assertFalse( $normal['cancelDeferred'] );
		$this->assertSame( Job::CANCELLED, $normal['job']->status );
	}

	public function test_cancelling_a_finished_job_leaves_it_as_it_is(): void {
		$job = $this->backupImport();
		$this->api->cancel( $job->id );
		$file  = $this->jobs->dir( $job->id ) . '/job.json';
		$saved = (string) file_get_contents( $file );
		$this->now = 500;

		$result = $this->api->cancel( $job->id );

		$this->assertSame( [ Job::CANCELLED, false ], [ $result['job']->status, $result['cancelDeferred'] ] );
		$this->assertSame( $saved, file_get_contents( $file ) );
	}

	public function test_an_export_takes_no_other_request_fields(): void {
		$refused = $this->refusal( fn() => $this->api->create( [ 'type' => 'export', 'source' => 'backup' ] ) );

		$this->assertSame( [ 400, ErrorMap::INVALID ], [ $refused->status, $refused->errorCode ] );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_an_export_without_destinations_has_no_cloud_plan(): void {
		$none = $this->api->create( [ 'type' => 'export', 'options' => [] ] );

		$this->assertNull( CloudPlan::of( $this->jobs->load( $none->id ) ) );
		$this->assertNotContains( 'cloud_upload', $this->jobs->load( $none->id )->steps );
	}

	public function test_an_empty_destinations_list_is_no_cloud_plan_either(): void {
		$empty = $this->api->create( [ 'type' => 'export', 'destinations' => [] ] );

		$this->assertNull( CloudPlan::of( $this->jobs->load( $empty->id ) ) );
	}

	public function test_the_chosen_destinations_reach_the_export_as_a_manual_plan(): void {
		$this->store( 'aaaa1111', 'bbbb2222' );

		$job  = $this->api->create( [ 'type' => 'export', 'destinations' => [ 'aaaa1111', 'bbbb2222' ], 'keepLocal' => false ] );
		$plan = CloudPlan::of( $this->jobs->load( $job->id ) );

		$this->assertSame( [ 'aaaa1111', 'bbbb2222' ], $plan->ids() );
		$this->assertFalse( $plan->keepLocal );
		$this->assertTrue( $plan->prune );
		$this->assertSame( CloudPipeline::EXPORT_STEPS, array_slice( $this->jobs->load( $job->id )->steps, -count( CloudPipeline::EXPORT_STEPS ) ) );
	}

	public function test_the_local_copy_is_kept_unless_the_request_says_otherwise(): void {
		$this->store( 'aaaa1111' );

		$job = $this->api->create( [ 'type' => 'export', 'destinations' => [ 'aaaa1111' ] ] );

		$this->assertTrue( CloudPlan::of( $this->jobs->load( $job->id ) )->keepLocal );
	}

	/**
	 * @dataProvider refused_cloud_requests
	 * @param array<string, mixed> $extra Request keys besides the type.
	 */
	public function test_a_bad_cloud_choice_is_refused_before_a_job_exists( array $extra, int $status, string $field ): void {
		$this->store( 'aaaa1111' );

		$refused = $this->refusal( fn() => $this->api->create( [ 'type' => 'export' ] + $extra ) );

		$this->assertSame( $status, $refused->status );
		$this->assertSame( [], $this->jobs->ids() );
		if ( 400 === $status ) {
			$this->assertSame( $field, $refused->data['field'] ?? null );
			$this->assertStringNotContainsString( 'bad id', $refused->getMessage() );
		}
	}

	/** @return array<string, array{0: array<string, mixed>, 1: int, 2: string}> */
	public static function refused_cloud_requests(): array {
		return [
			'not a list'                  => [ [ 'destinations' => 'aaaa1111' ], 400, 'destinations' ],
			'a bad id'                    => [ [ 'destinations' => [ 'bad id' ] ], 400, 'destinations' ],
			'a number'                    => [ [ 'destinations' => [ 12345678 ] ], 400, 'destinations' ],
			'a duplicate'                 => [ [ 'destinations' => [ 'aaaa1111', 'aaaa1111' ] ], 400, 'destinations' ],
			'an unknown destination'      => [ [ 'destinations' => [ 'cccc3333' ] ], 404, '' ],
			'keepLocal without a list'    => [ [ 'keepLocal' => false ], 400, 'keepLocal' ],
			'keepLocal with an empty list' => [ [ 'destinations' => [], 'keepLocal' => true ], 400, 'keepLocal' ],
			'a loose keepLocal'           => [ [ 'destinations' => [ 'aaaa1111' ], 'keepLocal' => 'false' ], 400, 'keepLocal' ],
			'an unknown key'              => [ [ 'destinations' => [ 'aaaa1111' ], 'target' => 'x' ], 400, 'type' ],
		];
	}

	public function test_an_import_takes_no_cloud_keys(): void {
		$refused = $this->refusal( fn() => $this->api->create( [ 'type' => 'import', 'source' => 'backup', 'name' => self::BACKUP, 'destinations' => [] ] ) );

		$this->assertSame( [ 400, ErrorMap::INVALID ], [ $refused->status, $refused->errorCode ] );
	}

	private function store( string ...$ids ): void {
		foreach ( $ids as $id ) {
			( new ProfileRepository() )->save( new Profile( $id, 's3', 'Dest ' . $id ) );
		}
	}

	public function test_the_view_never_shows_the_sites_folders(): void {
		$job             = $this->backupImport();
		$job->warnings[] = 'Cannot read ' . $this->dir . '/own/notes.txt';
		$job->message    = 'Reading ' . $this->dir . '/own';

		$view = $this->api->view( $job );

		$this->assertStringNotContainsString( $this->dir, (string) json_encode( $view, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_unknown_jobs_are_not_found(): void {
		$this->assertSame( 404, $this->refusal( fn() => $this->api->get( '0123456789abcdef' ) )->status );
		$this->assertSame( 404, $this->refusal( fn() => $this->api->tick( '../../etc' ) )->status );
		$this->assertSame( 404, $this->refusal( fn() => $this->api->cancel( '0123456789abcdef' ) )->status );
		$this->assertSame( 'lw_migration_no_job', $this->refusal( fn() => $this->api->upload( '0123456789abcdef', 0, 'x' ) )->errorCode );
		$this->assertNull( $this->api->active() );
	}

	public function test_an_export_view_carries_each_destinations_result_and_whether_the_backup_is_local(): void {
		$job                  = $this->jobs->create( 'export', [ 'store' ], [], 1 );
		$job->state['backup'] = [ 'name' => 'a.wpress', 'path' => '/x', 'size' => 3, 'local' => false ];
		$job->state['cloud']  = CloudPlan::manual( [ 'aaaa1111' ], false )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );

		$view = $this->api->view( $job );

		$this->assertSame( [ 'name' => 'a.wpress', 'size' => 3, 'local' => false ], $view['result']['backup'] );
		$this->assertSame( [ [ 'id' => 'aaaa1111', 'name' => 'One', 'type' => 's3', 'status' => 'ok', 'message' => '', 'pruned' => 0 ] ], $view['result']['cloud'] );
	}

	public function test_a_cloud_restore_names_its_backup_and_destination_even_when_the_destination_is_gone(): void {
		( new ProfileRepository() )->save( new Profile( 'aaaa1111', 's3', 'Offsite' ) );
		$job                       = $this->jobs->create( 'import', [ 'cloud_download' ], ( new ImportOptions( ImportOptions::CLOUD, 'a.wpress' ) )->toArray(), 1 );
		$job->state['cloudSource'] = [ 'id' => 'aaaa1111', 'file' => [ 'name' => 'a.wpress', 'size' => 3, 'mtime' => 1, 'ref' => 'k/a.wpress' ] ];

		$this->assertSame( [ 'kind' => 'cloud', 'name' => 'a.wpress', 'destination' => 'Offsite' ], $this->api->view( $job )['source'] );
		( new ProfileRepository() )->delete( 'aaaa1111' );
		$this->assertSame( [ 'kind' => 'cloud', 'name' => 'a.wpress', 'destination' => '' ], $this->api->view( $job )['source'] );
	}
}
