<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ProtectedTargets;
use LightweightPlugins\Migration\Import\UploadRoutes;
use LightweightPlugins\Migration\Import\Steps\ContentStep;
use LightweightPlugins\Migration\Import\Steps\DatabaseFileStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ContentStepTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

	private const PASSWORD = 'lw-fixture-pass';

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

	private function step(): ContentStep {
		$storage = $this->dir . '/wp-content/lw-migration-0123456789ab';
		return new ContentStep(
			$this->store,
			static function ( Job $job, string $staging ) use ( $storage ): ContentRules {
				$site = ImportState::site( $job );
				return new ContentRules(
					[
						'content' => $site->contentDir,
						'plugins' => $site->pluginsDir,
						'themes'  => $site->themeRoot,
						'uploads' => $site->uploadsDir,
						'staging' => $staging,
					],
					[ 'object-cache.php' ],
					UploadRoutes::forSubsite( ImportState::subsiteId( $job ) ),
					ProtectedTargets::forSite( $site, $storage )
				);
			}
		);
	}

	/** @param array<string, mixed> $secrets */
	private function job( string $archive, array $secrets = [] ): Job {
		$job                     = ImportJobFixture::make( $this->store, $this->dir, $archive, [], $secrets );
		$job->state['package']   = PackageCodec::read( $archive )->toArray();
		$job->state['subsiteId'] = null;
		return $job;
	}

	/** An encrypted job whose package.json carries no signature (the password cannot be checked up front). */
	private function unsignedJob( string $archive, string $password ): Job {
		$job = $this->job( $archive, [ 'password' => $password ] );
		unset( $job->state['package']['EncryptedSignature'] );
		return $job;
	}

	/** @param array<string, string> $entries Entry => contents. */
	private function archive( array $entries, ?string $password = null ): string {
		$path   = $this->dir . '/crafted.wpress';
		$codec  = null === $password ? null : new ChunkCodec( Codec::NONE, new Cipher( $password ) );
		$writer = new Writer( $path, $codec );
		$writer->addString( 'package.json', null === $password ? '{"SiteURL":"https://old.test"}' : '{"SiteURL":"https://old.test","Encrypted":true}', 1 );
		foreach ( $entries as $name => $data ) {
			$writer->addString( $name, $data, 1 );
		}
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		return $path;
	}

	private function runAll( Job $job ): void {
		$runs = 0;
		do {
			$result = $this->step()->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && null === $result->needsInputKind() && $runs < 100 );
		$this->assertTrue( $result->isDone() );
	}

	/** @return array<int, string> Temporary extraction files below $dir. */
	private function temps( string $dir ): array {
		$found = [];
		if ( ! is_dir( $dir ) ) {
			return $found;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( TempName::is( $file->getFilename() ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	public function test_restores_the_plain_fixture_byte_for_byte(): void {
		$job = $this->job( self::FIXTURES . 'plain.wpress' );
		$this->runAll( $job );
		$content = $this->dir . '/wp-content';

		$this->assertSame( 'fa6f554637c530ce8d4ba5d772065fe5d7bccd68', sha1_file( $content . '/hello.txt' ) );
		$this->assertSame( 0, filesize( $content . '/empty.txt' ) );
		$this->assertSame( 'cde6167f0765250c89f29e06d15deaf938c44430', sha1_file( $content . '/uploads/2026/10/ékezetes fájl.txt' ) );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $content . '/plugins/sample/big.bin' ) );
		$this->assertFileDoesNotExist( $content . '/database.sql' );
		$this->assertIsInt( $job->state['databaseEntry'] );
		$this->assertSame( [], $this->temps( $content ) );
		$this->assertSame( [], $job->warnings );

		$this->assertTrue( ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( "-- fixture dump\nSELECT 1;\n", file_get_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE ) );
		$this->assertTrue( ImportState::hasDatabase( $job ) );
	}

	public function test_encrypted_archive_asks_for_its_password(): void {
		$job    = $this->job( self::FIXTURES . 'encrypted-gzip.wpress' );
		$result = $this->step()->run( $job, new NoDeadline() );
		$this->assertSame( 'password', $result->needsInputKind() );
		$this->assertSame( [ 'reason' => 'encrypted' ], $result->inputData() );
		$job->secrets = [ 'password' => self::PASSWORD ];
		$this->runAll( $job );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $this->dir . '/wp-content/plugins/sample/big.bin' ) );
		$this->assertTrue( ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( "-- fixture dump\nSELECT 1;\n", file_get_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE ) );
	}

	public function test_unsigned_encrypted_archive_checks_the_password_before_writing(): void {
		$job    = $this->unsignedJob( self::FIXTURES . 'encrypted.wpress', 'not-the-password' );
		$result = $this->step()->run( $job, new NoDeadline() );
		$this->assertSame( 'password', $result->needsInputKind() );
		$this->assertSame( [ 'reason' => 'wrong' ], $result->inputData() );
		$this->assertDirectoryDoesNotExist( $this->dir . '/wp-content', 'Nothing is written with a wrong password.' );
		$this->assertArrayNotHasKey( 'passwordTrial', $job->state );

		$job->secrets = [ 'password' => self::PASSWORD ];
		$this->runAll( $job );
		$this->assertSame( 'fa6f554637c530ce8d4ba5d772065fe5d7bccd68', sha1_file( $this->dir . '/wp-content/hello.txt' ) );
		$this->assertTrue( ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );

		// A password typed again (after the secret was lost) is checked again, also by the database step.
		$job->secrets = [];
		$this->assertSame( [ 'reason' => 'encrypted' ], ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->inputData() );
		$job->secrets = [ 'password' => 'wrong-again' ];
		$this->assertSame( [ 'reason' => 'wrong' ], ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->inputData() );
	}

	public function test_unsigned_password_check_reads_the_start_of_a_large_dump(): void {
		$dump    = "-- dump\n" . str_repeat( "INSERT INTO t VALUES (1);\n", 25000 );
		$archive = $this->archive( [ 'a.txt' => 'a', 'database.sql' => $dump ], self::PASSWORD );

		$wrong = $this->unsignedJob( $archive, 'nope' );
		$this->assertSame( [ 'reason' => 'wrong' ], $this->step()->run( $wrong, new NoDeadline() )->inputData() );
		$this->assertFileDoesNotExist( $this->dir . '/wp-content/a.txt' );

		$right = $this->unsignedJob( $archive, self::PASSWORD );
		$this->runAll( $right );
		$this->assertSame( 'a', file_get_contents( $this->dir . '/wp-content/a.txt' ) );
	}

	public function test_unsigned_password_check_without_a_database_uses_an_entry_checksum(): void {
		$archive = $this->archive( [ 'empty.txt' => '', 'a.txt' => str_repeat( 'a', 600000 ), 'b.txt' => 'b' ], self::PASSWORD );

		$wrong = $this->unsignedJob( $archive, 'nope' );
		$this->assertSame( [ 'reason' => 'wrong' ], $this->step()->run( $wrong, new NoDeadline() )->inputData() );
		$this->assertDirectoryDoesNotExist( $this->dir . '/wp-content' );
		$this->assertSame( [], $this->temps( $this->store->dir( $wrong->id ) ) );

		$right = $this->unsignedJob( $archive, self::PASSWORD );
		$this->runAll( $right );
		$this->assertSame( 'b', file_get_contents( $this->dir . '/wp-content/b.txt' ) );
		$this->assertSame( [], $this->temps( $this->store->dir( $right->id ) ) );
		$this->assertFileDoesNotExist( $this->store->dir( $right->id ) . '/password-trial' );
	}

	public function test_unsafe_entries_are_skipped_and_mu_plugins_are_staged(): void {
		$job = $this->job(
			$this->archive(
				[
					'../evil.txt'                      => 'evil',
					'mu-plugins/custom.php'            => '<?php // custom',
					'mu-plugins/gd-system-plugin.php'  => '<?php // host',
					'object-cache.php'                 => '<?php // drop-in',
					'uploads/elementor/css/post-1.css' => 'css',
					'plugins/lw-migration/x.php'       => 'old copy',
					'themes/t/style.css'               => '/* t */',
					'wp-config.php'                    => '<?php // old server',
					'ai1wm-backups/old.wpress'         => 'backup',
				]
			)
		);
		$this->runAll( $job );
		$content = $this->dir . '/wp-content';
		$staging = $this->store->dir( $job->id ) . '/' . ImportFiles::STAGING;

		$this->assertFileDoesNotExist( $this->dir . '/evil.txt' );
		$this->assertFileExists( $staging . '/custom.php' );
		$this->assertFileDoesNotExist( $content . '/mu-plugins/custom.php', 'Never written to mu-plugins before the swap.' );
		$this->assertFileDoesNotExist( $staging . '/gd-system-plugin.php' );
		$this->assertFileDoesNotExist( $content . '/object-cache.php' );
		$this->assertFileDoesNotExist( $content . '/uploads/elementor/css/post-1.css' );
		$this->assertFileDoesNotExist( $content . '/plugins/lw-migration/x.php' );
		$this->assertFileDoesNotExist( $content . '/wp-config.php' );
		$this->assertFileDoesNotExist( $content . '/ai1wm-backups/old.wpress' );
		$this->assertSame( '/* t */', file_get_contents( $content . '/themes/t/style.css' ) );
		$warnings = implode( "\n", $job->warnings );
		foreach ( [ 'restored safely', 'drop-in', 'hosting', 'generated', 'LW Migration', 'wp-config.php', 'backup and storage' ] as $needle ) {
			$this->assertStringContainsString( $needle, $warnings );
		}
		$this->assertCount( 7, $job->warnings, 'One summary warning per category.' );
		$this->assertStringNotContainsString( $this->dir, $warnings );
	}

	public function test_a_symlink_at_the_target_is_skipped_with_a_warning(): void {
		mkdir( $this->dir . '/wp-content', 0755, true );
		file_put_contents( $this->dir . '/outside.txt', 'keep' );
		symlink( $this->dir . '/outside.txt', $this->dir . '/wp-content/a.txt' );
		$job = $this->job( $this->archive( [ 'a.txt' => 'overwrite', 'b.txt' => 'b' ] ) );
		$this->runAll( $job );

		$this->assertSame( 'keep', file_get_contents( $this->dir . '/outside.txt' ) );
		$this->assertTrue( is_link( $this->dir . '/wp-content/a.txt' ) );
		$this->assertSame( 'b', file_get_contents( $this->dir . '/wp-content/b.txt' ) );
		$this->assertSame( [ 'Skipped an unsafe path in the archive: a.txt' ], $job->warnings );
	}

	public function test_damaged_entry_removes_the_partial_file(): void {
		$path = $this->archive( [ 'good.txt' => 'good', 'bad.txt' => str_repeat( 'b', 100 ) ] );
		$h    = fopen( $path, 'r+b' );
		fseek( $h, -4377 - 10, SEEK_END );
		fwrite( $h, 'X' );
		fclose( $h );
		mkdir( $this->dir . '/wp-content', 0755, true );
		file_put_contents( $this->dir . '/wp-content/kept.txt', 'live' );
		$job = $this->job( $path );
		try {
			( $this->step() )->run( $job, new NoDeadline() );
			$this->fail( 'Damage not detected.' );
		} catch ( CorruptedEntryException $e ) {
			$this->assertStringContainsString( 'bad.txt', $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			$this->assertFileDoesNotExist( $this->dir . '/wp-content/bad.txt' );
			$this->assertFileExists( $this->dir . '/wp-content/good.txt' );
			$this->assertSame( [], $this->temps( $this->dir . '/wp-content' ) );
			$this->assertTrue( ContentStep::filesPlaced( $job ), 'good.txt is on the site.' );
		}
	}

	public function test_damaged_entry_keeps_the_live_file(): void {
		$path = $this->archive( [ 'bad.txt' => str_repeat( 'b', 100 ) ] );
		$h    = fopen( $path, 'r+b' );
		fseek( $h, -4377 - 10, SEEK_END );
		fwrite( $h, 'X' );
		fclose( $h );
		mkdir( $this->dir . '/wp-content', 0755, true );
		file_put_contents( $this->dir . '/wp-content/bad.txt', 'live copy' );
		$job = $this->job( $path );
		try {
			( $this->step() )->run( $job, new NoDeadline() );
			$this->fail( 'Damage not detected.' );
		} catch ( CorruptedEntryException $e ) {
			$this->assertSame( 'live copy', file_get_contents( $this->dir . '/wp-content/bad.txt' ), 'A damaged entry never deletes the live file before the swap.' );
			$this->assertSame( [], $this->temps( $this->dir . '/wp-content' ) );
			$this->assertFalse( ContentStep::filesPlaced( $job ), 'The folder was journaled, but no file was put in place.' );
		}
	}

	public function test_a_large_entry_resumes_from_its_temporary_file(): void {
		$job    = $this->job( self::FIXTURES . 'plain.wpress' );
		$target = $this->dir . '/wp-content/plugins/sample/big.bin';
		$runs   = 0;
		while ( ! isset( $job->cursor['extract'] ) && $runs++ < 20 ) {
			$this->assertFalse( $this->step()->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		}
		$this->assertSame( 'plugins/sample/big.bin', $job->cursor['name'] );
		$this->assertSame( realpath( $this->dir . '/wp-content/plugins' ) . '/sample/big.bin', $job->cursor['target'] );
		$temp = (string) $job->cursor['extract']['tempPath'];
		$this->assertFileExists( $temp );
		$this->assertSame( dirname( (string) realpath( $temp ) ), (string) realpath( dirname( $target ) ) );
		$this->assertFileDoesNotExist( $target );

		$this->runAll( $job );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $target ) );
		$this->assertFileDoesNotExist( $temp );
		$this->assertArrayNotHasKey( 'extract', $job->cursor );
		$this->assertArrayNotHasKey( 'target', $job->cursor );
	}

	/** Runs the step until an entry is in progress; returns the cursor from before the last run. */
	private function untilInProgress( Job $job ): array {
		$runs = 0;
		do {
			$before = $job->cursor;
			$this->step()->run( $job, new CountdownDeadline( 0 ) );
		} while ( ! isset( $job->cursor['extract'] ) && $runs++ < 20 );
		$this->assertArrayHasKey( 'extract', $job->cursor );
		return $before;
	}

	public function test_a_missing_temporary_file_restarts_the_entry(): void {
		$job = $this->job( self::FIXTURES . 'plain.wpress' );
		$this->untilInProgress( $job );
		unlink( (string) $job->cursor['extract']['tempPath'] );
		$this->runAll( $job );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $this->dir . '/wp-content/plugins/sample/big.bin' ) );
		$this->assertSame( [], $this->temps( $this->dir . '/wp-content' ) );
	}

	public function test_a_finished_entry_whose_progress_was_lost_is_extracted_again(): void {
		$job = $this->job( self::FIXTURES . 'plain.wpress' );
		$this->untilInProgress( $job );
		$saved = $job->cursor;
		$this->step()->run( $job, new CountdownDeadline( 0 ) );
		$this->assertArrayNotHasKey( 'extract', $job->cursor, 'The entry was renamed into place.' );
		$job->cursor = $saved; // The request died before the cursor was saved.

		$this->runAll( $job );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $this->dir . '/wp-content/plugins/sample/big.bin' ) );
		$this->assertSame( [], $this->temps( $this->dir . '/wp-content' ) );
	}

	public function test_orphaned_temporary_files_are_swept_when_the_files_are_restored(): void {
		$job            = $this->job( self::FIXTURES . 'plain.wpress' );
		$job->createdAt = time() - 60;
		$before         = $this->untilInProgress( $job );
		$orphan         = (string) $job->cursor['extract']['tempPath'];
		touch( $orphan, 1700000000 ); // Even with the archive's entry time (stamped just before a rename).
		$job->cursor = $before; // The request died before the cursor naming the new temporary file was saved.

		$this->runAll( $job );
		$this->assertFileDoesNotExist( $orphan );
		$this->assertSame( [], $this->temps( $this->dir . '/wp-content' ) );
		$this->assertSame( '305e6b98ac1203bebf8c7b568472df01e9d8f9ef', sha1_file( $this->dir . '/wp-content/plugins/sample/big.bin' ) );
	}

	public function test_a_changed_temporary_file_stops_the_import(): void {
		$job = $this->job( self::FIXTURES . 'plain.wpress' );
		$this->untilInProgress( $job );
		$live                               = $this->dir . '/wp-content/hello.txt';
		$job->cursor['extract']['tempPath'] = $live;
		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'A temporary file that is not ours must stop the import.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'plugins/sample/big.bin', $e->getMessage() );
			$this->assertStringContainsString( 'changed by something else', $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			$this->assertSame( 'fa6f554637c530ce8d4ba5d772065fe5d7bccd68', sha1_file( $live ) );
			$this->assertSame( [], $job->warnings );
		}
	}

	public function test_a_work_folder_failure_in_the_password_check_names_no_path(): void {
		$archive = $this->archive( [ 'a.txt' => 'a' ], self::PASSWORD );
		$job     = $this->unsignedJob( $archive, self::PASSWORD );
		$work    = $this->store->dir( $job->id );
		chmod( $work, 0500 );
		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'An unwritable work folder must stop the import.' );
		} catch ( ImportException $e ) {
			$this->assertStringNotContainsString( $this->dir, $e->getMessage() );
			$this->assertDirectoryDoesNotExist( $this->dir . '/wp-content' );
		} finally {
			chmod( $work, 0700 );
		}
	}

	public function test_database_file_restarts_when_its_progress_was_lost(): void {
		$dump = "-- dump\n" . str_repeat( "INSERT INTO t VALUES (1);\n", 25000 );
		$job  = $this->job( $this->archive( [ 'database.sql' => $dump ] ) );
		$this->runAll( $job );
		$step = new DatabaseFileStep( $this->store );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$saved = $job->cursor;
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$job->cursor = $saved; // The request died after the rename, before the cursor was saved.
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $dump, file_get_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE ) );
		$this->assertSame( [], $this->temps( $this->store->dir( $job->id ) ) );
	}

	public function test_resume_refuses_a_different_entry(): void {
		$job = $this->job( self::FIXTURES . 'plain.wpress' );
		$this->assertFalse( $this->step()->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$job->cursor['name'] = 'something-else.txt';
		$this->expectException( ImportException::class );
		$this->step()->run( $job, new NoDeadline() );
	}

	public function test_a_refused_resume_discards_the_temporary_file(): void {
		$job  = $this->job( self::FIXTURES . 'plain.wpress' );
		$runs = 0;
		while ( ! isset( $job->cursor['extract'] ) && $runs++ < 20 ) {
			$this->step()->run( $job, new CountdownDeadline( 0 ) );
		}
		$temp                = (string) $job->cursor['extract']['tempPath'];
		$job->cursor['name'] = 'something-else.txt';
		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'A different entry must be refused.' );
		} catch ( ImportException $e ) {
			$this->assertFileDoesNotExist( $temp );
		}
	}

	public function test_archive_without_database(): void {
		$job = $this->job( $this->archive( [ 'a.txt' => 'a' ] ) );
		$this->runAll( $job );
		$this->assertTrue( ( new DatabaseFileStep( $this->store ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertFalse( ImportState::hasDatabase( $job ) );
	}

	public function test_database_file_resumes_and_refuses_a_different_entry(): void {
		$dump    = "-- dump\n" . str_repeat( "INSERT INTO t VALUES (1);\n", 25000 );
		$archive = $this->archive( [ 'a.txt' => 'a', 'database.sql' => $dump ] );
		$job     = $this->job( $archive );
		$this->runAll( $job );
		$step = new DatabaseFileStep( $this->store );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $dump, file_get_contents( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE ) );

		$job->state['databaseEntry'] = 0;
		$this->expectException( ImportException::class );
		$step->run( $job, new NoDeadline() );
	}
}
