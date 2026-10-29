<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudCopies;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobException;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeFileDestination;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudUploadStepTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const BYTES = 'abcdefghijklmnopqrstuvw'; // 23 bytes: parts of 5, 5, 5, 5, 3.

	private string $dir;

	private JobStore $store;

	private int $now = 5000;

	/** @var array<int, float> */
	private array $paused = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		file_put_contents( $this->dir . '/' . self::NAME, self::BYTES );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $ids Destinations in order.
	 */
	private function job( array $ids, bool $keepLocal = true ): Job {
		$job                  = $this->store->create( 'export', [ CloudUploadStep::KEY ], [], 1 );
		$job->state['backup'] = [
			'name' => self::NAME,
			'path' => $this->dir . '/' . self::NAME,
			'size' => strlen( self::BYTES ),
		];
		$job->state['cloud']  = CloudPlan::manual( $ids, $keepLocal )->toArray();
		$this->store->save( $job );

		return $job;
	}

	private function step( Destinations $destinations ): CloudUploadStep {
		return new CloudUploadStep(
			$destinations,
			fn(): int => $this->now,
			function ( float $seconds ): void {
				$this->paused[] = $seconds;
				$this->now     += (int) ceil( $seconds ); // The wait passes.
			}
		);
	}

	/**
	 * Runs the step until it is done, saving the job after every call like the Runner.
	 */
	private function runToEnd( CloudUploadStep $step, Job $job, int $max = 50 ): void {
		for ( $i = 0; $i < $max; $i++ ) {
			$result = $step->run( $job, new NoDeadline() );
			$this->store->save( $job );
			if ( $result->isDone() ) {
				return;
			}
		}
		$this->fail( 'The step did not finish.' );
	}

	/**
	 * @param array<int, string> $calls Calls of a FakeDestination.
	 * @return array<int, string> Only "part:…" calls.
	 */
	private static function parts( array $calls ): array {
		return array_values( array_filter( $calls, static fn( string $call ): bool => str_starts_with( $call, 'part:' ) ) );
	}

	public function test_uploads_to_every_destination_and_records_each(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One', 'bbbb2222' => 'Two' ] );
		$job = $this->job( [ 'aaaa1111', 'bbbb2222' ] );

		$this->runToEnd( $this->step( $d ), $job );

		foreach ( [ 'aaaa1111', 'bbbb2222' ] as $id ) {
			$this->assertSame( self::BYTES, FakeCloud::$remotes[ $id ]->files[ self::NAME ] );
			$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, $id )['status'] );
			$this->assertSame( CloudCopies::UP, CloudCopies::of( self::NAME )[ $id ]['status'] );
			$this->assertSame( [ Profile::OK, 5000 ], [ $d->find( $id )->status, $d->find( $id )->lastUploadAt ] );
		}
		$this->assertFalse( CloudOutcome::partial( $job ) );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_a_started_upload_is_saved_before_the_first_part(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = $this->job( [ 'aaaa1111' ] );

		$result = $this->step( $d )->run( $job, new NoDeadline() );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( [ 'start:' . self::NAME ], FakeCloud::$remotes['aaaa1111']->calls, 'The session exists remotely, no part was sent yet.' );
		$this->assertSame( 'u1', $job->cursor['session']['remote'] );
	}

	/**
	 * Review Focus 2: the Runner saves the job before every step call; a
	 * request that dies after sending a part but before that save loses only
	 * the cursor change, so the next request sends that same part again (S3
	 * replaces a part of the same number) and never starts a second upload.
	 */
	public function test_a_request_that_died_after_a_part_resends_only_that_part(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() ); // part @0
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() ); // part @5 — the request dies before the save.

		$job = $this->store->load( $job->id );
		$this->runToEnd( $step, $job );

		$calls = FakeCloud::$remotes['aaaa1111']->calls;
		$this->assertSame( 1, count( array_filter( $calls, static fn( string $c ): bool => str_starts_with( $c, 'start:' ) ) ) );
		$this->assertSame( [ 'part:' . self::NAME . '@0+5', 'part:' . self::NAME . '@5+5', 'part:' . self::NAME . '@5+5', 'part:' . self::NAME . '@10+5', 'part:' . self::NAME . '@15+5', 'part:' . self::NAME . '@20+3' ], self::parts( $calls ) );
		$this->assertSame( self::BYTES, FakeCloud::$remotes['aaaa1111']->files[ self::NAME ] );
	}

	/**
	 * Review Focus 1.
	 */
	public function test_every_part_but_the_last_has_the_session_size_across_ticks(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new TransientFailure( 'No answer.', TransientFailure::NETWORK ) );
		$job = $this->job( [ 'aaaa1111' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$lengths = array_map( static fn( string $c ): int => (int) substr( $c, strrpos( $c, '+' ) + 1 ), self::parts( FakeCloud::$remotes['aaaa1111']->calls ) );
		$this->assertSame( [ 5, 5, 5, 5, 5, 3 ], $lengths, 'The failed first part is sent again with the same size.' );
	}

	/**
	 * Spec 21.1: one destination's failure does not stop the others.
	 */
	public function test_a_lasting_failure_stops_only_that_destination(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One', 'bbbb2222' => 'Two' ] );
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( 'Access denied.', PermanentFailure::DENIED, 403 ) );
		$job = $this->job( [ 'aaaa1111', 'bbbb2222' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$this->assertSame( [ CloudOutcome::FAILED, 'Access denied.' ], [ CloudOutcome::get( $job, 'aaaa1111' )['status'], CloudOutcome::get( $job, 'aaaa1111' )['message'] ] );
		$this->assertContains( 'abort:' . self::NAME, FakeCloud::$remotes['aaaa1111']->calls );
		$this->assertSame( [ Profile::ERROR, 'Access denied.' ], [ $d->find( 'aaaa1111' )->status, $d->find( 'aaaa1111' )->message ] );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'] );
		$this->assertTrue( CloudOutcome::partial( $job ) );
		$this->assertStringContainsString( 'One', implode( "\n", $job->warnings ) );
	}

	public function test_a_deleted_profile_is_skipped_with_a_warning(): void {
		$d   = FakeCloud::install( [ 'bbbb2222' => 'Two' ] );
		$job = $this->job( [ 'aaaa1111', 'bbbb2222' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$this->assertSame( CloudOutcome::SKIPPED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'] );
		$this->assertCount( 1, $job->warnings );
	}

	public function test_credentials_that_no_longer_open_fail_that_destination_as_reauth(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = $this->job( [ 'aaaa1111' ] );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );
		// A new request builds its vault after the salts changed (the vault keeps its key per instance).
		$fresh = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), $d->registry() );

		$this->runToEnd( $this->step( $fresh ), $job );

		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( Profile::REAUTH, $fresh->find( 'aaaa1111' )->status );
		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->calls );
	}

	public function test_an_upload_the_provider_forgot_starts_over_once(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() );
		$step->run( $job, new NoDeadline() );
		FakeCloud::$remotes['aaaa1111']->sessions = []; // Expired on the provider's side.

		$this->runToEnd( $step, $job );

		$this->assertSame( 2, count( array_filter( FakeCloud::$remotes['aaaa1111']->calls, static fn( string $c ): bool => str_starts_with( $c, 'start:' ) ) ) );
		$this->assertSame( self::BYTES, FakeCloud::$remotes['aaaa1111']->files[ self::NAME ] );
	}

	public function test_an_upload_forgotten_again_after_the_fresh_start_fails_that_destination(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( 'Upload gone.', PermanentFailure::SESSION_GONE, 404 ) );
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( 'Upload gone.', PermanentFailure::SESSION_GONE, 404 ) );
		$job = $this->job( [ 'aaaa1111' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$this->assertSame( 2, count( array_filter( FakeCloud::$remotes['aaaa1111']->calls, static fn( string $c ): bool => str_starts_with( $c, 'start:' ) ) ) );
		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( CloudCopies::FAILED, CloudCopies::of( self::NAME )['aaaa1111']['status'] );
		$this->assertSame( self::BYTES, file_get_contents( $this->dir . '/' . self::NAME ), 'The local backup is never touched.' );
	}

	public function test_a_busy_destination_is_left_alone_until_its_retry_after(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		FakeCloud::$remotes['aaaa1111']->failNext( 'start', new TransientFailure( 'Busy.', TransientFailure::BUSY, 503, 30 ) );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );

		$step->run( $job, new NoDeadline() );
		$this->assertSame( 5030, $job->cursor['notBefore'] );
		$step->run( $job, new NoDeadline() );

		$this->assertSame( [ 'start:' . self::NAME ], FakeCloud::$remotes['aaaa1111']->calls, 'No request before the server\'s time.' );
		$this->assertSame( [ 30.0 ], $this->paused );
		$this->now = 5031;
		$this->runToEnd( $step, $job );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
	}

	public function test_a_backup_that_changed_or_vanished_stops_the_job(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		file_put_contents( $this->dir . '/' . self::NAME, 'shorter' );

		$this->expectException( CloudJobException::class );
		$this->expectExceptionMessage( 'The backup ' . self::NAME . ' is no longer in the backups folder (or it changed), so it cannot be uploaded.' );
		$step->run( $job, new NoDeadline() );
	}

	public function test_a_backup_that_vanished_after_two_parts_stops_the_job(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		for ( $i = 0; $i < 3; $i++ ) { // start, part @0, part @5
			$step->run( $job, new NoDeadline() );
			$this->store->save( $job );
		}
		unlink( $this->dir . '/' . self::NAME );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The job went on without its backup.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( 'The backup ' . self::NAME . ' is no longer in the backups folder (or it changed), so it cannot be uploaded.', $e->getMessage() );
		}
		$this->assertCount( 2, self::parts( FakeCloud::$remotes['aaaa1111']->calls ) );
	}

	public function test_a_short_read_is_refused_before_the_part_is_sent(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		$step->run( $job, new NoDeadline() ); // part @0
		// The file shrinks after the step's size check, before it reads the part.
		FakeCloud::$onOpen = function (): void {
			file_put_contents( $this->dir . '/' . self::NAME, 'abcdefg' );
		};

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'A short part was not refused.' );
		} catch ( CloudJobException $e ) {
			$this->assertSame( 'The backup ' . self::NAME . ' changed while it was uploaded.', $e->getMessage() );
		}
		$this->assertSame( [ 'part:' . self::NAME . '@0+5' ], self::parts( FakeCloud::$remotes['aaaa1111']->calls ) );
	}

	public function test_a_finish_replayed_after_a_dead_request_completes_once(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		for ( $i = 0; $i < 6; $i++ ) { // start and five parts
			$step->run( $job, new NoDeadline() );
			$this->store->save( $job );
		}
		$step->run( $job, new NoDeadline() ); // finish — the request dies before the save.

		$job = $this->store->load( $job->id );
		$this->runToEnd( $step, $job );

		$calls = FakeCloud::$remotes['aaaa1111']->calls;
		$this->assertSame( 1, count( array_filter( $calls, static fn( string $c ): bool => str_starts_with( $c, 'start:' ) ) ) );
		$this->assertSame( 2, count( array_filter( $calls, static fn( string $c ): bool => str_starts_with( $c, 'finish:' ) ) ) );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( CloudCopies::UP, CloudCopies::of( self::NAME )['aaaa1111']['status'] );
		$this->assertSame( self::BYTES, FakeCloud::$remotes['aaaa1111']->files[ self::NAME ] );
	}

	public function test_patience_spent_on_one_destination_fails_only_that_one(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'One', 'bbbb2222' => 'Two' ] );
		for ( $i = 0; $i < 10; $i++ ) {
			FakeCloud::$remotes['aaaa1111']->failNext( 'part', new TransientFailure( 'No answer.', TransientFailure::NETWORK, 0, 100 ) );
		}
		$job = $this->job( [ 'aaaa1111', 'bbbb2222' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$this->assertSame( [ CloudOutcome::FAILED, 'No answer.' ], [ CloudOutcome::get( $job, 'aaaa1111' )['status'], CloudOutcome::get( $job, 'aaaa1111' )['message'] ] );
		$this->assertContains( 'abort:' . self::NAME, FakeCloud::$remotes['aaaa1111']->calls );
		$this->assertCount( 10, self::parts( FakeCloud::$remotes['aaaa1111']->calls ) );
		$this->assertSame( 5900, $this->now, 'Ten failures spanning fifteen minutes.' );
		$this->assertSame( Profile::ERROR, $d->find( 'aaaa1111' )->status );
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'] );
		$this->assertSame( self::BYTES, FakeCloud::$remotes['bbbb2222']->files[ self::NAME ] );
	}

	public function test_a_destination_list_that_cannot_be_read_stops_the_job(): void {
		$d                   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job                 = $this->job( [ 'aaaa1111' ] );
		$job->state['cloud'] = [ 'targets' => [ [ 'id' => 'not-an-id' ] ] ];

		$this->expectException( CloudJobException::class );
		$this->expectExceptionMessage( 'The list of destinations of this job cannot be read, so the backup was not uploaded.' );
		$this->step( $d )->run( $job, new NoDeadline() );
	}

	public function test_no_secret_reaches_the_job(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = $this->job( [ 'aaaa1111' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$this->assertStringNotContainsString( 'secret-token-', (string) file_get_contents( $this->store->dir( $job->id ) . '/job.json' ) );
	}

	public function test_a_file_upload_destination_reads_each_part_itself(): void {
		$d                              = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$file                           = new FakeFileDestination( 5 );
		FakeCloud::$remotes['aaaa1111'] = $file;
		$job                            = $this->job( [ 'aaaa1111' ] );

		$this->runToEnd( $this->step( $d ), $job );

		$path = $this->dir . '/' . self::NAME;
		$this->assertSame( [ $path . '@0', $path . '@5', $path . '@10', $path . '@15', $path . '@20' ], $file->from );
		$this->assertSame( self::BYTES, $file->inner->files[ self::NAME ] );
		$this->assertCount( 5, self::parts( $file->inner->calls ), 'Each streamed part reached the remote side once.' );
	}

	/**
	 * Review Focus 5 (plan D27): two requests died on this part — the Runner
	 * counted three starts from the same cursor. Only this destination stops.
	 */
	public function test_a_part_cut_off_twice_fails_only_that_destination(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One', 'bbbb2222' => 'Two' ] );
		$job  = $this->job( [ 'aaaa1111', 'bbbb2222' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // The upload to One starts.
		$job->attempt = [
			'print' => 'the same cursor',
			'count' => CloudUploadStep::CUT_OFF_STARTS,
		];

		$step->run( $job, new NoDeadline() );

		$one = CloudOutcome::get( $job, 'aaaa1111' );
		$this->assertSame( CloudOutcome::FAILED, $one['status'] );
		$this->assertStringContainsString( 'wp lw-migration upload ' . self::NAME . ' --to=aaaa1111', $one['message'] );
		$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->sessions, 'The unfinished upload was aborted.' );
		$this->assertSame( [], self::parts( FakeCloud::$remotes['aaaa1111']->calls ), 'The part that kept killing the request was not sent again.' );
		$this->assertSame( 1, $job->cursor['index'] );

		$job->attempt = [];
		$this->runToEnd( $step, $job );

		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'] );
		$this->assertTrue( CloudOutcome::partial( $job ) );
	}

	/**
	 * Preflight P-M7: past the cut-off start the abort itself may be what
	 * dies (it opens a connection), so a later start records the failure
	 * without contacting the destination and the job goes on.
	 */
	public function test_a_cut_off_seen_again_fails_the_destination_without_trying_the_abort_again(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // The upload to One starts.
		$opened           = 0;
		FakeCloud::$onOpen = static function () use ( &$opened ): void {
			++$opened;
		};
		$job->attempt     = [
			'print' => 'the same cursor',
			'count' => CloudUploadStep::CUT_OFF_STARTS + 1,
		];

		$result = $step->run( $job, new NoDeadline() );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( 0, $opened, 'The destination was not opened.' );
		$this->assertSame( [ 'start:' . self::NAME ], FakeCloud::$remotes['aaaa1111']->calls, 'No abort, no part.' );
	}

	public function test_one_dead_request_still_resends_the_part(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() );
		$job->attempt = [
			'print' => 'the same cursor',
			'count' => CloudUploadStep::CUT_OFF_STARTS - 1,
		];

		$step->run( $job, new NoDeadline() );

		$this->assertSame( [ 'part:' . self::NAME . '@0+5' ], self::parts( FakeCloud::$remotes['aaaa1111']->calls ) );
	}

	public function test_every_part_that_arrived_is_announced(): void {
		$d   = FakeCloud::install( [ 'aaaa1111' => 'One' ] );
		$job = $this->job( [ 'aaaa1111' ] );
		Actions\expectDone( 'lw_migration_cloud_part' )
			->times( 5 )
			->withArgs( static fn( $id, $offset, $size ): bool => 'aaaa1111' === $id && is_int( $offset ) && strlen( self::BYTES ) === $size );

		$this->runToEnd( $this->step( $d ), $job );
	}
}
