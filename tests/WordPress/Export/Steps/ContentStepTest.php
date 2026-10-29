<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Archive\Writer;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\ContentStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ContentStepTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

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

	/**
	 * A job whose archive holds package.json and whose list holds $files (entry => bytes).
	 *
	 * @param array<string, string> $files   Entry name => contents.
	 * @param array<string, mixed>  $options Export options.
	 * @param array<string, mixed>  $secrets Job secrets.
	 * @param string                $extra   Raw text appended to the list.
	 */
	private function job( array $files, array $options = [], array $secrets = [], string $extra = '' ): Job {
		$job  = JobFixture::export( $this->store, $this->dir, $options, $secrets );
		$work = $this->store->dir( $job->id );
		$list = '';
		foreach ( $files as $entry => $data ) {
			$path = $this->dir . '/src/' . $entry;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			file_put_contents( $path, $data );
			$list .= base64_encode( $entry ) . ' ' . base64_encode( $path ) . ' ' . strlen( $data ) . " 1\n";
		}
		file_put_contents( $work . '/' . ExportFiles::LIST, $extra . $list );
		$writer = new Writer( $work . '/' . ExportFiles::ARCHIVE, ArchiveCodec::forJob( $job ) );
		$writer->addString( 'package.json', '{}', 1 );
		$job->state['archiveLength'] = $writer->length();
		$writer->close();

		return $job;
	}

	private static function big( string $seed ): string {
		$data = '';
		for ( $i = 0; strlen( $data ) < 600000; $i++ ) {
			$data .= hash( 'sha256', $seed . $i, true );
		}
		return substr( $data, 0, 600000 );
	}

	/** Runs the step to the end, saving and reloading the job between ticks like the Runner does. */
	private function runAll( Job $job, callable $deadline ): Job {
		$step  = new ContentStep( $this->store );
		$ticks = 0;
		do {
			$result = $step->run( $job, $deadline() );
			$this->store->save( $job );
			$secrets      = $job->secrets;
			$job          = $this->store->load( $job->id );
			$job->secrets = $secrets;
			++$ticks;
		} while ( ! $result->isDone() && $ticks < 100 );
		$this->assertTrue( $result->isDone() );

		return $job;
	}

	/** @return array<string, string> Entry name => contents, after finalizing. */
	private function archived( Job $job ): array {
		$path   = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		$writer = new Writer( $path );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		$reader = new Reader( $path, ArchiveCodec::forJob( $job ) );
		$reader->validate();
		$this->assertTrue( $reader->verifyChecksum( new CrcCursor(), new NoDeadline() ) );
		$out = [];
		foreach ( $reader->entries() as $entry ) {
			$out[ $entry->name ] = $reader->readContents( $entry );
		}
		$reader->close();

		return $out;
	}

	public function test_archives_every_listed_file_across_ticks_encrypted(): void {
		$files = [ 'index.php' => '<?php', 'uploads/a b/é.txt' => 'é', 'plugins/p/big.bin' => self::big( 'x' ) ];
		$job   = $this->runAll( $this->job( $files, [ 'encrypt' => true, 'compression' => 'gzip' ], [ 'password' => 'pw' ] ), static fn(): Deadline => new CountdownDeadline( 0 ) );

		$this->assertSame( [ 'package.json' => '{}' ] + $files, $this->archived( $job ) );
		$this->assertSame( [], $job->warnings );
	}

	public function test_orphan_bytes_after_last_entry_are_dropped_on_resume(): void {
		$files = [ 'a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c' ];
		$job   = $this->job( $files );
		$step  = new ContentStep( $this->store );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		// A request died after writing a header it never recorded.
		file_put_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, str_repeat( "\x01", 5000 ), FILE_APPEND );

		$job = $this->runAll( $job, static fn(): Deadline => new NoDeadline() );
		$this->assertSame( [ 'package.json' => '{}' ] + $files, $this->archived( $job ) );
	}

	public function test_file_changed_between_ticks_is_restarted_once_then_skipped(): void {
		$job  = $this->job( [ 'big.bin' => self::big( 'one' ), 'z.txt' => 'z' ] );
		$src  = $this->dir . '/src/big.bin';
		$step = new ContentStep( $this->store );
		$step->run( $job, new CountdownDeadline( 0 ) );                  // First chunk of big.bin written.
		$this->change( $src, 'two' );
		$step->run( $job, new CountdownDeadline( 0 ) );                  // Changed: restart flagged.
		$step->run( $job, new CountdownDeadline( 0 ) );                  // Restarted copy, first chunk again.
		$this->change( $src, 'three' );
		$job = $this->runAll( $job, static fn(): Deadline => new NoDeadline() ); // Changed again: skipped.

		$this->assertSame( [ 'package.json' => '{}', 'z.txt' => 'z' ], $this->archived( $job ) );
		$this->assertStringContainsString( 'big.bin', implode( ' ', $job->warnings ) );
	}

	public function test_every_tick_within_one_big_file_shows_movement(): void {
		$job      = $this->job( [ 'big.bin' => str_repeat( self::big( 'one' ), 5 ), 'z.txt' => 'z' ] );
		$step     = new ContentStep( $this->store );
		$messages = [];
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			if ( ! $result->isDone() && isset( $job->cursor['file'] ) ) {
				$messages[] = $result->message();
			}
		} while ( ! $result->isDone() && count( $messages ) < 50 );

		$this->assertGreaterThan( 1, count( $messages ), 'big.bin takes several ticks.' );
		$this->assertSame( $messages, array_values( array_unique( $messages ) ), 'The list offset stays within one file; the text moves (a connected site gives up on a job that shows the same).' );
		$this->assertMatchesRegularExpression( '@^Archiving files \(\d+ B/1 written\)\.\.\.$@', $messages[0] );
	}

	public function test_file_changed_once_is_archived_with_its_new_contents(): void {
		$job = $this->job( [ 'big.bin' => self::big( 'one' ) ] );
		$src = $this->dir . '/src/big.bin';
		( new ContentStep( $this->store ) )->run( $job, new CountdownDeadline( 0 ) );
		$this->change( $src, 'two' );
		$job = $this->runAll( $job, static fn(): Deadline => new NoDeadline() );

		$this->assertSame( (string) file_get_contents( $src ), $this->archived( $job )['big.bin'] );
		$this->assertSame( [], $job->warnings );
	}

	public function test_unreadable_file_is_skipped_with_a_warning(): void {
		$job = $this->job( [ 'gone.txt' => 'x', 'kept.txt' => 'k' ] );
		unlink( $this->dir . '/src/gone.txt' );
		$job = $this->runAll( $job, static fn(): Deadline => new NoDeadline() );

		$this->assertSame( [ 'package.json' => '{}', 'kept.txt' => 'k' ], $this->archived( $job ) );
		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'gone.txt', $job->warnings[0] );
	}

	public function test_damaged_list_line_is_skipped_with_a_warning(): void {
		$job = $this->runAll( $this->job( [ 'kept.txt' => 'k' ], [], [], "not a list line\n" ), static fn(): Deadline => new NoDeadline() );

		$this->assertSame( [ 'package.json' => '{}', 'kept.txt' => 'k' ], $this->archived( $job ) );
		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'file list', $job->warnings[0] );
	}

	public function test_an_archive_error_fails_the_step_without_skipping_or_moving_the_cursor(): void {
		$job    = $this->job( [ 'a.txt' => 'a', 'b.txt' => 'b' ] );
		$step   = new ContentStep( $this->store );
		$step->run( $job, new CountdownDeadline( 0 ) );
		$cursor = $job->cursor;
		$length = $job->state['archiveLength'];
		// The saved length is now past the end of the archive: truncateTo() refuses it.
		$job->state['archiveLength'] = $length + 1;

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The archive error must surface.' );
		} catch ( ArchiveException $e ) {
			$this->assertSame( $cursor, $job->cursor );
			$this->assertSame( $length + 1, $job->state['archiveLength'] );
			$this->assertSame( [], $job->warnings );
		}
	}

	public function test_an_entry_replaced_by_an_unsaved_tick_is_restarted(): void {
		$files = [ 'x.bin' => self::big( 'x' ) . self::big( 'x2' ), 'y.bin' => self::big( 'y' ) ];
		$job   = $this->job( $files );
		$src   = $this->dir . '/src/x.bin';
		$step  = new ContentStep( $this->store );
		$step->run( $job, new CountdownDeadline( 0 ) );                 // x.bin open after one chunk.
		$this->store->save( $job );
		rename( $src, $src . '.away' );                                 // x.bin unreadable: skipped, y.bin written in its place ...
		$step->run( $job, new NoDeadline() );
		$this->assertCount( 1, $job->warnings );
		rename( $src . '.away', $src );                                 // ... then the request died: that tick's state is lost.

		$job = $this->runAll( $this->store->load( $job->id ), static fn(): Deadline => new NoDeadline() );
		$this->assertSame( [ 'package.json' => '{}' ] + $files, $this->archived( $job ) );
		$this->assertSame( [], $job->warnings );
	}

	public function test_garbage_after_an_open_entry_is_dropped_on_resume(): void {
		$files = [ 'x.bin' => self::big( 'x' ) . self::big( 'x2' ), 'z.txt' => 'z' ];
		$job   = $this->job( $files, [ 'compression' => 'gzip' ] );
		( new ContentStep( $this->store ) )->run( $job, new CountdownDeadline( 0 ) );
		$this->store->save( $job );
		// The next request wrote more of x.bin than it recorded, then died mid-chunk.
		file_put_contents( $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE, random_bytes( 70000 ), FILE_APPEND );

		$job = $this->runAll( $this->store->load( $job->id ), static fn(): Deadline => new NoDeadline() );
		$this->assertSame( [ 'package.json' => '{}' ] + $files, $this->archived( $job ) );
	}

	public function test_a_missing_file_list_fails_unless_files_are_excluded(): void {
		$none = JobFixture::export( $this->store, $this->dir, [ 'noFiles' => true ] );
		$this->assertTrue( ( new ContentStep( $this->store ) )->run( $none, new NoDeadline() )->isDone() );

		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:The file list is missing.' );
		( new ContentStep( $this->store ) )->run( JobFixture::export( $this->store, $this->dir ), new NoDeadline() );
	}

	private function change( string $path, string $seed ): void {
		file_put_contents( $path, self::big( $seed ) . 'tail' );
		touch( $path, time() + random_int( 100, 1000 ) );
		clearstatcache();
	}
}
