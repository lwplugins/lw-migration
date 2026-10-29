<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Storage\Steps\FinishStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FinishStepTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	private string $base;

	private string $old;

	private string $new;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => (string) $n );
		$this->base = (string) realpath( TempDir::create() );
		$this->old  = $this->base . '/old';
		$this->new  = $this->base . '/new';
		BackupDirRules::prepare( $this->old, self::MARKER );
		BackupDirRules::prepare( $this->new, self::MARKER );
		$this->db = FakeWpdb::install();
		$this->store( new BackupDir( $this->old, $this->new, self::MARKER ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		parent::tearDown();
	}

	private function store( BackupDir $setting ): void {
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => $setting->toJson(),
			'autoload' => 'off',
		];
	}

	private function job( ?string $fromDir = null, bool $fromDefault = false ): Job {
		$job          = new Job( '0123456789abcdef', MoveJobs::TYPE, MoveJobs::STEPS, 1 );
		$job->options = [
			'from'        => $fromDefault ? '' : $this->old,
			'to'          => $this->new,
			'fromDir'     => $fromDir ?? $this->old,
			'toDir'       => $this->new,
			'fromDefault' => $fromDefault,
			'during'      => ( new BackupDir( $fromDefault ? '' : $this->old, $this->new, self::MARKER ) )->toJson(),
			'after'       => ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(),
		];
		$job->state   = [
			'moved' => [
				'count' => 2,
				'bytes' => 10,
			],
		];

		return $job;
	}

	private function row(): string {
		return (string) $this->db->value( Options::BACKUP_DIR );
	}

	public function test_the_setting_switches_and_the_old_chosen_folder_is_tidied(): void {
		file_put_contents( $this->old . '/.lwm-00000000000000aa.part', 'stale' );
		$job = $this->job();

		$result = ( new FinishStep() )->run( $job, new NoDeadline() );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( '2 backups moved.', $result->message() );
		$this->assertSame( ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(), $this->row() );
		$this->assertDirectoryDoesNotExist( $this->old );
		$this->assertSame( [], $job->warnings );
		$this->assertSame( self::MARKER, BackupDirRules::markerOf( $this->new ), 'The new folder is untouched.' );
	}

	public function test_a_rerun_after_the_switch_passes(): void {
		$this->store( new BackupDir( $this->new, null, self::MARKER ) );
		TempDir::remove( $this->old );

		$this->assertTrue( ( new FinishStep() )->run( $this->job(), new NoDeadline() )->isDone() );
		$this->assertSame( ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(), $this->row() );
	}

	public function test_a_changed_setting_fails_the_job(): void {
		$third = ( new BackupDir( $this->base . '/third', null, self::MARKER ) )->toJson();
		$this->store( BackupDir::parse( $third ) );

		try {
			( new FinishStep() )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A changed setting fails the job.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'The backups folder setting changed while the backups were moved. Check it in Settings.', $e->getMessage() );
		}
		$this->assertSame( $third, $this->row() );
		$this->assertDirectoryExists( $this->old );
	}

	public function test_a_backup_left_in_the_old_folder_keeps_the_setting(): void {
		file_put_contents( $this->old . '/late.wpress', 'x' );
		$before = $this->row();

		try {
			( new FinishStep() )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A backup still in the old folder would leave the list.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Some backups are still in the old folder. Start the move again.', $e->getMessage() );
		}
		$this->assertSame( $before, $this->row() );
		$this->assertFileExists( $this->old . '/late.wpress' );
	}

	public function test_an_old_folder_with_other_files_is_kept_with_a_warning(): void {
		file_put_contents( $this->old . '/notes.txt', 'mine' );
		file_put_contents( $this->old . '/.lwm-00000000000000aa.part', 'stale' );
		$job = $this->job();

		( new FinishStep() )->run( $job, new NoDeadline() );

		$this->assertSame( [ 'The old backups folder still holds other files, so it was kept. No backups are left in it.' ], $job->warnings );
		$this->assertFileDoesNotExist( $this->old . '/.lwm-00000000000000aa.part', 'Our stale temp goes.' );
		$this->assertSame( 'mine', file_get_contents( $this->old . '/notes.txt' ) );
		$this->assertSame( self::MARKER, BackupDirRules::markerOf( $this->old ), 'The folder stays ours and protected.' );
		foreach ( array_keys( StorageDir::chosenDenyFiles() ) as $name ) {
			$this->assertFileExists( $this->old . '/' . $name );
		}
		$this->assertSame( ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(), $this->row() );
	}

	public function test_the_default_folder_is_never_tidied(): void {
		$default = $this->base . '/root/backups';
		mkdir( $default, 0755, true );
		StorageDir::protect( $default );
		$this->store( new BackupDir( '', $this->new, self::MARKER ) );

		( new FinishStep() )->run( $this->job( $default, true ), new NoDeadline() );

		$this->assertFileExists( $default . '/.htaccess' );
		$this->assertSame( ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(), $this->row() );
	}

	public function test_a_folder_marked_by_another_site_is_never_tidied(): void {
		$other = $this->base . '/other';
		BackupDirRules::prepare( $other, 'ffffffffffffffff' );

		( new FinishStep() )->run( $this->job( $other ), new NoDeadline() );

		$this->assertSame( 'ffffffffffffffff', BackupDirRules::markerOf( $other ) );
		$this->assertFileExists( $other . '/index.php' );
	}

	public function test_a_changed_deny_file_is_kept(): void {
		file_put_contents( $this->old . '/.htaccess', 'Allow from all' );
		$job = $this->job();

		( new FinishStep() )->run( $job, new NoDeadline() );

		$this->assertSame( 'Allow from all', file_get_contents( $this->old . '/.htaccess' ) );
		$this->assertSame( self::MARKER, BackupDirRules::markerOf( $this->old ) );
		$this->assertFileExists( $this->old . '/index.php' );
		$this->assertCount( 1, $job->warnings );
	}

	public function test_a_folder_that_cannot_be_removed_is_protected_again(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores folder modes.' );
		}
		$parent = $this->base . '/locked';
		mkdir( $parent );
		$old = $parent . '/old';
		BackupDirRules::prepare( $old, self::MARKER );
		$this->store( new BackupDir( $old, $this->new, self::MARKER ) );
		$job                  = $this->job( $old );
		$job->options['during'] = ( new BackupDir( $old, $this->new, self::MARKER ) )->toJson();
		chmod( $parent, 0555 ); // rmdir() of the old folder fails; its files may still go.

		try {
			( new FinishStep() )->run( $job, new NoDeadline() );
		} finally {
			chmod( $parent, 0755 );
		}

		$this->assertDirectoryExists( $old );
		foreach ( array_keys( StorageDir::chosenDenyFiles() ) as $name ) {
			$this->assertFileExists( $old . '/' . $name, 'Protected again: ' . $name );
		}
		$this->assertSame( [ 'The old backups folder could not be removed (a file appeared in it, or the folder above it may not be changed), so it was kept and protected again. No backups are left in it.' ], $job->warnings );
		$this->assertSame( ( new BackupDir( $this->new, null, self::MARKER ) )->toJson(), $this->row() );
	}

	public function test_an_old_folder_that_cannot_be_listed_keeps_the_setting(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores folder modes.' );
		}
		file_put_contents( $this->old . '/late.wpress', 'x' );
		$before = $this->row();
		chmod( $this->old, 0300 );
		try {
			( new FinishStep() )->run( $this->job(), new NoDeadline() );
			$this->fail( 'An unlistable folder is never taken for empty.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'The old backups folder cannot be read, so its backups cannot be moved. Check the permissions of that folder.', $e->getMessage() );
		} finally {
			chmod( $this->old, 0755 );
		}
		$this->assertSame( $before, $this->row() );
		$this->assertFileExists( $this->old . '/late.wpress' );
	}
}
