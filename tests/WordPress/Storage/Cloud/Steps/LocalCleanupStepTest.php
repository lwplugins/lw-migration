<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\LocalCleanupStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LocalCleanupStepTest extends MonkeyTestCase {

	private string $dir;

	private string $name;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		$this->dir  = TempDir::create();
		$this->name = BackupName::forSite( 'https://example.com', 1760238000, 1 );
		file_put_contents( $this->dir . '/' . $this->name, 'x' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job( bool $keepLocal ): Job {
		$job                  = new Job( '0123456789abcdef', 'export', [ LocalCleanupStep::KEY ] );
		$job->state['backup'] = [
			'name' => $this->name,
			'path' => $this->dir . '/' . $this->name,
			'size' => 1,
		];
		$job->state['cloud']  = CloudPlan::manual( [ 'aaaa1111', 'bbbb2222' ], $keepLocal )->toArray();

		return $job;
	}

	private function step(): LocalCleanupStep {
		return new LocalCleanupStep( fn(): Repository => new Repository( $this->dir, '' ) );
	}

	public function test_deletes_the_local_copy_only_when_every_upload_succeeded_and_keep_local_is_off(): void {
		$job = $this->job( false );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertFileDoesNotExist( $this->dir . '/' . $this->name );
		$this->assertFalse( $job->state['backup']['local'] );
	}

	/**
	 * Review Focus 4.
	 */
	public function test_a_failed_or_skipped_destination_keeps_the_local_copy(): void {
		foreach ( [ CloudOutcome::FAILED, CloudOutcome::SKIPPED ] as $status ) {
			$job = $this->job( false );
			CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
			CloudOutcome::record( $job, 'bbbb2222', $status, 'Two', 's3', 'Denied.' );

			$this->step()->run( $job, new NoDeadline() );

			$this->assertFileExists( $this->dir . '/' . $this->name, $status );
			$this->assertArrayNotHasKey( 'local', $job->state['backup'] );
			$this->assertStringContainsString( 'kept', implode( "\n", $job->warnings ) );
		}
	}

	public function test_keep_local_on_never_deletes(): void {
		$job = $this->job( true );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertFileExists( $this->dir . '/' . $this->name );
		$this->assertSame( [], $job->warnings );
	}

	/**
	 * P-M3: the file is not the one that was uploaded.
	 */
	public function test_a_local_file_of_another_size_is_kept_with_a_warning(): void {
		$job = $this->job( false );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );
		file_put_contents( $this->dir . '/' . $this->name, 'xyz' );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertFileExists( $this->dir . '/' . $this->name );
		$this->assertArrayNotHasKey( 'local', $job->state['backup'] );
		$this->assertStringContainsString( 'kept', implode( "\n", $job->warnings ) );
	}

	public function test_a_symlink_is_never_deleted(): void {
		$job    = $this->job( false );
		$target = $this->dir . '/target.bin';
		$link   = $this->dir . '/' . BackupName::forSite( 'https://example.com', 1760238001, 2 );
		file_put_contents( $target, 'x' );
		symlink( $target, $link );
		$job->state['backup']['path'] = $link;
		$job->state['backup']['name'] = basename( $link );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertTrue( is_link( $link ) );
		$this->assertFileExists( $target );
		$this->assertStringContainsString( 'kept', implode( "\n", $job->warnings ) );
	}

	public function test_a_file_in_the_external_folder_is_never_deleted(): void {
		$ext = TempDir::create();
		file_put_contents( $ext . '/' . $this->name, 'x' );
		unlink( $this->dir . '/' . $this->name );
		$job = $this->job( false );
		$job->state['backup']['path'] = $ext . '/' . $this->name;
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );

		( new LocalCleanupStep( fn(): Repository => new Repository( $this->dir, $ext ) ) )->run( $job, new NoDeadline() );

		$this->assertFileExists( $ext . '/' . $this->name );
		$this->assertStringContainsString( 'kept', implode( "\n", $job->warnings ) );
		TempDir::remove( $ext );
	}

	public function test_a_replay_after_the_file_was_removed_marks_it_cloud_only_without_a_warning(): void {
		$job = $this->job( false );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );
		unlink( $this->dir . '/' . $this->name );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertFalse( $job->state['backup']['local'] );
		$this->assertSame( [], $job->warnings );
	}
}
