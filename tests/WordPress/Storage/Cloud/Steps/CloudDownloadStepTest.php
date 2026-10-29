<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudDownloadStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\FileIdentity;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudDownloadStepTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const BYTES = 'abcdefghijklmnopqrstuvw';

	private string $dir;

	private JobStore $store;

	private Destinations $destinations;

	private int $now = 1000;

	/** @var array<int, float> */
	private array $paused = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/backups' );
		$this->store        = new JobStore( $this->dir . '/jobs' );
		$this->destinations = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		FakeCloud::$remotes['aaaa1111']->files[ self::NAME ] = self::BYTES;
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job( string $name = self::NAME ): Job {
		$job                       = $this->store->create( 'import', [ CloudDownloadStep::KEY ], [], 1 );
		$job->state['cloudSource'] = CloudJobs::source( 'aaaa1111', new RemoteFile( $name, strlen( self::BYTES ), 0, 'k/' . $name ) );
		$this->store->save( $job );

		return $job;
	}

	private function step( ?int $free = null, ?\Closure $link = null ): CloudDownloadStep {
		return new CloudDownloadStep(
			$this->destinations,
			fn(): string => $this->dir . '/backups',
			fn(): int => $this->now,
			static fn( string $dir ): ?int => $free,
			function ( float $s ): void {
				$this->paused[] = $s;
			},
			5,
			$link
		);
	}

	private function runToEnd( CloudDownloadStep $step, Job $job ): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$result = $step->run( $job, new NoDeadline() );
			$this->store->save( $job );
			if ( $result->isDone() ) {
				return;
			}
		}
		$this->fail( 'The step did not finish.' );
	}

	/** @return array<int, string> */
	private function reads(): array {
		return array_values( array_filter( FakeCloud::$remotes['aaaa1111']->calls, static fn( string $c ): bool => str_starts_with( $c, 'read:' ) ) );
	}

	/** @return array<int, string> */
	private function backupsFolder(): array {
		return array_values( array_diff( (array) scandir( $this->dir . '/backups' ), [ '.', '..' ] ) );
	}

	public function test_downloads_in_ranges_publishes_and_hands_the_archive_to_the_import(): void {
		$job = $this->job();

		$this->runToEnd( $this->step(), $job );

		$path = $this->dir . '/backups/' . self::NAME;
		$this->assertSame( self::BYTES, file_get_contents( $path ) );
		$this->assertSame( [ $path, false ], [ $job->state['archive'], $job->state['archiveOwned'] ] );
		$this->assertSame( strlen( self::BYTES ), $job->state['backup']['size'] );
		$this->assertSame( [ self::NAME ], $this->backupsFolder(), 'No temp is left.' );
		$this->assertCount( 5, $this->reads() );
	}

	public function test_the_downloaded_backup_is_owner_only_under_a_permissive_umask(): void {
		$umask = umask( 0 );
		try {
			$this->runToEnd( $this->step(), $this->job() );
		} finally {
			umask( $umask );
		}

		clearstatcache();
		$this->assertSame( 0600, fileperms( $this->dir . '/backups/' . self::NAME ) & 0777 );
	}

	public function test_a_request_that_died_mid_download_resumes_from_the_saved_offset(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() ); // picks the temp name
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() ); // bytes 0-4
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() ); // bytes 5-9, then the request dies before the save

		$job = $this->store->load( $job->id );
		$this->runToEnd( $step, $job );

		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertSame( 'read:' . self::NAME . '@5+5', $this->reads()[2], 'The lost part is read again from the saved offset.' );
	}

	public function test_a_request_that_died_after_publishing_ends_with_the_published_backup(): void {
		$job  = $this->job();
		$step = $this->step();
		for ( $i = 0; $i < 6; $i++ ) { // the temp name, then five parts
			$step->run( $job, new NoDeadline() );
			$this->store->save( $job );
		}
		$step->run( $job, new NoDeadline() ); // publishes, then the request dies before the save

		$job = $this->store->load( $job->id );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertSame( $this->dir . '/backups/' . self::NAME, $job->state['archive'] );
		$this->assertCount( 5, $this->reads(), 'Nothing is downloaded again.' );
		$this->assertSame( [ self::NAME ], $this->backupsFolder() );
	}

	public function test_the_temp_is_created_in_the_call_that_names_it(): void {
		$job = $this->job();

		$this->step()->run( $job, new NoDeadline() );

		$this->assertTrue( TempName::is( (string) $job->cursor['temp'] ) );
		$this->assertFileExists( $this->dir . '/backups/' . $job->cursor['temp'] );
		$this->assertSame( 0, filesize( $this->dir . '/backups/' . $job->cursor['temp'] ) );
		$this->assertSame( FileIdentity::of( $this->dir . '/backups/' . $job->cursor['temp'] )['id'], $job->cursor['id'] );
	}

	public function test_a_temp_swapped_for_a_link_is_dropped_and_the_download_restarts(): void {
		mkdir( $this->dir . '/outside' );
		$secret = '<?php // important config, 40 bytes long';
		file_put_contents( $this->dir . '/outside/secret.php', $secret );
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() ); // bytes 0-4
		$this->store->save( $job );
		$temp = $this->dir . '/backups/' . $job->cursor['temp'];
		unlink( $temp );
		symlink( $this->dir . '/outside/secret.php', $temp );

		$this->runToEnd( $step, $job );

		$this->assertSame( $secret, file_get_contents( $this->dir . '/outside/secret.php' ), 'The link target is never cut or written.' );
		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertSame( 'read:' . self::NAME . '@0+5', $this->reads()[1], 'The download starts over.' );
		$this->assertSame( [ self::NAME ], $this->backupsFolder(), 'The link is gone.' );
	}

	public function test_a_temp_replaced_by_another_file_or_cut_short_restarts_the_download(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$step->run( $job, new NoDeadline() ); // bytes 0-4
		$step->run( $job, new NoDeadline() ); // bytes 5-9
		$temp = $this->dir . '/backups/' . $job->cursor['temp'];
		file_put_contents( $temp, 'abc' ); // the same file, fewer bytes than the saved offset

		$step->run( $job, new NoDeadline() );
		$this->assertSame( 'read:' . self::NAME . '@0+5', $this->reads()[2] );

		$temp = $this->dir . '/backups/' . $job->cursor['temp']; // the new temp of the restart
		file_put_contents( $temp . '.new', 'abcde' );
		rename( $temp . '.new', $temp ); // another file (inode) under the name
		$step->run( $job, new NoDeadline() );
		$this->assertSame( 'read:' . self::NAME . '@0+5', $this->reads()[3] );

		$this->runToEnd( $step, $job );
		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertSame( [ self::NAME ], $this->backupsFolder(), 'The dropped temps are gone.' );
	}

	public function test_a_local_backup_of_the_same_name_and_size_is_used_without_a_download(): void {
		file_put_contents( $this->dir . '/backups/' . self::NAME, str_repeat( 'z', strlen( self::BYTES ) ) );
		$job = $this->job();

		$this->runToEnd( $this->step(), $job );

		$this->assertSame( [], $this->reads() );
		$this->assertSame( $this->dir . '/backups/' . self::NAME, $job->state['archive'] );
		$this->assertSame( str_repeat( 'z', strlen( self::BYTES ) ), file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertSame( [ 'A backup of the same name and size was already in the backups folder; that copy is used.' ], $job->warnings );
	}

	public function test_a_request_that_died_after_a_reserved_publish_ends_with_the_published_backup(): void {
		$job  = $this->job();
		$step = $this->step( null, static fn( string $from, string $to ): bool => false ); // no hard links here
		for ( $i = 0; $i < 7; $i++ ) { // the temp, five parts, then RESERVE
			$step->run( $job, new NoDeadline() );
			$this->store->save( $job );
		}
		$this->assertSame( self::NAME, $job->cursor['reserving'] );
		$step->run( $job, new NoDeadline() ); // reserves, renames, then the request dies before the save

		$job = $this->store->load( $job->id );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
		$this->assertCount( 5, $this->reads() );
		$this->assertSame( [ self::NAME ], $this->backupsFolder() );
	}

	public function test_unknown_free_space_caps_the_download_at_the_ceiling(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		Filters\expectApplied( 'lw_migration_max_download_bytes' )->once()->andReturn( 10 );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Stopped.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( 'The backup is larger than the free disk space allows.', $e->getMessage() );
			$this->assertSame( [], $this->reads() );
		}
	}

	public function test_a_source_with_a_damaged_profile_id_is_refused(): void {
		$job                              = $this->job();
		$job->state['cloudSource']['id'] = '../x';

		$this->expectException( CloudJobException::class );
		$this->step()->run( $job, new NoDeadline() );
	}

	public function test_a_cloud_import_whose_name_differs_from_its_source_is_refused(): void {
		$job          = $this->job();
		$job->options = [
			'source'   => 'cloud',
			'location' => 'other.wpress',
		];

		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( [], $this->backupsFolder() );
		}
		$job->options['location'] = self::NAME;
		$this->runToEnd( $this->step(), $job );
		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
	}

	public function test_a_different_local_backup_of_the_same_name_stops_the_job_and_stays(): void {
		file_put_contents( $this->dir . '/backups/' . self::NAME, 'other' );

		try {
			$this->step()->run( $this->job(), new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( 'other', file_get_contents( $this->dir . '/backups/' . self::NAME ) );
			$this->assertSame( [], $this->reads() );
		}
	}

	public function test_a_name_that_is_not_a_plain_backup_name_is_refused(): void {
		$this->expectException( CloudJobException::class );
		$this->step()->run( $this->job( '..' ), new NoDeadline() );
	}

	public function test_too_little_disk_space_stops_before_reading(): void {
		$job  = $this->job();
		$step = $this->step( 10 );
		$step->run( $job, new NoDeadline() );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Stopped.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( 'The backup is larger than the free disk space allows.', $e->getMessage() );
			$this->assertSame( [], $this->reads() );
		}
	}

	public function test_a_passing_failure_waits_and_then_goes_on(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		FakeCloud::$remotes['aaaa1111']->failNext( 'read', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->assertFalse( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [ 1, 1002 ], [ $job->cursor['failures'], $job->cursor['notBefore'] ] );
		$this->assertSame( 0, $job->cursor['offset'] );

		$step->run( $job, new NoDeadline() );
		$this->assertSame( [ 2.0 ], $this->paused, 'The held request waits instead of reading.' );
		$this->assertSame( 1, $job->cursor['waits'] );
		$this->assertCount( 1, $this->reads() );

		$this->now = 1002;
		$this->runToEnd( $step, $job );
		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/backups/' . self::NAME ) );
	}

	public function test_spent_patience_stops_the_job(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$job->cursor['failures']     = 9;
		$job->cursor['failingSince'] = 0;
		FakeCloud::$remotes['aaaa1111']->failNext( 'read', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );

		$this->expectException( CloudJobException::class );
		$step->run( $job, new NoDeadline() );
	}

	public function test_a_file_gone_from_the_destination_stops_the_job_and_names_its_temp_for_the_finish_hook(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$step->run( $job, new NoDeadline() );
		unset( FakeCloud::$remotes['aaaa1111']->files[ self::NAME ] );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'Stopped.' );
		} catch ( CloudJobException $e ) {
			$this->assertTrue( TempName::is( (string) $job->cursor['temp'] ) );
			$this->assertFileExists( $this->dir . '/backups/' . $job->cursor['temp'] );
		}
	}

	public function test_a_deleted_destination_stops_the_job(): void {
		$job  = $this->job();
		$step = $this->step();
		$step->run( $job, new NoDeadline() );
		$this->destinations->forget( 'aaaa1111' );

		$this->expectException( CloudJobException::class );
		$step->run( $job, new NoDeadline() );
	}
}
