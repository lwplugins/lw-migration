<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Rest\CloudApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class CloudApiTest extends MonkeyTestCase {

	private const HOME = 'https://example.com';

	private string $dir;

	private FakeWpdb $db;

	private JobStore $jobs;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/backups' );
		$this->db = FakeWpdb::install();
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
		$this->jobs = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->db;
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param \Closure|null $backupsReady Backups folder check.
	 * @param string        $external     External backups folder ('' = none).
	 */
	private function api( ?\Closure $backupsReady = null, string $external = '' ): CloudApi {
		$destinations = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$site         = SiteFixture::make( $this->dir, [ 'homeUrl' => self::HOME ] );

		return new CloudApi(
			$destinations,
			$this->jobs,
			new SiteLock(),
			new Repository( $this->dir . '/backups', $external ),
			new MemorySecretStore(),
			static fn(): array => [
				'site'       => $site,
				'admin'      => [ 'id' => 1, 'login' => 'admin' ],
				'permalinks' => true,
				'free'       => null,
			],
			static fn(): int => 100,
			static fn(): string => self::HOME,
			$backupsReady
		);
	}

	private static function code( \Closure $call ): string {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e->status . ' ' . $e->errorCode;
		}
		return 'accepted';
	}

	public function test_lists_only_backups_newest_first_and_marks_this_sites_own(): void {
		$api     = $this->api();
		$own     = BackupName::forSite( self::HOME, 1760238000, 1 );
		$remote  = FakeCloud::$remotes['aaaa1111'];
		$remote->files[ $own ]             = 'x';
		$remote->mtimes[ $own ]            = 20;
		$remote->files['old-site.wpress']  = 'y';
		$remote->mtimes['old-site.wpress'] = 10;
		$remote->files['notes.txt']        = 'z';

		$files = $api->files( 'aaaa1111' );

		$this->assertSame( [ $own, 'old-site.wpress' ], array_column( $files['files'], 'name' ) );
		$this->assertSame( [ true, false ], array_column( $files['files'], 'ours' ) );
		$this->assertFalse( $files['insecure'] );
	}

	public function test_listing_a_destination_forgets_a_copy_no_longer_there(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['kept.wpress'] = 'x';
		CloudCopies::mark( 'kept.wpress', 'aaaa1111', CloudCopies::UP, 1 );
		CloudCopies::mark( 'trashed.wpress', 'aaaa1111', CloudCopies::UP, 1 );

		$api->files( 'aaaa1111' );

		$this->assertSame( [ 'kept.wpress' ], array_keys( CloudCopies::all() ) );
	}

	public function test_listing_records_the_backups_held_here_byte_for_byte(): void {
		$api    = $this->api();
		$remote = FakeCloud::$remotes['aaaa1111'];
		file_put_contents( $this->dir . '/backups/same.wpress', 'abc' );
		file_put_contents( $this->dir . '/backups/other-size.wpress', 'ab' );
		$remote->files['same.wpress']       = 'abc';
		$remote->files['other-size.wpress'] = 'abcd';
		$remote->files['only-there.wpress'] = 'xyz';

		$files = $api->files( 'aaaa1111' );

		$this->assertSame( [ 'same.wpress' => [ 'aaaa1111' => [ 'status' => 'up', 'at' => 100 ] ] ], CloudCopies::all() );
		$this->assertTrue( $files['copiesChanged'] );
		$this->assertFalse( $api->files( 'aaaa1111' )['copiesChanged'], 'A second listing changes nothing.' );
	}

	public function test_listing_says_the_copies_changed_when_it_forgot_one(): void {
		$api = $this->api();
		CloudCopies::mark( 'trashed.wpress', 'aaaa1111', CloudCopies::UP, 1 );

		$this->assertTrue( $api->files( 'aaaa1111' )['copiesChanged'] );
	}

	public function test_a_restore_of_a_backup_no_longer_there_forgets_its_copy(): void {
		$api = $this->api();
		CloudCopies::mark( 'trashed.wpress', 'aaaa1111', CloudCopies::UP, 1 );

		$this->assertSame( '404 lw_migration_remote_missing', self::code( fn() => $api->restore( 'aaaa1111', [ 'name' => 'trashed.wpress' ] ) ) );
		$this->assertSame( [], CloudCopies::all() );
	}

	public function test_deleting_a_remote_backup_forgets_its_copy_record(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'x';
		CloudCopies::mark( 'a.wpress', 'aaaa1111', CloudCopies::UP, 1 );

		$this->assertSame( [ 'deleted' => true ], $api->deleteFile( 'aaaa1111', 'a.wpress' ) );
		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->files );
		$this->assertSame( [], CloudCopies::of( 'a.wpress' ) );
		$this->assertSame( '404 lw_migration_remote_missing', self::code( fn() => $api->deleteFile( 'aaaa1111', 'a.wpress' ) ) );
	}

	public function test_a_restore_is_an_import_that_downloads_first(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';

		$job = $api->restore( 'aaaa1111', [ 'name' => 'a.wpress', 'safetyBackup' => false ] );

		$this->assertSame( [ 'import', CloudDownloadStep::KEY ], [ $job->type, $job->steps[0] ] );
		$this->assertSame( [ ImportOptions::CLOUD, 'a.wpress', false ], [ $job->options['source'], $job->options['location'], $job->options['safetyBackup'] ] );
		$this->assertSame( 'aaaa1111', $this->jobs->load( $job->id )->state['cloudSource']['id'] );
	}

	public function test_a_different_local_backup_of_that_name_refuses_before_any_job(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';
		file_put_contents( $this->dir . '/backups/a.wpress', 'longer' );

		$this->assertSame( '409 lw_migration_local_exists', self::code( fn() => $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress' ] ) ) );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_a_fetch_is_a_cloud_job(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';

		$job = $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress' ] );

		$this->assertSame( [ CloudJobs::TYPE, [ 'action' => CloudJobs::FETCH ] ], [ $job->type, $job->options ] );
	}

	public function test_an_upload_names_existing_destinations_and_an_existing_backup(): void {
		$api = $this->api();
		file_put_contents( $this->dir . '/backups/a.wpress', 'abc' );

		$this->assertSame( '404 lw_migration_destination_missing', self::code( fn() => $api->upload( [ 'folder' => 'own', 'name' => 'a.wpress', 'destinations' => [ 'ffff0000' ] ] ) ) );
		$this->assertSame( '400 lw_migration_invalid', self::code( fn() => $api->upload( [ 'folder' => 'own', 'name' => 'a.wpress', 'destinations' => [] ] ) ) );
		$this->assertSame( '404 lw_migration_backup_missing', self::code( fn() => $api->upload( [ 'folder' => 'own', 'name' => 'b.wpress', 'destinations' => [ 'aaaa1111' ] ] ) ) );

		$job = $api->upload( [ 'folder' => 'own', 'name' => 'a.wpress', 'destinations' => [ 'aaaa1111' ] ] );
		$this->assertSame( [ CloudJobs::TYPE, 'a.wpress', 3 ], [ $job->type, $job->state['backup']['name'], $job->state['backup']['size'] ] );
	}

	public function test_a_destination_that_does_not_answer_is_422(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->failNext( 'list', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->assertSame( '422 lw_migration_destination_failed', self::code( fn() => $api->files( 'aaaa1111' ) ) );
	}

	public function test_only_a_backup_name_can_be_deleted_at_a_destination(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['notes.txt'] = 'z';

		$refusal = null;
		try {
			$api->deleteFile( 'aaaa1111', 'notes.txt' );
		} catch ( RestException $e ) {
			$refusal = $e;
		}

		$this->assertSame( [ 400, 'name' ], [ $refusal->status ?? 0, $refusal->data['field'] ?? null ] );
		$this->assertSame( [ 'notes.txt' => 'z' ], FakeCloud::$remotes['aaaa1111']->files );
	}

	public function test_an_unknown_or_malformed_destination_is_404_before_any_request(): void {
		$api = $this->api();

		$this->assertSame( '404 lw_migration_destination_missing', self::code( fn() => $api->files( 'ffff0000' ) ) );
		$this->assertSame( '404 lw_migration_destination_missing', self::code( fn() => $api->files( '../x' ) ) );
		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->calls );
	}

	public function test_unknown_or_malformed_fields_are_refused_before_any_job(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';
		file_put_contents( $this->dir . '/backups/b.wpress', 'abc' );

		foreach (
			[
				fn() => $api->restore( 'aaaa1111', [ 'name' => 'a.wpress', 'path' => '/x' ] ),
				fn() => $api->restore( 'aaaa1111', [ 'name' => 'a.wpress', 'safetyBackup' => 'no' ] ),
				fn() => $api->restore( 'aaaa1111', [ 'name' => 'a.wpress', 'password' => [ 'x' ] ] ),
				fn() => $api->restore( 'aaaa1111', [ 'name' => '../a.wpress' ] ),
				fn() => $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress', 'safetyBackup' => true ] ),
				fn() => $api->upload( [ 'folder' => 'own', 'name' => 'b.wpress', 'destinations' => [ 'aaaa1111' ], 'keepLocal' => false ] ),
				fn() => $api->upload( [ 'folder' => 'own', 'name' => 'b.wpress', 'destinations' => 'aaaa1111' ] ),
			] as $call
		) {
			$this->assertSame( '400 lw_migration_invalid', self::code( $call ) );
		}
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_a_restore_names_the_remote_file_it_downloads(): void {
		$api = $this->api();
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';

		$job = $this->jobs->load( $api->restore( 'aaaa1111', [ 'name' => 'a.wpress', 'password' => 'pw' ] )->id );

		$this->assertSame( $job->options['location'], $job->state['cloudSource']['file']['name'] );
		$this->assertSame( [ 3, true ], [ $job->state['cloudSource']['file']['size'], $job->options['safetyBackup'] ] );
	}

	public function test_a_remote_backup_that_is_gone_is_404(): void {
		$api = $this->api();

		$this->assertSame( '404 lw_migration_remote_missing', self::code( fn() => $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress' ] ) ) );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_a_refused_upload_destination_never_echoes_the_submitted_value(): void {
		$api = $this->api();
		file_put_contents( $this->dir . '/backups/a.wpress', 'abc' );

		foreach ( [ [ '<b>typed-by-hand</b>' ], [ 'aaaa1111', 'aaaa1111' ], [ 'AAAA1111' ], [ 5 ] ] as $ids ) {
			$refusal = null;
			try {
				$api->upload( [ 'folder' => 'own', 'name' => 'a.wpress', 'destinations' => $ids ] );
			} catch ( RestException $e ) {
				$refusal = $e;
			}
			$this->assertSame( [ 400, 'destinations' ], [ $refusal->status ?? 0, $refusal->data['field'] ?? null ], json_encode( $ids ) );
			$this->assertStringNotContainsString( 'typed-by-hand', $refusal->getMessage() );
			$this->assertStringNotContainsString( 'aaaa1111', strtolower( $refusal->getMessage() ) );
		}
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_an_unusable_backups_folder_refuses_a_restore_and_a_fetch_before_any_job(): void {
		$api = $this->api(
			static function (): void {
				throw new FolderRefusedException( FolderRefusedException::NOT_WRITABLE );
			}
		);
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';

		$this->assertSame( '409 lw_migration_backup_dir_unusable', self::code( fn() => $api->restore( 'aaaa1111', [ 'name' => 'a.wpress' ] ) ) );
		$this->assertSame( '409 lw_migration_backup_dir_unusable', self::code( fn() => $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress' ] ) ) );
		$this->assertSame( [], $this->jobs->ids() );
	}

	public function test_only_a_backup_in_the_own_folder_clashes_with_a_download(): void {
		mkdir( $this->dir . '/ext' );
		$api = $this->api( null, $this->dir . '/ext' );
		FakeCloud::$remotes['aaaa1111']->files['a.wpress'] = 'abc';
		file_put_contents( $this->dir . '/ext/a.wpress', 'longer' );

		$job = $api->fetch( 'aaaa1111', [ 'name' => 'a.wpress' ] );

		$this->assertSame( CloudJobs::TYPE, $job->type, 'The download lands in the own folder; the external one is not touched.' );
	}
}
