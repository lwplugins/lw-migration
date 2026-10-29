<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class CloudJobsTest extends MonkeyTestCase {

	private const NAME = 'a.wpress';

	private string $dir;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->db  = FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->db;
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_an_upload_job_carries_the_backup_and_a_plan_that_never_prunes_or_deletes(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$job  = CloudJobs::upload( $jobs, new SiteLock(), SiteFixture::make( $this->dir ), new Backup( 'a.wpress', $this->dir . '/a.wpress', 7, 1, Backup::OWN ), CloudPlan::upload( [ 'aaaa1111' ] ), 100 );

		$saved = $jobs->load( $job->id );
		$this->assertSame( [ CloudJobs::TYPE, [ CloudUploadStep::KEY ], [ 'action' => CloudJobs::UPLOAD ] ], [ $saved->type, $saved->steps, $saved->options ] );
		$this->assertSame( [ 'name' => 'a.wpress', 'path' => $this->dir . '/a.wpress', 'size' => 7 ], $saved->state['backup'] );
		$this->assertSame( [ false, true ], [ $saved->state['cloud']['prune'], $saved->state['cloud']['keepLocal'] ] );
	}

	public function test_a_fetch_job_downloads_one_remote_file(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$file = new RemoteFile( 'a.wpress', 7, 1, 'k/a.wpress' );

		$job = CloudJobs::fetch( $jobs, new SiteLock(), SiteFixture::make( $this->dir ), 'aaaa1111', $file, 100 );

		$this->assertSame( [ CloudDownloadStep::KEY ], $job->steps );
		$this->assertSame( [ 'action' => CloudJobs::FETCH ], $jobs->load( $job->id )->options );
		$this->assertSame( CloudJobs::source( 'aaaa1111', $file ), $jobs->load( $job->id )->state['cloudSource'] );
	}

	public function test_a_busy_site_starts_nothing(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'ffffffffffffffff', 100 ) );

		try {
			CloudJobs::fetch( $jobs, $lock, SiteFixture::make( $this->dir ), 'aaaa1111', new RemoteFile( 'a.wpress', 7, 1, 'k' ), 100 );
			$this->fail( 'Refused.' );
		} catch ( \LightweightPlugins\Migration\WordPress\SiteBusyException $e ) {
			$this->assertSame( [], $jobs->ids() );
		}
	}

	/** A failed upload job at the destination of index 0, with $cursor. */
	private function uploadJob( array $cursor ): Job {
		$job                  = ( new JobStore( $this->dir . '/jobs' ) )->create( CloudJobs::TYPE, [ CloudUploadStep::KEY ], [ 'action' => CloudJobs::UPLOAD ], 1 );
		$job->state['backup'] = [
			'name' => self::NAME,
			'path' => $this->dir . '/' . self::NAME,
			'size' => 7,
		];
		$job->state['cloud']  = CloudPlan::upload( [ 'aaaa1111' ] )->toArray();
		$job->cursor          = $cursor;
		$job->status          = Job::FAILED;

		return $job;
	}

	private function cloud(): Destinations {
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );

		return FakeCloud::install( [ 'aaaa1111' => 'One' ] );
	}

	/** @return array<string, mixed> The stored copies row, read from the database. */
	private function storedCopies(): array {
		return (array) json_decode( (string) $this->db->value( Options::CLOUD_COPIES ), true );
	}

	public function test_an_ended_upload_is_aborted_at_its_destination_and_its_pending_copy_fails(): void {
		$destinations = $this->cloud();
		$remote       = FakeCloud::$remotes['aaaa1111'];
		CloudCopies::mark( self::NAME, 'aaaa1111', CloudCopies::PENDING, 50 );
		$session = $remote->startUpload( self::NAME, 7 );
		$job     = $this->uploadJob( [ 'index' => 0, 'session' => $session->toArray() ] );

		CloudJobs::abortUpload( $destinations, $job );

		$this->assertSame( [], $remote->sessions, 'The unfinished upload is gone.' );
		$this->assertContains( 'abort:' . self::NAME, $remote->calls );
		$this->assertSame( CloudCopies::FAILED, $this->storedCopies()[ self::NAME ]['aaaa1111']['status'] );
	}

	public function test_a_pending_copy_without_a_started_upload_fails_and_nothing_is_sent(): void {
		$destinations = $this->cloud();
		CloudCopies::mark( self::NAME, 'aaaa1111', CloudCopies::PENDING, 50 );
		$job = $this->uploadJob( [ 'index' => 0, 'failures' => 1, 'notBefore' => 99 ] );

		CloudJobs::abortUpload( $destinations, $job );

		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->calls );
		$this->assertSame( CloudCopies::FAILED, $this->storedCopies()[ self::NAME ]['aaaa1111']['status'] );
	}

	public function test_a_copy_that_is_not_pending_is_left_alone(): void {
		$destinations = $this->cloud();
		CloudCopies::mark( self::NAME, 'aaaa1111', CloudCopies::UP, 50 );
		$job = $this->uploadJob( [ 'index' => 0 ] );

		CloudJobs::abortUpload( $destinations, $job );

		$this->assertSame( [ 'status' => CloudCopies::UP, 'at' => 50 ], $this->storedCopies()[ self::NAME ]['aaaa1111'] );
	}

	public function test_an_upload_whose_profile_was_deleted_only_fails_its_pending_copy(): void {
		$destinations = $this->cloud();
		$session      = FakeCloud::$remotes['aaaa1111']->startUpload( self::NAME, 7 );
		CloudCopies::mark( self::NAME, 'aaaa1111', CloudCopies::PENDING, 50 );
		$destinations->forget( 'aaaa1111' );
		$job = $this->uploadJob( [ 'index' => 0, 'session' => $session->toArray() ] );

		CloudJobs::abortUpload( $destinations, $job );

		$this->assertNotContains( 'abort:' . self::NAME, FakeCloud::$remotes['aaaa1111']->calls );
		$this->assertSame( CloudCopies::FAILED, $this->storedCopies()[ self::NAME ]['aaaa1111']['status'] );
	}
}
