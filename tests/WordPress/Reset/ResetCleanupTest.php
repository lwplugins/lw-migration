<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Reset\ResetCleanup;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ResetCleanupTest extends MonkeyTestCase {

	public function test_a_failed_reset_loses_the_backup_temp_and_its_work_files_but_never_a_stored_backup(): void {
		$root    = TempDir::create();
		$jobs    = new JobStore( $root . '/jobs' );
		$backups = $root . '/backups';
		mkdir( $backups, 0700, true );
		$job  = $jobs->create( 'reset', [ 'reset_backup', 'reset_media' ], [ 'type' => 'media', 'backup' => true ], 1791363600 );
		$work = $jobs->dir( $job->id ) . '/reset-backup';
		mkdir( $work, 0700, true );
		file_put_contents( $work . '/archive.wpress', 'part' );
		$temp = TempName::name();
		file_put_contents( $backups . '/' . $temp, 'half a backup' );
		file_put_contents( $backups . '/pre-reset-20261006-090000.wpress', 'an older, finished backup' );
		$job->cursor = [
			'backup' => [
				'index'  => 7,
				'cursor' => [ 'temp' => $temp ],
				'state'  => [],
			],
		];
		$job->status = Job::FAILED;

		ResetCleanup::afterFailure( $job, $jobs, $backups );

		$this->assertDirectoryDoesNotExist( $work );
		$this->assertFileDoesNotExist( $backups . '/' . $temp );
		$this->assertFileExists( $backups . '/pre-reset-20261006-090000.wpress' );
		TempDir::remove( $root );
	}

	public function test_an_empty_reservation_goes_and_the_tables_hook_runs(): void {
		$root    = TempDir::create();
		$jobs    = new JobStore( $root . '/jobs' );
		$backups = $root . '/backups';
		mkdir( $backups, 0700, true );
		$job = $jobs->create( 'reset', [ 'reset_backup' ], [ 'type' => 'media', 'backup' => true ], 1791363600 );
		touch( $backups . '/pre-reset-20261007-090000.wpress' );
		$job->cursor = [ 'backup' => [ 'cursor' => [ 'reserving' => 'pre-reset-20261007-090000.wpress' ] ] ];
		$seen        = [];

		ResetCleanup::afterFailure(
			$job,
			$jobs,
			$backups,
			static function ( Job $failed ) use ( &$seen ): void {
				$seen[] = $failed->id;
			}
		);

		$this->assertFileDoesNotExist( $backups . '/pre-reset-20261007-090000.wpress' );
		$this->assertSame( [ $job->id ], $seen );
		TempDir::remove( $root );
	}
}
