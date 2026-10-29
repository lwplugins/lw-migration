<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\Backup;
use LightweightPlugins\Migration\Backups\Fingerprint;
use LightweightPlugins\Migration\Backups\Repository;
use LightweightPlugins\Migration\Backups\Shares;
use LightweightPlugins\Migration\Export\BackupPublisher;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\FileMover;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Storage\Steps\MoveStep;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class MoveStepTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	private const SAFETY = 'pre-import-20261007-101600.wpress';

	private string $base;

	private FakeWpdb $db;

	private string $from;

	private string $to;

	/** @var array<string, string> Name => bytes of the backups in the old folder. */
	private array $backups = [];

	/** @var array<string, int> Name => mtime of the backups in the old folder. */
	private array $mtimes = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->base = (string) realpath( TempDir::create() );
		$this->db   = FakeWpdb::install();
		$this->from = $this->base . '/root/backups';
		$this->to   = $this->base . '/new';
		mkdir( $this->from, 0755, true );
		BackupDirRules::prepare( $this->to, self::MARKER );
		$mtime = 1700000000;
		foreach ( [ 'a.wpress' => 3000, 'b.wpress' => 5000, self::SAFETY => 2000 ] as $name => $size ) {
			$this->backups[ $name ] = random_bytes( $size );
			file_put_contents( $this->from . '/' . $name, $this->backups[ $name ] );
			touch( $this->from . '/' . $name, $mtime );
			$mtime                += 100;
			$this->mtimes[ $name ] = (int) filemtime( $this->from . '/' . $name ); // Patchwork may shift touch() times.
		}
		file_put_contents( $this->from . '/notes.txt', 'mine' );
		file_put_contents( $this->from . '/.lwm-00000000000000aa.part', 'temp' );
		file_put_contents( $this->base . '/elsewhere.wpress', 'not ours' );
		symlink( $this->base . '/elsewhere.wpress', $this->from . '/x.wpress' );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->from ) && ! is_link( $this->from ) ) {
			chmod( $this->from, 0755 );
		}
		TempDir::remove( $this->base );
		parent::tearDown();
	}

	private function job( string $from = '', bool $fromDefault = true ): Job {
		$from         = '' === $from ? $this->from : $from;
		$job          = new Job( '0123456789abcdef', MoveJobs::TYPE, MoveJobs::STEPS );
		$job->options = [
			'from'        => $fromDefault ? '' : $from,
			'to'          => $this->to,
			'fromDir'     => $from,
			'toDir'       => $this->to,
			'fromDefault' => $fromDefault,
			'during'      => ( new BackupDir( $fromDefault ? '' : $from, $this->to, self::MARKER ) )->toJson(),
			'after'       => ( new BackupDir( $this->to, null, self::MARKER ) )->toJson(),
		];
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => $job->options['during'],
			'autoload' => 'off',
		];

		return $job;
	}

	private function step( ?FileMover $mover = null, ?\Closure $free = null, ?\Closure $sameDevice = null, ?\Closure $link = null ): MoveStep {
		$storage = new StorageDir( $this->base . '/root' );

		return new MoveStep( $mover ?? new FileMover(), static fn( Job $job ) => MoveJobs::targetReady( $job, $storage ), $free, $sameDevice, $link );
	}

	/**
	 * @param \Closure|null $deadline fn(): Deadline per call.
	 * @return int Calls made.
	 */
	private function drive( MoveStep $step, Job $job, ?\Closure $deadline = null ): int {
		for ( $calls = 1; $calls <= 2000; $calls++ ) {
			if ( $step->run( $job, null === $deadline ? new NoDeadline() : $deadline() )->isDone() ) {
				return $calls;
			}
		}
		$this->fail( 'The step never finished.' );
	}

	/** Every call is repeated from the job as saved before it, as after a request that died before the save. */
	private function driveWithReplays( MoveStep $step, Job $job, ?\Closure $deadline = null ): Job {
		for ( $calls = 1; $calls <= 2000; $calls++ ) {
			$saved = $job->toArray();
			$step->run( $job, null === $deadline ? new NoDeadline() : $deadline() );
			$job = Job::fromArray( $saved );
			if ( $step->run( $job, null === $deadline ? new NoDeadline() : $deadline() )->isDone() ) {
				return $job;
			}
		}
		$this->fail( 'The step never finished.' );
	}

	/** Runs until the cursor reaches $phase (or the step ends). */
	private function until( MoveStep $step, Job $job, string $phase ): void {
		for ( $calls = 1; $calls <= 200; $calls++ ) {
			$step->run( $job, new NoDeadline() );
			if ( ( $job->cursor['phase'] ?? '' ) === $phase ) {
				return;
			}
		}
		$this->fail( 'The phase never came: ' . $phase );
	}

	private function assertAllMoved( Job $job ): void {
		foreach ( $this->backups as $name => $bytes ) {
			$this->assertSame( $bytes, file_get_contents( $this->to . '/' . $name ), $name );
			$this->assertFileDoesNotExist( $this->from . '/' . $name, $name );
			// The mtime stays, so retention by age does not change (exact in FileMoverTest; Patchwork shifts touch() here by a second).
			$this->assertEqualsWithDelta( $this->mtimes[ $name ], filemtime( $this->to . '/' . $name ), 1, $name );
		}
		$this->assertSame(
			[
				'count' => 3,
				'bytes' => 10000,
			],
			$job->state['moved']
		);
		$this->assertSame( $job->state['total'], $job->state['moved'] );
		$this->assertSame( 'mine', file_get_contents( $this->from . '/notes.txt' ) );
		$this->assertFileExists( $this->from . '/.lwm-00000000000000aa.part' );
		$this->assertTrue( is_link( $this->from . '/x.wpress' ), 'A link is no backup: it stays.' );
		$this->assertFileExists( $this->base . '/elsewhere.wpress' );
		$this->assertSame( [], preg_grep( '/^\.lwm-/', (array) scandir( $this->to ) ), 'No temp is left in the new folder.' );
		$this->assertSame( [], $job->cursor === [ 'checked' => true ] ? [] : [ $job->cursor ] );
	}

	private function assertNothingMoved(): void {
		foreach ( $this->backups as $name => $bytes ) {
			$this->assertSame( $bytes, file_get_contents( $this->from . '/' . $name ), $name );
		}
	}

	private function refused( string $message, MoveStep $step, Job $job ): void {
		try {
			$this->drive( $step, $job );
			$this->fail( 'Expected: ' . $message );
		} catch ( JobException $e ) {
			$this->assertSame( $message, $e->getMessage() );
			$this->assertStringNotContainsString( $this->base, $e->getMessage() );
		}
	}

	public function test_every_backup_moves_with_its_name_bytes_and_mtime(): void {
		$job = $this->job();
		$this->drive( $this->step(), $job );

		$this->assertAllMoved( $job );
		$this->assertSame( [ 'a.wpress', 'b.wpress', self::SAFETY ], MoveStep::names( $this->to ) );
		$this->assertSame(
			[
				'count' => 3,
				'bytes' => 10000,
			],
			MoveStep::count( $this->to )
		);
		$this->assertSame( [], MoveStep::names( $this->base . '/gone' ) );
	}

	public function test_copies_across_calls_without_hard_links(): void {
		$job   = $this->job();
		$calls = $this->drive( $this->step( new FileMover( false, 1024 ) ), $job, static fn(): Deadline => new CountdownDeadline( 1 ) );

		$this->assertGreaterThan( 12, $calls );
		$this->assertAllMoved( $job );
		$this->assertSame( 1, stat( $this->to . '/a.wpress' )['nlink'], 'A copy, not a link.' );
	}

	public function test_a_live_link_follows_its_backup_to_another_disk_and_dies_on_an_external_replacement(): void {
		$shares   = new Shares();
		$original = Fingerprint::of( $this->from . '/a.wpress' );
		$live     = $shares->create( Backup::OWN, 'a.wpress', 1, 1790000000, 3600, $original );
		$stale    = $shares->create( Backup::OWN, 'b.wpress', 1, 1790000000, 3600, Fingerprint::fromStat( [ 'size' => 5000, 'mtime' => 1, 'ino' => 1 ] ) );
		$other    = $shares->create( Backup::EXTERNAL, 'a.wpress', 1, 1790000000, 3600, $original );

		$this->drive( $this->step( new FileMover( false, 1024 ) ), $this->job() );

		$identity = array_column( $shares->all(), 'identity', 'id' );
		$this->assertSame( 1, stat( $this->to . '/a.wpress' )['nlink'], 'A copy with its own inode, as on another disk.' );
		$this->assertNotSame( $original, Fingerprint::of( $this->to . '/a.wpress' ) );
		$this->assertTrue( Shares::matches( $identity[ $live['id'] ], Fingerprint::of( $this->to . '/a.wpress' ) ), 'The link follows the verified copy.' );
		$this->assertFalse( Shares::matches( $identity[ $stale['id'] ], Fingerprint::of( $this->to . '/b.wpress' ) ), 'A link of a file replaced before the move stays dead.' );
		$this->assertSame( $original, $identity[ $other['id'] ], 'A link of the other folder is not touched.' );

		file_put_contents( $this->base . '/replacement', $this->backups['a.wpress'] );
		touch( $this->base . '/replacement', (int) filemtime( $this->to . '/a.wpress' ) );
		rename( $this->base . '/replacement', $this->to . '/a.wpress' );
		$this->assertFalse( Shares::matches( $identity[ $live['id'] ], Fingerprint::of( $this->to . '/a.wpress' ) ), 'A file put there from outside is not the linked one.' );
	}

	public function test_a_hard_linked_move_keeps_a_live_link_as_it_is(): void {
		$shares   = new Shares();
		$original = Fingerprint::of( $this->from . '/a.wpress' );
		$live     = $shares->create( Backup::OWN, 'a.wpress', 1, 1790000000, 3600, $original );

		$this->drive( $this->step(), $this->job() );

		$this->assertSame( $original, array_column( $shares->all(), 'identity', 'id' )[ $live['id'] ] );
		$this->assertTrue( Shares::matches( $original, Fingerprint::of( $this->to . '/a.wpress' ) ) );
	}

	public function test_moved_backups_are_owner_only_whatever_their_source_mode_and_the_link_setting(): void {
		foreach ( [ 'hard links' => true, 'copies' => false ] as $label => $links ) {
			foreach ( [ 'a.wpress', 'b.wpress', self::SAFETY ] as $name ) {
				if ( file_exists( $this->to . '/' . $name ) ) {
					unlink( $this->to . '/' . $name );
				}
				file_put_contents( $this->from . '/' . $name, $this->backups[ $name ] );
				chmod( $this->from . '/' . $name, 0644 );
			}
			$job = $this->job();
			$this->drive( $this->step( new FileMover( $links, 1024 ) ), $job );

			clearstatcache();
			foreach ( MoveStep::names( $this->to ) as $name ) {
				$this->assertSame( 0600, fileperms( $this->to . '/' . $name ) & 0777, $label . ' ' . $name );
			}
		}
	}

	/**
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public static function provide_ways(): array {
		return [
			'hard links'                   => [ true, true ],
			'copies, links when published' => [ false, true ],
			'no hard links at all'         => [ false, false ],
		];
	}

	/**
	 * @dataProvider provide_ways
	 */
	public function test_a_request_that_dies_after_any_call_repeats_safely( bool $copyLinks, bool $publishLinks ): void {
		$link = $publishLinks ? null : static fn( string $from, string $to ): bool => false;
		$job  = $this->driveWithReplays( $this->step( new FileMover( $copyLinks, 1024 ), null, null, $link ), $this->job(), static fn(): Deadline => new CountdownDeadline( 1 ) );

		$this->assertAllMoved( $job );
	}

	public function test_a_crash_before_publishing_keeps_the_original(): void {
		$step = $this->step( new FileMover( false ) );
		$job  = $this->job();
		$this->until( $step, $job, 'publish' );

		$name = (string) $job->cursor['name'];
		$this->assertSame( 'a.wpress', $name );
		$this->assertSame( $this->backups[ $name ], file_get_contents( $this->from . '/' . $name ), 'The original is untouched.' );
		$this->assertFileExists( $this->to . '/' . $job->cursor['temp'] );
		$this->assertFileDoesNotExist( $this->to . '/' . $name, 'Nothing is published yet.' );

		$fresh = Job::fromArray( $job->toArray() );
		$this->drive( $this->step( new FileMover( false ) ), $fresh );
		$this->assertAllMoved( $fresh );
	}

	/**
	 * A move left in its publish phase past the lock lifetime; a delete in
	 * between removed the backup from both folders. Resuming must not bring
	 * it back from the temp (final review item 2), with or without hard links.
	 */
	public function test_a_backup_deleted_while_its_copy_waits_to_be_published_stays_deleted(): void {
		foreach ( [ 'hard links' => null, 'reserved name' => static fn( string $a, string $b ): bool => false ] as $label => $link ) {
			$this->tearDown();
			$this->setUp();
			$step = $this->step( new FileMover( false ), null, null, $link );
			$job  = $this->job();
			$this->until( $step, $job, 'publish' );
			if ( null !== $link ) {
				$step->run( $job, new NoDeadline() ); // The name is reserved first (saved in the cursor) ...
				$this->assertSame( 'a.wpress', $job->cursor['reserving'] ?? null, $label );
				file_put_contents( $this->to . '/a.wpress', '' ); // ... and the request died after creating it.
			}
			$temp = (string) $job->cursor['temp'];
			$this->assertFileExists( $this->to . '/' . $temp, $label );
			$this->assertTrue( ( new Repository( $this->from, '', $this->to ) )->deleteIn( 'a.wpress', Backup::OWN ), $label );

			$this->drive( $this->step( new FileMover( false ), null, null, $link ), $job );

			$this->assertFileDoesNotExist( $this->to . '/a.wpress', $label . ': the deleted backup does not come back.' );
			$this->assertFileDoesNotExist( $this->from . '/a.wpress', $label );
			$this->assertFileDoesNotExist( $this->to . '/' . $temp, $label . ': its temp is discarded.' );
			$this->assertSame( [], preg_grep( '/^\.lwm-/', (array) scandir( $this->to ) ), $label );
			foreach ( [ 'b.wpress', self::SAFETY ] as $name ) {
				$this->assertSame( $this->backups[ $name ], file_get_contents( $this->to . '/' . $name ), $label . ' ' . $name );
			}
		}
	}

	public function test_a_crash_after_publishing_removes_the_original_once(): void {
		$step = $this->step( new FileMover( false ) );
		$job  = $this->job();
		$this->until( $step, $job, 'publish' );
		$this->assertSame( BackupPublisher::DONE, ( new BackupPublisher( $this->to ) )->publish( (string) $job->cursor['temp'], 'a.wpress' ) );

		$this->drive( $this->step( new FileMover( false ) ), $job );

		$this->assertAllMoved( $job );
	}

	public function test_a_name_taken_in_the_target_stops_before_anything_moves(): void {
		file_put_contents( $this->to . '/b.wpress', 'another site' );

		$this->refused( 'The new folder already holds a file named b.wpress. Move or delete it there first.', $this->step(), $this->job() );
		$this->assertNothingMoved();
		$this->assertSame( 'another site', file_get_contents( $this->to . '/b.wpress' ) );
		$this->assertFileDoesNotExist( $this->to . '/a.wpress' );
	}

	public function test_a_name_with_the_same_size_and_time_but_other_bytes_is_taken_too(): void {
		file_put_contents( $this->to . '/b.wpress', random_bytes( 5000 ) );
		touch( $this->to . '/b.wpress', $this->mtimes['b.wpress'] );

		$this->refused( 'The new folder already holds a file named b.wpress. Move or delete it there first.', $this->step(), $this->job() );
		$this->assertNothingMoved();
		$this->assertFileDoesNotExist( $this->to . '/a.wpress' );
	}

	public function test_a_duplicate_left_by_an_interrupted_move_does_not_block_the_finish(): void {
		copy( $this->from . '/a.wpress', $this->to . '/a.wpress' );
		touch( $this->to . '/a.wpress', $this->mtimes['a.wpress'] );
		link( $this->from . '/b.wpress', $this->to . '/b.wpress' );

		$job = $this->job();
		$this->drive( $this->step(), $job );

		$this->assertAllMoved( $job );
	}

	public function test_a_read_only_old_folder_and_a_full_target_are_refused_first(): void {
		$free = static fn( string $dir ): ?int => 10;
		$this->refused( 'The new folder needs ' . ( 10000 + FileMover::MARGIN ) . ' B of free space for the backups; it has 10 B.', $this->step( null, $free, static fn( string $a, string $b ): bool => false ), $this->job() );
		$this->assertNothingMoved();
		$this->drive( $this->step( null, $free, static fn( string $a, string $b ): bool => true ), $this->job() );
		$this->assertFileExists( $this->to . '/a.wpress', 'On one disk a hard link needs no room.' );

		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores folder modes.' );
		}
		$other = $this->base . '/other';
		mkdir( $other );
		file_put_contents( $other . '/c.wpress', 'c' );
		chmod( $other, 0550 );
		try {
			$this->refused( 'The old backups folder is read-only, so the backups cannot be moved out of it.', $this->step(), $this->job( $other ) );
			$this->assertFileDoesNotExist( $this->to . '/c.wpress' );
		} finally {
			chmod( $other, 0755 );
		}
	}

	public function test_a_missing_old_folder_moves_nothing_and_warns(): void {
		$job = $this->job( $this->base . '/gone', false );
		$this->drive( $this->step(), $job );

		$this->assertSame( [ 'The old backups folder was not found, so nothing was moved from it. Its backups are no longer listed.' ], $job->warnings );
		$this->assertSame( 0, $job->state['moved']['count'] );
		$this->assertSame( 0, $job->state['total']['count'] );

		$quiet = $this->job( $this->base . '/root/none' );
		$this->drive( $this->step(), $quiet );
		$this->assertSame( [], $quiet->warnings, 'A default folder that was never made holds nothing to warn about.' );
	}

	public function test_a_missing_or_changed_target_fails_with_its_message(): void {
		$step = $this->step();
		$job  = $this->job();
		$step->run( $job, new NoDeadline() );
		TempDir::remove( $this->to );

		$this->refused( FolderMessages::of( R::MISSING ), $step, $job );
		$this->assertNothingMoved();
		$this->assertDirectoryDoesNotExist( $this->to, 'A missing target is never created again.' );
	}

	public function test_an_old_folder_that_vanished_or_was_replaced_stops_the_move(): void {
		$old = $this->base . '/old';
		BackupDirRules::prepare( $old, self::MARKER );
		copy( $this->from . '/a.wpress', $old . '/a.wpress' );
		copy( $this->from . '/b.wpress', $old . '/b.wpress' );
		$step = $this->step();
		$job  = $this->job( $old, false );
		$step->run( $job, new NoDeadline() );
		rename( $old, $this->base . '/old-moved' );
		symlink( $this->from, $old );

		$this->refused( FolderMessages::of( R::CHANGED ), $step, $job );
		$this->assertNothingMoved();

		unlink( $old );
		$this->refused( FolderMessages::of( R::MISSING ), $step, $job );
	}

	public function test_a_backup_whose_original_changed_after_the_copy_is_kept(): void {
		$step = $this->step( new FileMover( false ) );
		$job  = $this->job();
		$this->until( $step, $job, 'verify' );
		file_put_contents( $this->from . '/a.wpress', 'changed' );

		$this->refused( 'The copy of a.wpress did not match the original. Nothing was removed; start again.', $step, $job );
		$this->assertSame( 'changed', file_get_contents( $this->from . '/a.wpress' ) );
	}

	public function test_a_copy_whose_bytes_changed_under_the_same_identity_never_costs_the_original(): void {
		$step = $this->step( new FileMover( false ) );
		$job  = $this->job();
		$this->until( $step, $job, 'verify' );
		$copy   = $job->cursor['moved']['copy'];
		$target = $this->to . '/a.wpress';
		$handle = fopen( $target, 'r+b' );
		fseek( $handle, 10 );
		fwrite( $handle, chr( ord( $this->backups['a.wpress'][10] ) ^ 0xff ) );
		fclose( $handle );
		$this->touchExactly( $target, $copy['mtime'] );
		$this->assertSame( $copy, FileIdentity::of( $target ), 'Same inode, size and mtime (as FAT may reuse them).' );

		$this->refused( 'The copy of a.wpress did not match the original. Nothing was removed; start again.', $step, $job );
		$this->assertSame( $this->backups['a.wpress'], file_get_contents( $this->from . '/a.wpress' ) );
	}

	/** touch() with Patchwork loaded may shift the time: set exactly $mtime. */
	private function touchExactly( string $path, int $mtime ): void {
		touch( $path, $mtime );
		clearstatcache( true, $path );
		$shift = (int) filemtime( $path ) - $mtime;
		if ( 0 !== $shift ) {
			touch( $path, $mtime - $shift );
			clearstatcache( true, $path );
		}
	}

	public function test_nothing_moves_unless_the_setting_marks_this_move(): void {
		$job                                   = $this->job();
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => ( new BackupDir( '', $this->base . '/elsewhere', self::MARKER ) )->toJson(),
			'autoload' => 'off',
		];

		$this->refused( 'The backups folder setting changed while the backups were moved. Check it in Settings.', $this->step(), $job );
		$this->assertNothingMoved();
		$this->assertFileDoesNotExist( $this->to . '/a.wpress' );
	}

	public function test_an_old_folder_that_cannot_be_listed_is_never_taken_for_empty(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores folder modes.' );
		}
		$text = 'The old backups folder cannot be read, so its backups cannot be moved. Check the permissions of that folder.';
		chmod( $this->from, 0300 );
		try {
			$this->refused( $text, $this->step(), $this->job() );
		} finally {
			chmod( $this->from, 0755 );
		}

		$step = $this->step();
		$job  = $this->job();
		$step->run( $job, new NoDeadline() );
		$this->assertTrue( $job->cursor['checked'] );
		chmod( $this->from, 0300 );
		try {
			$this->refused( $text, $step, $job );
		} finally {
			chmod( $this->from, 0755 );
		}
		$this->assertNothingMoved();
		$this->assertSame( [], MoveStep::listed( $this->base . '/gone' ), 'A missing folder is empty.' );
	}

	public function test_the_temp_is_named_in_the_cursor_before_it_is_written(): void {
		$step = $this->step( new FileMover( false ) );
		$job  = $this->job();
		$step->run( $job, new NoDeadline() );
		$step->run( $job, new NoDeadline() );

		$this->assertSame( 'copy', $job->cursor['phase'] );
		$this->assertMatchesRegularExpression( '/^\.lwm-[0-9a-f]{16}\.part\z/', $job->cursor['temp'] );
		$this->assertFileDoesNotExist( $this->to . '/' . $job->cursor['temp'], 'Saved first (Cleanup keeps every temp a job names), written in the next call.' );
	}
}
