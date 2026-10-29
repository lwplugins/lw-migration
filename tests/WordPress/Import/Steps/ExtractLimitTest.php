<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\WriteBudget;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\ExtractBudget;
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

/**
 * Issue #9: everything an import extracts (files, database, password trial)
 * counts against one limit set from the free disk space at its first byte.
 */
final class ExtractLimitTest extends MonkeyTestCase {

	private const PASSWORD = 'lw-fixture-pass';

	private string $dir;

	private JobStore $store;

	/** Folders the free space was asked for. @var array<int, string> */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/** Free space that leaves room for exactly $room bytes, and records every question. */
	private function freeSpace( int $room ): \Closure {
		return function ( string $dir ) use ( $room ): int {
			$this->asked[] = $dir;
			return WriteBudget::MIN_MARGIN + $room;
		};
	}

	private function content( \Closure $freeSpace ): ContentStep {
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
					[],
					UploadRoutes::forSubsite( ImportState::subsiteId( $job ) ),
					ProtectedTargets::forSite( $site, $storage )
				);
			},
			$freeSpace
		);
	}

	/** @param array<string, string> $secrets */
	private function job( string $archive, array $secrets = [] ): Job {
		$job                     = ImportJobFixture::make( $this->store, $this->dir, $archive, [], $secrets );
		$job->state['package']   = PackageCodec::read( $archive )->toArray();
		$job->state['subsiteId'] = null;
		return $job;
	}

	/** @param array<string, string> $entries Entry => contents. */
	private function archive( array $entries, ?ChunkCodec $codec = null, string $package = '{"SiteURL":"https://old.test"}' ): string {
		$path   = $this->dir . '/crafted.wpress';
		$writer = new Writer( $path, $codec );
		$writer->addString( 'package.json', $package, 1 );
		foreach ( $entries as $name => $data ) {
			$writer->addString( $name, $data, 1 );
		}
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();
		return $path;
	}

	/** @return array<int, string> Temporary extraction files below wp-content. */
	private function temps(): array {
		$found = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir . '/wp-content', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( TempName::is( $file->getFilename() ) ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	public function test_files_past_the_limit_stop_the_import_and_leave_no_temporary_file(): void {
		$job = $this->job( $this->archive( [ 'a.txt' => str_repeat( 'a', 1000 ), 'b.txt' => str_repeat( 'b', 1000 ) ] ) );

		try {
			$this->content( $this->freeSpace( 1500 ) )->run( $job, new NoDeadline() );
			$this->fail( 'Expected an ImportException.' );
		} catch ( ImportException $e ) {
			$this->assertSame( 'The import stopped: the archive holds more data than the 1500 B the free disk space allows it to write. Free up disk space and try again.', $e->getMessage() );
		}
		$this->assertFileExists( $this->dir . '/wp-content/a.txt' );
		$this->assertFileDoesNotExist( $this->dir . '/wp-content/b.txt' );
		$this->assertSame( [], $this->temps() );
		$this->assertSame( [ 'limit' => 1500, 'written' => 1000 ], $job->state[ ExtractBudget::STATE ] );
	}

	public function test_the_free_space_is_read_once_and_the_total_is_kept_between_requests(): void {
		$data = random_bytes( 3 * Format::CHUNK_SIZE );
		$job  = $this->job( $this->archive( [ 'a.bin' => $data, 'b.bin' => $data ] ) );
		$step = $this->content( $this->freeSpace( 6 * Format::CHUNK_SIZE ) );
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 2, $runs );
		$this->assertSame( [ $this->dir . '/wp-content', $this->store->dir( $job->id ) ], $this->asked, 'Asked on the first request only.' );
		$this->assertSame( 6 * Format::CHUNK_SIZE, $job->state[ ExtractBudget::STATE ]['written'] );
	}

	public function test_restoring_files_over_equal_ones_needs_room_for_one_file_at_a_time_not_for_all(): void {
		$files = [];
		mkdir( $this->dir . '/wp-content', 0755, true );
		foreach ( [ 'a.bin', 'b.bin', 'c.bin' ] as $name ) {
			$files[ $name ] = random_bytes( 2 * Format::CHUNK_SIZE );
			file_put_contents( $this->dir . '/wp-content/' . $name, random_bytes( 2 * Format::CHUNK_SIZE ) );
		}
		$job  = $this->job( $this->archive( $files ) );
		$step = $this->content( $this->freeSpace( 3 * Format::CHUNK_SIZE ) );
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$this->assertTrue( $result->isDone(), 'Six chunks in all, three of room: each file fits beside the one it replaces.' );
		$this->assertGreaterThan( 3, $runs, 'The replaced size is kept between requests.' );
		foreach ( $files as $name => $data ) {
			$this->assertSame( $data, file_get_contents( $this->dir . '/wp-content/' . $name ) );
		}
		$this->assertSame( 0, $job->state[ ExtractBudget::STATE ]['written'] );
	}

	public function test_the_database_counts_against_the_same_total(): void {
		$job  = $this->job( $this->archive( [ 'a.txt' => str_repeat( 'a', 600 ), 'database.sql' => "-- dump\n" . str_repeat( 'x', 600 ) ] ) );
		$free = $this->freeSpace( 1000 );
		$this->assertTrue( $this->content( $free )->run( $job, new NoDeadline() )->isDone() );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'the archive holds more data than the 1000 B' );
		try {
			( new DatabaseFileStep( $this->store, $free ) )->run( $job, new NoDeadline() );
		} finally {
			$this->assertFileDoesNotExist( $this->store->dir( $job->id ) . '/' . ImportFiles::DATABASE );
		}
	}

	public function test_the_password_trial_is_held_to_the_limit(): void {
		$archive = $this->archive( [ 'a.txt' => 'a' ], new ChunkCodec( Codec::NONE, new Cipher( self::PASSWORD ) ), '{"SiteURL":"https://old.test","Encrypted":true}' );
		$job     = $this->job( $archive, [ 'password' => self::PASSWORD ] );
		unset( $job->state['package']['EncryptedSignature'] );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'the archive holds more data than the 0 B' );
		$this->content( $this->freeSpace( 0 ) )->run( $job, new NoDeadline() );
	}

	public function test_the_password_trial_does_not_use_up_the_limit(): void {
		$archive = $this->archive( [ 'a.txt' => str_repeat( 'a', 100 ) ], new ChunkCodec( Codec::NONE, new Cipher( self::PASSWORD ) ), '{"SiteURL":"https://old.test","Encrypted":true}' );
		$job     = $this->job( $archive, [ 'password' => self::PASSWORD ] );
		unset( $job->state['package']['EncryptedSignature'] );

		$this->assertTrue( $this->content( $this->freeSpace( 100 ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( str_repeat( 'a', 100 ), file_get_contents( $this->dir . '/wp-content/a.txt' ) );
		$this->assertSame( 100, $job->state[ ExtractBudget::STATE ]['written'] );
	}
}
