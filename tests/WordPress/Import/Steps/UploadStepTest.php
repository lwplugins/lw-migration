<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\UploadStep;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class UploadStepTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_waits_for_the_whole_upload_then_takes_the_file(): void {
		$store                  = new JobStore( $this->dir . '/jobs' );
		$job                    = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'upload', 'location' => 'site.wpress' ] );
		$job->state['upload']   = [ 'size' => 10 ];
		$step                   = new UploadStep( $store );
		$target                 = UploadStep::target( $store, $job->id );
		$this->assertSame( $store->dir( $job->id ) . '/' . ImportFiles::UPLOAD, $target, 'The one place the upload path is built (also for the REST upload route).' );

		$first = $step->run( $job, new NoDeadline() );
		$this->assertSame( UploadStep::KEY, $first->needsInputKind() );
		$this->assertSame( [ 'received' => 0, 'size' => 10 ], $first->inputData() );

		file_put_contents( $target . UploadReceiver::PART_SUFFIX, '12345' );
		$this->assertSame( [ 'received' => 5, 'size' => 10 ], $step->run( $job, new NoDeadline() )->inputData() );

		rename( $target . UploadReceiver::PART_SUFFIX, $target );
		file_put_contents( $target, '1234567890' );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $target, $job->state['archive'] );
		$this->assertTrue( $job->state['archiveOwned'], 'Cleanup deletes the upload.' );
	}

	public function test_a_job_without_a_declared_size_fails(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'upload', 'location' => 'site.wpress' ] );
		$this->expectException( ImportException::class );
		( new UploadStep( $store ) )->run( $job, new NoDeadline() );
	}

	public function test_a_full_part_left_by_a_failed_rename_is_finished(): void {
		$store                = new JobStore( $this->dir . '/jobs' );
		$job                  = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'upload', 'location' => 'site.wpress' ] );
		$job->state['upload'] = [ 'size' => 10 ];
		$target               = $store->dir( $job->id ) . '/' . ImportFiles::UPLOAD;
		file_put_contents( $target . UploadReceiver::PART_SUFFIX, '1234567890' );

		$this->assertTrue( ( new UploadStep( $store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( '1234567890', file_get_contents( $target ) );
		$this->assertFileDoesNotExist( $target . UploadReceiver::PART_SUFFIX );
	}

	public function test_a_full_part_that_cannot_become_the_upload_fails(): void {
		$store                = new JobStore( $this->dir . '/jobs' );
		$job                  = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'upload', 'location' => 'site.wpress' ] );
		$job->state['upload'] = [ 'size' => 10 ];
		$target               = $store->dir( $job->id ) . '/' . ImportFiles::UPLOAD;
		file_put_contents( $target . UploadReceiver::PART_SUFFIX, '1234567890' );
		mkdir( $target );

		$this->expectException( ImportException::class );
		( new UploadStep( $store ) )->run( $job, new NoDeadline() );
	}

	public function test_a_linked_target_is_not_the_upload(): void {
		$store                = new JobStore( $this->dir . '/jobs' );
		$job                  = ImportJobFixture::make( $store, $this->dir, '', [ 'source' => 'upload', 'location' => 'site.wpress' ] );
		$job->state['upload'] = [ 'size' => 10 ];
		$target               = $store->dir( $job->id ) . '/' . ImportFiles::UPLOAD;
		file_put_contents( $this->dir . '/outside', '1234567890' );
		symlink( $this->dir . '/outside', $target );

		$this->assertSame( UploadStep::KEY, ( new UploadStep( $store ) )->run( $job, new NoDeadline() )->needsInputKind() );
		$this->assertSame( '', $job->state['archive'], 'The link is not taken as the archive.' );
		$this->assertArrayNotHasKey( 'archiveOwned', $job->state );
	}
}
