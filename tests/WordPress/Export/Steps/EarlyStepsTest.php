<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Export\EnumCursor;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Export\Steps\ConfigStep;
use LightweightPlugins\Migration\Export\Steps\EnumerateStep;
use LightweightPlugins\Migration\Export\Steps\InitStep;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\SiteTree;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class EarlyStepsTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		SiteTree::create( $this->dir );
		Functions\when( 'size_format' )->alias( static fn( $bytes ) => $bytes . ' B' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function enumerateStep(): EnumerateStep {
		return new EnumerateStep( $this->store, $this->dir . '/wp-content/lw-migration-0123456789ab', static fn( string $dir ): ?int => 10, static fn(): int => 1000 );
	}

	public function test_init_requires_the_password_of_an_encrypted_backup(): void {
		$this->assertTrue( ( new InitStep() )->run( JobFixture::export( $this->store, $this->dir ), new NoDeadline() )->isDone() );
		$this->assertTrue( ( new InitStep() )->run( JobFixture::export( $this->store, $this->dir, [ 'encrypt' => true ], [ 'password' => 'secret' ] ), new NoDeadline() )->isDone() );
		$this->expectException( ExportException::class );
		( new InitStep() )->run( JobFixture::export( $this->store, $this->dir, [ 'encrypt' => true ] ), new NoDeadline() );
	}

	public function test_init_fails_with_a_translated_message_without_a_site_snapshot(): void {
		$job = JobFixture::export( $this->store, $this->dir );
		unset( $job->state['site'] );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:The job has no site snapshot.' );
		( new InitStep() )->run( $job, new NoDeadline() );
	}

	public function test_config_writes_package_json_first(): void {
		file_put_contents( $this->dir . '/.htaccess', "RewriteEngine On\n" );
		$job  = JobFixture::export( $this->store, $this->dir, [ 'compression' => 'gzip' ] );
		$step = new ConfigStep( $this->store, static fn(): string => '8.0.36', static fn(): int => 1767225600 );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone(), 'A retry rewrites the archive from scratch.' );

		$archive = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		$this->assertSame( filesize( $archive ), $job->state['archiveLength'] );
		$reader  = new Reader( $archive );
		$first   = $reader->firstEntry();
		$package = PackageConfig::fromJson( $reader->readContents( $first ) );
		$reader->close();
		$this->assertSame( 'package.json', $first->name );
		$this->assertSame( 1767225600, $first->mtime, 'The entry time comes from the injected clock.' );
		$this->assertSame( 'gzip', $package->compression() );
		$this->assertSame( "RewriteEngine On\n", base64_decode( (string) $package->toArray()['Server']['.htaccess'] ) );
		$this->assertSame( $package->toArray(), $job->state['package'] );
	}

	public function test_config_creates_an_owner_only_archive_whatever_the_umask(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'POSIX permissions only.' );
		}
		$job   = JobFixture::export( $this->store, $this->dir );
		$umask = umask( 0 );
		try {
			( new ConfigStep( $this->store, static fn(): string => '8.0.36', static fn(): int => 1 ) )->run( $job, new NoDeadline() );
		} finally {
			umask( $umask );
		}
		$archive = $this->store->dir( $job->id ) . '/' . ExportFiles::ARCHIVE;
		clearstatcache( true, $archive );

		$this->assertSame( 0600, fileperms( $archive ) & 0777 );
	}

	public function test_enumerate_lists_files_resumably_and_warns_on_low_disk_space(): void {
		$job  = JobFixture::export( $this->store, $this->dir, [ 'noInactivePlugins' => true ] );
		$step = $this->enumerateStep();
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$entries = [];
		foreach ( (array) file( $this->store->dir( $job->id ) . '/' . ExportFiles::LIST ) as $line ) {
			$entries[] = FileEnumerator::parseLine( (string) $line )['entry'];
		}
		$this->assertSame(
			[ 'index.php', 'mu-plugins/host.php', 'uploads/2026/10/photo.txt', 'plugins/sample/sample.php', 'themes/old-theme/style.css', 'themes/twentytwentyfive/style.css' ],
			$entries
		);
		$this->assertSame( 6, $job->state['files']['count'] );
		$this->assertStringContainsString( 'only 10 B is free', implode( ' ', $job->warnings ) );
	}

	public function test_a_backups_folder_on_another_disk_is_checked_for_free_space_too(): void {
		$storage = $this->dir . '/wp-content/lw-migration-0123456789ab';
		$free    = static fn( string $dir ): ?int => $dir === $storage ? PHP_INT_MAX : 10;
		$other   = new EnumerateStep( $this->store, $storage, $free, static fn(): int => 1000, '/mnt/backups', static fn( string $dir ): ?int => $dir === $storage ? 1 : 2 );
		$same    = new EnumerateStep( $this->store, $storage, $free, static fn(): int => 1000, '/mnt/backups', static fn( string $dir ): ?int => 1 );
		$unknown = new EnumerateStep( $this->store, $storage, $free, static fn(): int => 1000, '/mnt/backups', static fn( string $dir ): ?int => $dir === $storage ? 1 : null );

		$warnings = [];
		foreach ( [ 'other' => $other, 'same' => $same, 'unknown' => $unknown ] as $case => $step ) {
			$job = JobFixture::export( $this->store, $this->dir );
			$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
			$warnings[ $case ] = implode( ' ', $job->warnings );
		}

		$this->assertStringContainsString( 'The backups folder is on another disk', $warnings['other'] );
		$this->assertStringContainsString( 'only 10 B is free there', $warnings['other'] );
		$this->assertSame( '', $warnings['same'], 'One disk: the copy into the folder is a link, no second copy.' );
		$this->assertSame( '', $warnings['unknown'] );
	}

	public function test_a_dangling_link_is_reported_by_its_entry_name(): void {
		symlink( $this->dir . '/nowhere', $this->dir . '/wp-content/plugins/sample/broken.php' );
		$job = JobFixture::export( $this->store, $this->dir );

		$this->assertTrue( $this->enumerateStep()->run( $job, new NoDeadline() )->isDone() );

		$warning = $job->warnings[0];
		$this->assertStringContainsString( 'target does not exist', $warning );
		$this->assertStringEndsWith( ': plugins/sample/broken.php', $warning );
		$this->assertStringNotContainsString( $this->dir, implode( ' ', $job->warnings ) );
	}

	public function test_a_failed_run_keeps_the_saved_cursor(): void {
		$job         = JobFixture::export( $this->store, $this->dir );
		$saved       = ( new EnumCursor( 0, true, [ 'uploads' ], 100 ) )->toArray();
		$job->cursor = $saved;

		try {
			$this->enumerateStep()->run( $job, new NoDeadline() );
			$this->fail( 'A list shorter than the saved progress must fail.' );
		} catch ( ExportException $e ) {
			$this->assertSame( $saved, $job->cursor );
		}
	}
}
