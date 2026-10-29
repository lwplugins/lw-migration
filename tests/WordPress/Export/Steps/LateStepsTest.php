<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Export\Steps\CleanStep;
use LightweightPlugins\Migration\Export\Steps\FinalizeStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LateStepsTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function archive( Job $job, string $bytes = 'archive' ): string {
		$path = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		file_put_contents( $path, $bytes );

		return $path;
	}

	public function test_finalize_fails_on_a_missing_archive(): void {
		$job = JobFixture::export( $this->store, $this->dir );
		$this->expectException( ExportException::class );
		( new FinalizeStep( $this->store ) )->run( $job, new NoDeadline() );
	}

	public function test_finalize_drops_bytes_after_the_last_complete_entry(): void {
		$job                         = JobFixture::export( $this->store, $this->dir );
		$path                        = $this->archive( $job, str_repeat( 'a', 10 ) . 'orphan' );
		$job->state['archiveLength'] = 10;

		$this->assertTrue( ( new FinalizeStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		clearstatcache();
		$this->assertSame( str_repeat( 'a', 10 ), substr( (string) file_get_contents( $path ), 0, 10 ) );
		$this->assertSame( 10 + Format::HEADER_SIZE, filesize( $path ) );
	}

	public function test_finalize_rerun_after_an_unsaved_eof_writes_it_exactly_once(): void {
		$job                         = JobFixture::export( $this->store, $this->dir );
		$path                        = $this->archive( $job, str_repeat( 'a', 10 ) );
		$job->state['archiveLength'] = 10;
		$step                        = new FinalizeStep( $this->store );

		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$first       = (string) file_get_contents( $path );
		$job->cursor = []; // The request died after writing the EOF, before the cursor was saved.
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		clearstatcache();
		$this->assertSame( 10 + Format::HEADER_SIZE, filesize( $path ) );
		$this->assertSame( $first, file_get_contents( $path ) );
	}

	public function test_finalize_resume_from_a_saved_partial_cursor_after_the_eof_was_written(): void {
		$job                         = JobFixture::export( $this->store, $this->dir );
		$length                      = 2 * 1048576 + 5;
		$path                        = $this->archive( $job, str_repeat( 'b', $length ) );
		$job->state['archiveLength'] = $length;
		$step                        = new FinalizeStep( $this->store );

		$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$partial = $job->cursor;
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$first       = (string) file_get_contents( $path );
		$job->cursor = $partial; // Died after the EOF, the last saved cursor is the partial one.
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		clearstatcache();
		$this->assertSame( $length + Format::HEADER_SIZE, filesize( $path ) );
		$this->assertSame( $first, file_get_contents( $path ) );
	}

	public function test_finalize_of_a_pull_export_hashes_the_finished_archive_over_several_ticks(): void {
		$job                         = JobFixture::export( $this->store, $this->dir );
		$job->steps                  = ExportPipeline::LINK_STEPS;
		$length                      = 3 * 8192 + 5; // Patchwork's stream wrapper reads 8 KiB per block here.
		$path                        = $this->archive( $job, str_repeat( 'c', $length ) );
		$job->state['archiveLength'] = $length;
		$step                        = new FinalizeStep( $this->store );

		$ticks   = 0;
		$hashing = 0;
		do {
			$result      = $step->run( $job, new CountdownDeadline( 0 ) );
			$job->cursor = json_decode( (string) json_encode( $job->cursor ), true ); // Saved in job.json between ticks.
			$hashing    += isset( $job->cursor['sha256'] ) ? 1 : 0;
			++$ticks;
		} while ( ! $result->isDone() && $ticks < 100 );

		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 1, $hashing, 'The SHA-256 stops at the deadline and goes on in the next tick.' );
		clearstatcache();
		$this->assertSame( $length + Format::HEADER_SIZE, filesize( $path ) );
		$this->assertSame( hash_file( 'sha256', $path ), $job->state['archiveSha256'], 'Over the finished archive, EOF block included.' );
	}

	public function test_finalize_of_another_export_computes_no_sha256(): void {
		$job                         = JobFixture::export( $this->store, $this->dir );
		$this->archive( $job, str_repeat( 'a', 10 ) );
		$job->state['archiveLength'] = 10;

		$this->assertTrue( ( new FinalizeStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertArrayNotHasKey( 'archiveSha256', $job->state );
	}

	public function test_clean_removes_work_files_and_an_empty_sub_folder(): void {
		$job                   = JobFixture::export( $this->store, $this->dir );
		$job->state['workSub'] = 'safety';
		$work                  = $this->store->dir( $job->id ) . '/safety';
		mkdir( $work );
		foreach ( [ ExportFiles::ARCHIVE, ExportFiles::LIST, ExportFiles::LIST . '.dirs', ExportFiles::DATABASE ] as $file ) {
			file_put_contents( $work . '/' . $file, 'x' );
		}

		$this->assertTrue( ( new CleanStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertDirectoryDoesNotExist( $work );
		$this->assertFileExists( $this->store->dir( $job->id ) . '/job.json' );
	}
}
