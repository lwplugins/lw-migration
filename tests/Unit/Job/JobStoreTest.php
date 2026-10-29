<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class JobStoreTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_create_save_load_delete(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $store->create( 'export', [ 'a', 'b' ], [ 'no_media' => true ], 1000 );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $job->id );
		$this->assertDirectoryExists( $store->dir( $job->id ) );
		$this->assertSame( 1000, $job->createdAt );

		$job->stepIndex = 1;
		$store->save( $job );
		$this->assertSame( 1, $store->load( $job->id )->stepIndex );
		$this->assertSame( [ $job->id ], $store->ids() );

		$store->delete( $job->id );
		$this->assertDirectoryDoesNotExist( $store->dir( $job->id ) );
	}

	public function test_invalid_id_is_rejected(): void {
		$this->expectException( JobException::class );
		( new JobStore( $this->dir ) )->load( '../etc' );
	}

	public function test_missing_job_is_rejected(): void {
		$this->expectException( JobException::class );
		( new JobStore( $this->dir ) )->load( str_repeat( 'a', 16 ) );
	}

	public function test_lock_is_exclusive(): void {
		$store = new JobStore( $this->dir );
		$job   = $store->create( 'export', [ 'a' ], [], 1 );
		$lock  = $store->acquire( $job->id );
		$this->assertNotNull( $lock );
		$this->assertNull( $store->acquire( $job->id ) );
		$store->release( $lock );
		$again = $store->acquire( $job->id );
		$this->assertNotNull( $again );
		$store->release( $again );
	}

	public function test_save_fails_on_non_utf8_data(): void {
		$store        = new JobStore( $this->dir );
		$job          = $store->create( 'export', [ 'a' ], [], 1 );
		$job->cursor  = [ 'bad' => "\xff" ];
		$this->expectException( JobException::class );
		$store->save( $job );
	}

	public function test_trailing_newline_id_is_rejected(): void {
		$this->expectException( JobException::class );
		( new JobStore( $this->dir ) )->dir( str_repeat( 'a', 16 ) . "\n" );
	}

	public function test_acquire_and_save_on_missing_job_throw(): void {
		$store = new JobStore( $this->dir );
		$id    = str_repeat( 'b', 16 );
		try {
			$store->acquire( $id );
			$this->fail( 'acquire should throw' );
		} catch ( JobException $e ) {
			$this->assertStringContainsString( 'not found', $e->getMessage() );
		}
		$this->expectException( JobException::class );
		$store->save( new \LightweightPlugins\Migration\Job\Job( $id, 'export', [] ) );
	}

	public function test_delete_stops_at_a_folder_it_cannot_list(): void {
		$store  = new JobStore( $this->dir . '/jobs' );
		$job    = $store->create( 'export', [ 'a' ], [], 1000 );
		$locked = $store->dir( $job->id ) . '/locked';
		mkdir( $locked );
		chmod( $locked, 0300 );
		$warnings = [];
		set_error_handler(
			static function ( int $errno, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;
				return true;
			}
		);
		try {
			$store->delete( $job->id );
		} finally {
			restore_error_handler();
			chmod( $locked, 0755 );
		}

		$this->assertDirectoryExists( $locked );
		$this->assertSame( [], $warnings, 'An unlistable folder is left in place quietly, never walked.' );
	}

	public function test_folders_lists_every_id_folder_with_or_without_job_json(): void {
		$store = new JobStore( $this->dir );
		$job   = $store->create( 'export', [ 'a' ], [], 1 );
		mkdir( $this->dir . '/0123456789abcdef' );
		mkdir( $this->dir . '/notajob' );
		mkdir( $this->dir . '/target' );
		symlink( $this->dir . '/target', $this->dir . '/fedcba9876543210' );
		file_put_contents( $this->dir . '/aaaaaaaaaaaaaaaa', 'a file, not a folder' );

		$folders = $store->folders();
		sort( $folders );
		$expected = [ '0123456789abcdef', $job->id ];
		sort( $expected );

		$this->assertSame( $expected, $folders );
		$this->assertSame( [], ( new JobStore( $this->dir . '/missing' ) )->folders() );
	}
}
