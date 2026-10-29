<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Eof;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\Steps\CrcStep;
use LightweightPlugins\Migration\Import\Steps\SourceStep;
use LightweightPlugins\Migration\Import\Steps\ValidateStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use Patchwork\CodeManipulation\Stream;

final class ArchiveChecksTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

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

	public function test_source_resolves_files_and_backups(): void {
		mkdir( $this->dir . '/own' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/own/b.wpress' );
		$step = new SourceStep( new Repository( $this->dir . '/own', '' ), $this->dir . '/jobs' );

		$job = ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'backup', 'location' => 'b.wpress' ] );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( realpath( $this->dir . '/own/b.wpress' ), $job->state['archive'] );
		$this->assertFalse( $job->state['archiveOwned'] );

		$file = ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'file', 'location' => self::FIXTURES . 'plain.wpress' ] );
		$step->run( $file, new NoDeadline() );
		$this->assertSame( realpath( self::FIXTURES . 'plain.wpress' ), $file->state['archive'] );

		$missing = ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'backup', 'location' => 'none.wpress' ] );
		$this->expectFailure( $step, $missing, 'none.wpress' );
	}

	public function test_source_refuses_a_missing_file_without_showing_its_path(): void {
		$step = new SourceStep( new Repository( $this->dir . '/own', '' ), $this->dir . '/jobs' );
		$job  = ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'file', 'location' => $this->dir . '/none.wpress' ] );
		$this->expectFailure( $step, $job, 'none.wpress' );

		file_put_contents( $this->dir . '/notes.txt', 'x' );
		$wrong = ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'file', 'location' => $this->dir . '/notes.txt' ] );
		$this->expectFailure( $step, $wrong, 'notes.txt' );
	}

	public function test_source_refuses_links_and_files_inside_the_jobs_folder(): void {
		$step = new SourceStep( new Repository( $this->dir . '/own', '' ), $this->dir . '/jobs' );
		mkdir( $this->dir . '/real' );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/real/b.wpress' );
		file_put_contents( $this->dir . '/real/notes.txt', 'x' );

		symlink( $this->dir . '/real/b.wpress', $this->dir . '/link.wpress' );
		$this->expectFailure( $step, $this->fileJob( $this->dir . '/link.wpress' ), 'link.wpress' );

		mkdir( $this->dir . '/jobs/other', 0777, true );
		copy( self::FIXTURES . 'plain.wpress', $this->dir . '/jobs/other/download.wpress' );
		$this->expectFailure( $step, $this->fileJob( $this->dir . '/jobs/other/download.wpress' ), 'download.wpress' );

		// A linked folder is resolved: the saved path and the extension check use the real path.
		symlink( $this->dir . '/real', $this->dir . '/linked' );
		$job = $this->fileJob( $this->dir . '/linked/b.wpress' );
		$step->run( $job, new NoDeadline() );
		$this->assertSame( realpath( $this->dir . '/real/b.wpress' ), $job->state['archive'] );
	}

	public function test_validate_reads_package_and_subsite_config(): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, self::FIXTURES . 'encrypted-gzip.wpress' );
		$this->assertTrue( ( new ValidateStep() )->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( 'https://example.test', $job->state['package']['SiteURL'] );
		$this->assertFalse( $job->state['multisite']['network'] );
		$this->assertSame( 2, $job->state['subsiteId'] );
		$this->assertSame( filesize( self::FIXTURES . 'encrypted-gzip.wpress' ), $job->state['archiveSize'] );

		$plain = ImportJobFixture::make( $this->store, $this->dir, self::FIXTURES . 'plain.wpress' );
		( new ValidateStep() )->run( $plain, new NoDeadline() );
		$this->assertNull( $plain->state['multisite'] );
		$this->assertNull( $plain->state['subsiteId'] );
	}

	public function test_validate_treats_blog_one_as_the_main_site_and_refuses_an_empty_site_list(): void {
		$main = $this->archive( 'main.wpress', '{"Network":false,"Sites":[{"BlogID":1}]}' );
		$job  = ImportJobFixture::make( $this->store, $this->dir, $main );
		( new ValidateStep() )->run( $job, new NoDeadline() );
		$this->assertFalse( $job->state['multisite']['network'] );
		$this->assertNull( $job->state['subsiteId'] );

		$empty = $this->archive( 'empty.wpress', '{"Network":false,"Sites":[]}' );
		try {
			( new ValidateStep() )->run( ImportJobFixture::make( $this->store, $this->dir, $empty ), new NoDeadline() );
			$this->fail( 'An archive without sites was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'lists no site', $e->getMessage() );
		}
	}

	public function test_validate_refuses_a_network_archive_and_a_truncated_file(): void {
		$network = $this->archive( 'network.wpress', '{"Network":true,"Sites":[{"BlogID":1},{"BlogID":2}]}' );
		try {
			( new ValidateStep() )->run( ImportJobFixture::make( $this->store, $this->dir, $network ), new NoDeadline() );
			$this->fail( 'A network archive was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'network', $e->getMessage() );
		}

		$cut = $this->dir . '/cut.wpress';
		file_put_contents( $cut, substr( (string) file_get_contents( self::FIXTURES . 'plain.wpress' ), 0, 100000 ) );
		$this->expectException( InvalidArchiveException::class );
		( new ValidateStep() )->run( ImportJobFixture::make( $this->store, $this->dir, $cut ), new NoDeadline() );
	}

	public function test_crc_is_resumable_and_catches_damage(): void {
		$big = $this->dir . '/big.wpress';
		$writer = new Writer( $big );
		$writer->addString( 'package.json', '{"SiteURL":"https://big.test"}', 1 );
		foreach ( [ 'a', 'b', 'c' ] as $name ) {
			$writer->addString( $name . '.bin', str_repeat( $name, 1048576 ), 1 );
		}
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		$job     = ImportJobFixture::make( $this->store, $this->dir, $big );
		$step    = new CrcStep();
		$result  = $this->runPlain( $step, $job );
		$midway  = $job->cursor;
		$runs    = 1;
		while ( ! $result->isDone() && $runs < 10 ) {
			$result = $this->runPlain( $step, $job );
			++$runs;
		}
		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThanOrEqual( 2, $runs );
		$this->assertGreaterThan( 0, $midway['offset'] );
		$this->assertLessThan( $midway['size'], $midway['offset'] );
		$this->assertSame( $midway['size'], $job->cursor['offset'] );

		$h = fopen( $big, 'r+b' );
		fseek( $h, 1500000 );
		fwrite( $h, 'X' );
		fclose( $h );
		$this->expectException( CorruptedEntryException::class );
		$step->run( ImportJobFixture::make( $this->store, $this->dir, $big ), new NoDeadline() );
	}

	public function test_crc_passes_an_archive_with_the_old_end_block(): void {
		$old  = $this->dir . '/old.wpress';
		$data = (string) file_get_contents( self::FIXTURES . 'plain.wpress' );
		file_put_contents( $old, substr( $data, 0, -Format::HEADER_SIZE ) . Eof::v1() );

		$job = ImportJobFixture::make( $this->store, $this->dir, $old );
		$this->assertTrue( ( new CrcStep() )->run( $job, new CountdownDeadline( 0 ) )->isDone() );
	}

	/**
	 * Runs the step with a one-check deadline on PHP's own file stream: Brain
	 * Monkey's code wrapper on file:// returns 8 KiB per fread(), production
	 * reads whole 1 MiB checksum blocks.
	 */
	private function runPlain( CrcStep $step, Job $job ): StepResult {
		Stream::unwrap();
		try {
			return $step->run( $job, new CountdownDeadline( 0 ) );
		} finally {
			Stream::wrap();
		}
	}

	private function archive( string $name, string $multisite ): string {
		$path   = $this->dir . '/' . $name;
		$writer = new Writer( $path );
		$writer->addString( 'package.json', '{"SiteURL":"https://net.test"}', 1 );
		$writer->addString( 'multisite.json', $multisite, 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		return $path;
	}

	private function fileJob( string $location ): Job {
		return ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'file', 'location' => $location ] );
	}

	private function expectFailure( SourceStep $step, Job $job, string $name ): void {
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'An unusable archive source was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( $name, $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
		}
	}
}
