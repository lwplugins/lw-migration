<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Rest\ActiveJob;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ActiveJobTest extends TestCase {

	private string $dir;

	private JobStore $jobs;

	protected function setUp(): void {
		$this->dir  = TempDir::create();
		$this->jobs = new JobStore( $this->dir );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	/**
	 * @param array<string, mixed> $options Job options.
	 */
	private function job( string $type, string $status, int $updated, array $options = [] ): Job {
		$job            = $this->jobs->create( $type, [ 'a' ], $options, 1 );
		$job->status    = $status;
		$job->updatedAt = $updated;
		$this->jobs->save( $job );

		return $job;
	}

	public function test_the_lock_holder_comes_first_then_the_newest_unfinished_job(): void {
		$running = $this->job( 'export', Job::RUNNING, 5 );
		$waiting = $this->job( 'import', Job::NEEDS_INPUT, 9 );
		$done    = $this->job( 'export', Job::DONE, 20 );

		$this->assertSame( $running->id, ActiveJob::find( $this->jobs, $running->id )->id );
		$this->assertSame( $waiting->id, ActiveJob::find( $this->jobs, null )->id );
		$this->assertSame( $waiting->id, ActiveJob::find( $this->jobs, $done->id )->id, 'A finished holder is skipped.' );
		$this->assertSame( $waiting->id, ActiveJob::find( $this->jobs, '0000000000000000' )->id );
	}

	public function test_no_unfinished_job(): void {
		$this->job( 'export', Job::DONE, 5 );
		$this->job( 'import', Job::CANCELLED, 6 );

		$this->assertNull( ActiveJob::find( $this->jobs, null ) );
	}

	public function test_a_job_that_ran_after_the_last_save_of_another_is_found(): void {
		$old   = $this->job( 'import', Job::RUNNING, 50 );
		$newer = $this->jobs->create( 'export', [ 'a' ], [], 60 );

		$this->assertFalse( ActiveJob::ranAfter( $this->jobs, $old ), 'A newer job that never ran does not count.' );

		$newer->status = Job::RUNNING;
		$this->jobs->save( $newer );
		$this->assertTrue( ActiveJob::ranAfter( $this->jobs, $old ) );

		$newer->status    = Job::PENDING;
		$newer->updatedAt = 61;
		$this->jobs->save( $newer );
		$this->assertTrue( ActiveJob::ranAfter( $this->jobs, $old ), 'Saved after its creation: it ran.' );
		$this->assertFalse( ActiveJob::ranAfter( $this->jobs, $newer ), 'The job itself and older jobs do not count.' );

		$old->updatedAt = 60;
		$this->assertFalse( ActiveJob::ranAfter( $this->jobs, $old ), 'Started in the same second is not later.' );
	}

	public function test_an_unfinished_restore_reading_a_backup_is_found(): void {
		$this->job( 'import', Job::RUNNING, 5, [ 'source' => 'backup', 'location' => 'twin.wpress' ] );
		$this->job( 'import', Job::NEEDS_INPUT, 5, [ 'source' => 'backup', 'location' => 'ext.wpress', 'folder' => 'external' ] );
		$this->job( 'import', Job::DONE, 5, [ 'source' => 'backup', 'location' => 'old.wpress' ] );
		$this->job( 'import', Job::RUNNING, 5, [ 'source' => 'url', 'location' => 'https://x.test/url.wpress' ] );

		$this->assertTrue( ActiveJob::readsBackup( $this->jobs, 'twin.wpress', 'own' ) );
		$this->assertTrue( ActiveJob::readsBackup( $this->jobs, 'twin.wpress', 'external' ), 'Without a folder the job may read either.' );
		$this->assertTrue( ActiveJob::readsBackup( $this->jobs, 'ext.wpress', 'external' ) );
		$this->assertFalse( ActiveJob::readsBackup( $this->jobs, 'ext.wpress', 'own' ) );
		$this->assertFalse( ActiveJob::readsBackup( $this->jobs, 'old.wpress', 'own' ) );
		$this->assertFalse( ActiveJob::readsBackup( $this->jobs, 'url.wpress', 'own' ) );
	}

	public function test_an_unfinished_cloud_restore_protects_its_backup_in_the_backups_folder(): void {
		$this->job( 'import', Job::RUNNING, 5, [ 'source' => 'cloud', 'location' => 'cloud.wpress' ] );
		$other                          = $this->job( 'import', Job::NEEDS_INPUT, 5, [ 'source' => 'cloud', 'location' => 'named.wpress' ] );
		$other->state['backup']['name'] = 'state.wpress';
		$this->jobs->save( $other );
		$this->job( 'import', Job::FAILED, 5, [ 'source' => 'cloud', 'location' => 'ended.wpress' ] );

		$this->assertTrue( ActiveJob::readsBackup( $this->jobs, 'cloud.wpress', 'own' ) );
		$this->assertFalse( ActiveJob::readsBackup( $this->jobs, 'cloud.wpress', 'external' ), 'A download lands in the backups folder only.' );
		$this->assertTrue( ActiveJob::readsBackup( $this->jobs, 'state.wpress', 'own' ) );
		$this->assertFalse( ActiveJob::readsBackup( $this->jobs, 'ended.wpress', 'own' ) );
	}
}
