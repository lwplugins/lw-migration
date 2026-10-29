<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\DatabaseFileStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class DatabaseFileStepTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	/**
	 * Each change() moves the mtime to a new, later second (a random one could
	 * repeat the previous one with the size kept, so no change would show).
	 */
	private int $bump = 0;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'size_format' )->alias( static fn( $bytes, $decimals = 0 ) => $bytes . ' B/' . $decimals );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_appends_the_dump_as_last_entry_and_skips_without_database(): void {
		$job  = JobFixture::export( $this->store, $this->dir );
		$work = $this->store->dir( $job->id );
		$sql  = str_repeat( "INSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\n", 20000 );
		file_put_contents( $work . '/' . ExportFiles::DATABASE, $sql );
		$writer = new Writer( $work . '/' . ExportFiles::ARCHIVE );
		$writer->addString( 'package.json', '{}', 1 );
		$job->state['archiveLength'] = $writer->length();
		$writer->close();

		$step = new DatabaseFileStep( $this->store );
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 20 );
		$this->assertGreaterThan( 1, $runs, 'The dump is appended across ticks.' );

		$writer = new Writer( $work . '/' . ExportFiles::ARCHIVE );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		$reader = new Reader( $work . '/' . ExportFiles::ARCHIVE );
		$this->assertSame( $sql, $reader->readContents( $reader->find( 'database.sql' ) ) );
		$reader->close();

		$none = JobFixture::export( $this->store, $this->dir, [ 'noDatabase' => true ] );
		$this->assertTrue( $step->run( $none, new NoDeadline() )->isDone() );
	}

	public function test_every_tick_reports_how_much_of_the_dump_is_in(): void {
		$job  = $this->jobWithDump( str_repeat( "INSERT INTO `SERVMASK_PREFIX_t` VALUES (1);\n", 40000 ) );
		$step = new DatabaseFileStep( $this->store );
		$seen = [];
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			if ( ! $result->isDone() ) {
				$seen[] = [ $result->progress(), $result->message() ];
			}
		} while ( ! $result->isDone() && count( $seen ) < 50 );

		$this->assertGreaterThan( 1, count( $seen ) );
		$messages = array_column( $seen, 1 );
		$this->assertSame( $messages, array_values( array_unique( $messages ) ), 'Every tick shows movement (a connected site gives up on a job that shows the same).' );
		$this->assertMatchesRegularExpression( '@^Adding the database to the backup: \d+ B/1 of 1760000 B/1\.\.\.$@', $messages[0] );
		$progress = array_column( $seen, 0 );
		$sorted   = $progress;
		sort( $sorted );
		$this->assertSame( $sorted, $progress );
		$this->assertLessThan( 100, end( $progress ) );
		$this->assertGreaterThan( 0, $progress[0] );
	}

	public function test_a_missing_dump_fails_the_step(): void {
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:The database dump is missing.' );
		( new DatabaseFileStep( $this->store ) )->run( JobFixture::export( $this->store, $this->dir ), new NoDeadline() );
	}

	public function test_a_dump_that_keeps_changing_fails_with_its_own_message(): void {
		$job  = $this->jobWithDump( str_repeat( 'a', 600000 ) );
		$sql  = $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE;
		$step = new DatabaseFileStep( $this->store );
		$step->run( $job, new CountdownDeadline( 0 ) );                 // First chunk written.
		$this->change( $sql, 'b' );
		$step->run( $job, new CountdownDeadline( 0 ) );                 // Changed: restart flagged.
		$step->run( $job, new CountdownDeadline( 0 ) );                 // Restarted, first chunk again.
		$this->change( $sql, 'c' );

		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:The database dump kept changing while it was added to the backup.' );
		$step->run( $job, new NoDeadline() );
	}

	public function test_an_unreadable_dump_fails_with_its_own_message(): void {
		$job = $this->jobWithDump( 'SELECT 1;' );
		$sql = $this->store->dir( $job->id ) . '/' . ExportFiles::DATABASE;
		chmod( $sql, 0000 );
		clearstatcache();
		if ( is_readable( $sql ) ) {
			chmod( $sql, 0644 );
			$this->markTestSkipped( 'File permissions are not enforced for this user.' );
		}

		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		try {
			$this->expectException( ExportException::class );
			$this->expectExceptionMessage( 'T:The database dump cannot be read.' );
			( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() );
		} finally {
			chmod( $sql, 0644 );
		}
	}

	private function jobWithDump( string $sql ): Job {
		$job  = JobFixture::export( $this->store, $this->dir );
		$work = $this->store->dir( $job->id );
		file_put_contents( $work . '/' . ExportFiles::DATABASE, $sql );
		$writer = new Writer( $work . '/' . ExportFiles::ARCHIVE );
		$writer->addString( 'package.json', '{}', 1 );
		$job->state['archiveLength'] = $writer->length();
		$writer->close();

		return $job;
	}

	private function change( string $path, string $fill ): void {
		file_put_contents( $path, str_repeat( $fill, 600001 ) );
		touch( $path, time() + 100 + ( ++$this->bump * 10 ) );
		clearstatcache();
	}
}
