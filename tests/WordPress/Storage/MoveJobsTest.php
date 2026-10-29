<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Storage\BackupDir;
use LightweightPlugins\Migration\Storage\BackupDirRules;
use LightweightPlugins\Migration\Storage\FolderRefusedException as R;
use LightweightPlugins\Migration\Storage\MoveJobs;
use LightweightPlugins\Migration\Storage\StorageDir;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class MoveJobsTest extends MonkeyTestCase {

	private const MARKER = '0123456789abcdef';

	private string $base;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->base = (string) realpath( TempDir::create() );
		foreach ( [ 'h', 'site/wp-content/plugins' ] as $dir ) {
			mkdir( $this->base . '/' . $dir, 0755, true );
		}
		$this->db = FakeWpdb::install();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->base );
		parent::tearDown();
	}

	private function storage( ?BackupDir $setting = null ): StorageDir {
		return new StorageDir( $this->base . '/site/wp-content/lw-migration-0123456789ab', $setting );
	}

	/** The rules FolderContext would build for a site in $base/site. */
	private function rules( StorageDir $storage ): BackupDirRules {
		return new BackupDirRules(
			[ $this->base . '/site', $this->base . '/site/wp-content' ],
			[ $this->base . '/site/wp-content/plugins' ],
			array_merge( [ $storage->root() ], $storage->backupDirs() ),
			'',
			$storage->setting()->marker,
			contentDirs: [ $this->base . '/site/wp-content' ],
			owners: $storage->owners()
		);
	}

	/** @return array<string, mixed> */
	private function plan( StorageDir $storage, string $target, ?bool $supported = null ): array {
		$plan = MoveJobs::plan( $storage, $this->rules( $storage ), $target, $supported );
		foreach ( [ 'current', 'during', 'after' ] as $key ) {
			$plan[ $key ] = json_decode( $plan[ $key ]->toJson(), true );
		}

		return $plan;
	}

	private function refusedPlan( string $reason, StorageDir $storage, string $target, ?bool $supported = null ): void {
		try {
			MoveJobs::plan( $storage, $this->rules( $storage ), $target, $supported );
			$this->fail( 'Expected refusal ' . $reason );
		} catch ( R $e ) {
			$this->assertSame( $reason, $e->reason );
		}
	}

	private function prepared( string $name ): string {
		$dir = $this->base . '/h/' . $name;
		BackupDirRules::prepare( $dir, self::MARKER );

		return $dir;
	}

	private function caseInsensitive(): bool {
		clearstatcache();

		return is_dir( strtoupper( $this->base ) . '/H' ) || is_dir( $this->base . '/H' );
	}

	public function test_plan_of_a_new_move_a_finish_and_an_undo(): void {
		$typed = $this->base . '/h/new';
		$plan  = $this->plan( $this->storage(), $typed );
		$new   = $plan['to'];
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $typed, '#' ) . '/lw-migration-backups-[a-f0-9]{12}$#', $new, 'A new typed folder holds a random one.' );
		$this->assertSame( '', $plan['from'] );
		$this->assertSame( [ $this->storage()->defaultBackupsDir(), $new ], [ $plan['fromDir'], $plan['toDir'] ] );
		$this->assertSame( '', $plan['during']['path'] );
		$this->assertSame( $new, $plan['during']['moving'] );
		$this->assertMatchesRegularExpression( BackupDir::MARKER_PATTERN, $plan['during']['marker'] );
		$this->assertSame(
			[
				'path'   => $new,
				'moving' => null,
				'marker' => $plan['during']['marker'],
			],
			$plan['after']
		);
		$this->assertDirectoryDoesNotExist( $typed, 'Planning writes nothing.' );

		$moving  = $this->prepared( 'moving' );
		$pending = $this->storage( new BackupDir( '', $moving, self::MARKER ) );
		$finish  = $this->plan( $pending, $moving );
		$this->assertSame( [ '', $moving ], [ $finish['from'], $finish['to'] ] );
		$this->assertSame( $finish['current'], $finish['during'], 'A finish keeps the stored setting until it switches.' );
		$undo = $this->plan( $pending, '' );
		$this->assertSame( [ $moving, '' ], [ $undo['from'], $undo['to'] ] );
		$this->assertSame( $moving, $undo['fromDir'] );
		$this->assertSame(
			[
				'path'   => '',
				'moving' => null,
				'marker' => self::MARKER,
			],
			$undo['after']
		);
		$this->refusedPlan( R::PENDING, $pending, $this->base . '/h/third' );

		$current = $this->storage( new BackupDir( $moving, null, self::MARKER ) );
		$this->refusedPlan( R::SAME, $current, $moving );
		$this->refusedPlan( R::SAME, $current, $moving . '/' );
		$this->refusedPlan( R::SAME, $this->storage(), ' ' );
		$this->refusedPlan( R::UNSUPPORTED, $this->storage(), $new, false );
		$this->assertSame( '', $this->plan( $current, '', false )['to'], 'The default folder works everywhere.' );
	}

	public function test_another_spelling_of_a_folder_is_that_folder(): void {
		if ( ! $this->caseInsensitive() ) {
			$this->markTestSkipped( 'The file system is case-sensitive.' );
		}
		$moving  = $this->prepared( 'moving' );
		$current = $this->storage( new BackupDir( $moving, null, self::MARKER ) );
		$this->refusedPlan( R::SAME, $current, $this->base . '/H/MOVING' );

		$pending = $this->storage( new BackupDir( '', $moving, self::MARKER ) );
		$finish  = $this->plan( $pending, $this->base . '/h/Moving' );
		$this->assertSame( $moving, $finish['to'], 'The stored spelling is used.' );
	}

	public function test_the_parent_of_the_random_folder_is_that_folder(): void {
		$content = $this->base . '/site/wp-content';
		$random  = $this->rules( $this->storage() )->check( $content );
		BackupDirRules::prepare( $random, self::MARKER );
		$link = $this->base . '/h/content-link';
		symlink( $content, $link );

		$current = $this->storage( new BackupDir( $random, null, self::MARKER ) );
		$this->refusedPlan( R::SAME, $current, $content );
		$this->refusedPlan( R::SAME, $current, $content . '/' );
		$this->refusedPlan( R::SAME, $current, $link ); // Matched by identity, not by name.
		$this->assertSame( [ $random ], glob( $content . '/lw-migration-backups-*' ), 'No second random folder.' );

		$pending = $this->storage( new BackupDir( '', $random, self::MARKER ) );
		$this->assertSame( $random, $this->plan( $pending, $content )['to'], 'Typing the parent finishes the move.' );
	}

	public function test_a_missing_random_folder_is_not_that_folder(): void {
		$content = $this->base . '/site/wp-content';
		$gone    = $content . '/lw-migration-backups-0123456789ab';
		$current = $this->storage( new BackupDir( $gone, null, self::MARKER ) );

		$plan = $this->plan( $current, $content );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $content, '#' ) . '/lw-migration-backups-[a-f0-9]{12}$#', $plan['to'], 'Typing the parent again makes a new random folder.' );
		$this->assertNotSame( $gone, $plan['to'] );
	}

	public function test_typing_the_parent_of_the_current_random_folder_is_the_same_folder_everywhere(): void {
		$parent = $this->base . '/h/outside';
		mkdir( $parent, 0755 );
		$named = $parent . '/lw-migration-backups-0123456789ab';
		BackupDirRules::prepare( $named, self::MARKER );
		$current = $this->storage( new BackupDir( $named, null, self::MARKER ) );

		$this->refusedPlan( R::SAME, $current, $parent );
	}

	public function test_a_finish_never_creates_its_target_again(): void {
		$gone    = $this->base . '/h/gone';
		$pending = $this->storage( new BackupDir( '', $gone, self::MARKER ) );

		$this->refusedPlan( R::MISSING, $pending, $gone );
		$this->assertDirectoryDoesNotExist( $gone );
		$undo = $this->plan( $pending, '' );
		$this->assertSame( $gone, $undo['fromDir'], 'The undo is the way out of a dead target.' );
	}

	public function test_create_prepares_the_folder_writes_moving_and_holds_the_lock(): void {
		$jobs = new JobStore( $this->base . '/jobs' );
		$lock = new SiteLock();
		$typed = $this->base . '/h/new';

		$job = MoveJobs::create( $jobs, $lock, $this->storage(), $this->rules( $this->storage() ), $typed, 100 );

		$new = BackupDir::read()->moving;
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $typed, '#' ) . '/lw-migration-backups-[a-f0-9]{12}$#', (string) $new );
		$marker = BackupDirRules::markerOf( $new );
		$this->assertMatchesRegularExpression( BackupDir::MARKER_PATTERN, (string) $marker );
		foreach ( array_keys( StorageDir::chosenDenyFiles() ) as $name ) {
			$this->assertFileExists( $new . '/' . $name );
		}
		$row = BackupDir::read();
		$this->assertSame( [ '', $new, $marker ], [ $row->path, $row->moving, $row->marker ] );
		$this->assertSame( [ MoveJobs::TYPE, MoveJobs::STEPS ], [ $job->type, $job->steps ] );
		$this->assertSame( $job->id, $lock->holder( 101 ) );
		$saved = $jobs->load( $job->id );
		$this->assertSame( $new, $saved->options['toDir'] );
		$this->assertTrue( $saved->options['fromDefault'] );
		$this->assertSame( $row->toJson(), $saved->options['during'] );
	}

	public function test_a_finish_keeps_the_row_and_checks_the_target_under_the_lock(): void {
		$moving = $this->prepared( 'moving' );
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => ( new BackupDir( '', $moving, self::MARKER ) )->toJson(),
			'autoload' => 'off',
		];
		$storage = $this->storage( BackupDir::read() );
		$jobs    = new JobStore( $this->base . '/jobs' );

		$job = MoveJobs::create( $jobs, new SiteLock(), $storage, $this->rules( $storage ), $moving, 100 );
		$this->assertSame( $moving, $job->options['to'] );
		$this->assertSame( ( new BackupDir( '', $moving, self::MARKER ) )->toJson(), (string) $this->db->value( Options::BACKUP_DIR ) );
	}

	public function test_a_busy_site_writes_nothing(): void {
		$jobs = new JobStore( $this->base . '/jobs' );
		$lock = new SiteLock();
		$this->assertTrue( $lock->acquire( 'ffffffffffffffff', 100 ) );
		$new = $this->base . '/h/new';
		Functions\when( 'wp_date' )->justReturn( 'soon' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );

		try {
			MoveJobs::create( $jobs, $lock, $this->storage(), $this->rules( $this->storage() ), $new, 100 );
			$this->fail( 'A busy site is refused.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( 100 + SiteLock::TTL, $e->expiresAt );
		}
		$this->assertNull( $this->db->value( Options::BACKUP_DIR ) );
		$this->assertDirectoryDoesNotExist( $new );
		$this->assertSame( [], $jobs->ids() );
		$this->assertSame( 'ffffffffffffffff', $lock->holder( 101 ) );
	}

	public function test_a_refused_folder_starts_nothing(): void {
		$jobs = new JobStore( $this->base . '/jobs' );
		$lock = new SiteLock();

		try {
			MoveJobs::create( $jobs, $lock, $this->storage(), $this->rules( $this->storage() ), $this->base . '/site/wp-content/plugins/x', 100 );
			$this->fail( 'A site folder is refused.' );
		} catch ( R $e ) {
			$this->assertSame( R::SITE_FOLDER, $e->reason );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( $lock->holder( 101 ) );
		$this->assertNull( $this->db->value( Options::BACKUP_DIR ) );
		$this->assertDirectoryDoesNotExist( $this->base . '/site/wp-content/plugins/x' );
	}

	public function test_a_typed_folder_filled_by_another_process_before_the_lock_only_holds_the_new_folder(): void {
		$jobs  = new JobStore( $this->base . '/jobs' );
		$new   = $this->base . '/h/new';
		$rules = $this->rules( $this->storage() );
		$lock  = new SiteLock();
		// Another process fills the folder after plan() checked it, while the lock is taken.
		$this->db->beforeWrite = static function () use ( $new ): void {
			mkdir( $new );
			file_put_contents( $new . '/theirs.txt', 'x' );
		};

		MoveJobs::create( $jobs, $lock, $this->storage(), $rules, $new, 100 );

		$child = (string) BackupDir::read()->moving;
		$this->assertSame( $new, dirname( $child ) );
		$this->assertSame( 'x', file_get_contents( $new . '/theirs.txt' ), 'Their file is left alone.' );
		$this->assertSame( [], array_values( array_diff( (array) scandir( $new ), [ '.', '..', 'theirs.txt', basename( $child ) ] ) ) );
	}

	public function test_active_sees_only_a_recent_unfinished_move(): void {
		$jobs = new JobStore( $this->base . '/jobs' );
		$this->assertFalse( MoveJobs::active( $jobs, 5000 ) );

		$export = $jobs->create( 'export', [ 'store' ], [], 5000 );
		$this->assertFalse( MoveJobs::active( $jobs, 5000 ) );
		$move = $jobs->create( MoveJobs::TYPE, MoveJobs::STEPS, [], 5000 );
		$this->assertTrue( MoveJobs::active( $jobs, 5000 ) );
		$this->assertFalse( MoveJobs::active( $jobs, 5001 + SiteLock::TTL ), 'An abandoned move stops blocking after the lock lifetime.' );

		$move->status = Job::FAILED;
		$jobs->save( $move );
		$this->assertFalse( MoveJobs::active( $jobs, 5000 ) );
		unset( $export );
	}

	public function test_a_plan_made_from_a_setting_read_before_the_lock_is_refused(): void {
		$jobs   = new JobStore( $this->base . '/jobs' );
		$lock   = new SiteLock();
		$moving = $this->prepared( 'moving' );
		$stale  = $this->storage();
		$rules  = $this->rules( $stale );
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => ( new BackupDir( '', $moving, self::MARKER ) )->toJson(),
			'autoload' => 'off',
		];
		$stored = (string) $this->db->value( Options::BACKUP_DIR );

		try {
			MoveJobs::create( $jobs, $lock, $stale, $rules, $this->base . '/h/new', 100 );
			$this->fail( 'A stale plan is refused.' );
		} catch ( R $e ) {
			$this->assertSame( R::PENDING, $e->reason );
		}
		$this->assertDirectoryDoesNotExist( $this->base . '/h/new', 'Nothing prepared.' );
		$this->assertSame( $stored, (string) $this->db->value( Options::BACKUP_DIR ) );
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( $lock->holder( 101 ) );

		// A finish planned before another request finished the same move.
		$pending                               = $this->storage( new BackupDir( '', $moving, self::MARKER ) );
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => ( new BackupDir( $moving, null, self::MARKER ) )->toJson(),
			'autoload' => 'off',
		];
		try {
			MoveJobs::create( $jobs, $lock, $pending, $this->rules( $pending ), $moving, 100 );
			$this->fail( 'A stale finish is refused.' );
		} catch ( R $e ) {
			$this->assertSame( R::PENDING, $e->reason );
		}
		$this->assertSame( [], $jobs->ids() );
	}

	public function test_an_abandoned_moves_empty_reservation_is_discarded_under_the_lock(): void {
		$jobs   = new JobStore( $this->base . '/jobs' );
		$moving = $this->prepared( 'moving' );
		$this->db->rows[ Options::BACKUP_DIR ] = [
			'value'    => ( new BackupDir( '', $moving, self::MARKER ) )->toJson(),
			'autoload' => 'off',
		];
		$abandoned         = $jobs->create( MoveJobs::TYPE, MoveJobs::STEPS, [ 'toDir' => $moving ], 50 );
		$abandoned->cursor = [ 'reserving' => 'a.wpress' ];
		$jobs->save( $abandoned );
		$full         = $jobs->create( MoveJobs::TYPE, MoveJobs::STEPS, [ 'toDir' => $moving ], 50 );
		$full->cursor = [ 'reserving' => 'b.wpress' ];
		$jobs->save( $full );
		touch( $moving . '/a.wpress' );
		file_put_contents( $moving . '/b.wpress', 'a backup' );
		$storage = $this->storage( BackupDir::read() );

		MoveJobs::create( $jobs, new SiteLock(), $storage, $this->rules( $storage ), $moving, 100 );

		$this->assertFileDoesNotExist( $moving . '/a.wpress' );
		$this->assertSame( 'a backup', file_get_contents( $moving . '/b.wpress' ), 'A name holding bytes is a backup.' );
	}
}
