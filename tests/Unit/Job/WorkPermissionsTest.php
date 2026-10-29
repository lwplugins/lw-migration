<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Export\ListFile;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobLog;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Job folders are owner-only (0700), job work files 0600, whatever the umask;
 * backups keep the default permissions.
 */
final class WorkPermissionsTest extends TestCase {

	private string $dir;

	private int $umask;

	protected function setUp(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'POSIX permissions only.' );
		}
		$this->dir   = TempDir::create();
		$this->umask = umask( 0 );
	}

	protected function tearDown(): void {
		umask( $this->umask );
		TempDir::remove( $this->dir );
	}

	private static function mode( string $path ): int {
		clearstatcache( true, $path );
		return fileperms( $path ) & 0777;
	}

	public function test_storage_jobs_folder_is_and_backups_are_owner_only(): void {
		$storage = new StorageDir( $this->dir . '/lw-migration-0123456789ab' );
		$storage->ensure();

		$this->assertSame( 0700, self::mode( $storage->jobsDir() ) );
		$this->assertSame( 0700, self::mode( $storage->backupsDir() ) );
		$this->assertSame( 0700, self::mode( $storage->root() ) );
	}

	public function test_job_folders_and_files_are_owner_only(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $store->create( 'export', [ 'a' ], [], 1 );
		$dir   = $store->dir( $job->id );
		$lock  = $store->acquire( $job->id );
		$this->assertNotNull( $lock );
		$store->release( $lock );
		JobLog::append( $dir, 1, 'line' );
		$job->state['workSub'] = 'safety';
		$sub                   = WorkDir::path( $store, $job );
		fclose( ListFile::open( $sub . '/files.list', 0 ) );

		$this->assertSame( 0700, self::mode( $dir ) );
		$this->assertSame( 0700, self::mode( $sub ) );
		foreach ( [ $dir . '/job.json', $dir . '/lock', $dir . '/' . JobLog::FILE, $sub . '/files.list' ] as $file ) {
			$this->assertSame( 0600, self::mode( $file ), basename( $file ) );
		}
	}
}
