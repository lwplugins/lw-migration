<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\ResetJobs;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class ResetJobsTest extends MonkeyTestCase {

	private const NOW = 1791363600;

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_reset_job_holds_the_lock_and_carries_no_secret(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$lock = new SiteLock();
		$job  = ResetJobs::create( $jobs, $lock, SiteFixture::make( $this->dir ), 'database', true, [ 'id' => 7, 'login' => 'anna' ], self::NOW );
		$this->assertSame( 'reset', $job->type );
		$this->assertSame( [ 'reset_backup', 'reset_db_build', 'reset_db_swap', 'reset_db_finish' ], $job->steps );
		$this->assertSame( [ 'type' => 'database', 'backup' => true ], $job->options );
		$this->assertSame( [ 'id' => 7, 'login' => 'anna' ], ResetJobs::admin( $jobs->load( $job->id ) ) );
		$this->assertSame( $job->id, $lock->holder( self::NOW ) );
		$this->assertStringNotContainsString( 'password', (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' ) );
	}

	public function test_a_busy_site_leaves_nothing_behind(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$lock = new SiteLock();
		$lock->acquire( 'aaaaaaaaaaaaaaaa', self::NOW );
		try {
			ResetJobs::create( $jobs, $lock, SiteFixture::make( $this->dir ), 'media', false, [ 'id' => 7, 'login' => 'anna' ], self::NOW );
			$this->fail( 'Busy.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( [], $jobs->ids() );
		}
	}

	public function test_an_unknown_type_or_no_admin_is_refused_before_the_lock(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		foreach ( [ [ 'all', [ 'id' => 7, 'login' => 'anna' ] ], [ 'media', [ 'id' => 0, 'login' => '' ] ] ] as [ $type, $admin ] ) {
			try {
				ResetJobs::create( $jobs, new SiteLock(), SiteFixture::make( $this->dir ), $type, false, $admin, self::NOW );
				$this->fail( $type );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNull( ( new SiteLock() )->holder( self::NOW ) );
			}
		}
	}

	public function test_the_point_of_no_return_is_the_swap_marker(): void {
		$jobs = new JobStore( $this->dir . '/jobs' );
		$job  = ResetJobs::create( $jobs, new SiteLock(), SiteFixture::make( $this->dir ), 'database', false, [ 'id' => 7, 'login' => 'anna' ], self::NOW );
		$dir  = $jobs->dir( $job->id );
		$this->assertFalse( ResetJobs::pastNoReturn( $job, $dir ) );
		touch( $dir . '/swap-started' );
		$this->assertTrue( ResetJobs::pastNoReturn( $job, $dir ) );
	}

	public function test_a_reset_that_did_not_finish_discards_its_backup_work_and_a_finished_one_keeps_it(): void {
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		try {
			FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
			$services = new Services();
			$services->storage()->ensure();
			$jobs    = $services->jobs();
			$backups = $services->storage()->backupsDir();
			foreach ( [ Job::FAILED => false, Job::CANCELLED => false, Job::DONE => true ] as $status => $kept ) {
				$job  = $jobs->create( 'reset', [ 'reset_backup', 'reset_media' ], [ 'type' => 'media', 'backup' => true ], self::NOW );
				$temp = TempName::name();
				mkdir( $jobs->dir( $job->id ) . '/reset-backup' );
				file_put_contents( $backups . '/' . $temp, 'half a backup' );
				$job->cursor = [ 'backup' => [ 'cursor' => [ 'temp' => $temp ] ] ];
				$job->status = $status;

				ResetJobs::finished( $services, $job );

				$this->assertSame( $kept, is_dir( $jobs->dir( $job->id ) . '/reset-backup' ), $status );
				$this->assertSame( $kept, is_file( $backups . '/' . $temp ), $status );
			}
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}
}
