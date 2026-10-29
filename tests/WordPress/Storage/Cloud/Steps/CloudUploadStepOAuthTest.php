<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\ReauthNotice;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeResumableDestination;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * M7c plan E8/E9 with preflight P-I7 and P-M6: the status query after a
 * dead request or a passing failure, the death count that keeps M7b's
 * cut-off (D27), and one reconnect mail per revoked connection.
 */
final class CloudUploadStepOAuthTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const BYTES = 'abcdefghijklmnopqrstuvw'; // 23 bytes: parts of 5, 5, 5, 5, 3.

	private const REVOKED = 'The access to Google Drive was withdrawn or has expired. Connect the account again.';

	private string $dir;

	private JobStore $store;

	private int $now = 5000;

	/** @var array<int, string> Subjects of the reconnect mails. */
	private array $mails = [];

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( 'size_format' )->alias( static fn( $bytes ): string => $bytes . ' B' );
		Functions\when( 'is_email' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		file_put_contents( $this->dir . '/' . self::NAME, self::BYTES );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $ids     Destinations.
	 * @param string             $trigger '' (not a schedule run), cron or manual.
	 */
	private function job( array $ids, string $trigger = '' ): Job {
		$job                  = $this->store->create( 'export', [ CloudUploadStep::KEY ], [], 1 );
		$job->state['backup'] = [
			'name' => self::NAME,
			'path' => $this->dir . '/' . self::NAME,
			'size' => strlen( self::BYTES ),
		];
		$job->state['cloud']  = CloudPlan::manual( $ids, true )->toArray();
		if ( '' !== $trigger ) {
			$job->state['schedule'] = [
				'id'      => 'sch00001',
				'title'   => 'Nightly',
				'trigger' => $trigger,
				'notify'  => [
					'when'  => 'failure',
					'email' => 'ops@example.com',
				],
			];
		}
		$this->store->save( $job );

		return $job;
	}

	private function step( Destinations $destinations ): CloudUploadStep {
		$notice = new ReauthNotice(
			function ( array $to, string $subject ): bool {
				$this->mails[] = $subject;
				return true;
			},
			static fn(): string => 'https://site.test/wp-admin/admin.php?page=lw-migration&tab=destinations'
		);

		return new CloudUploadStep( $destinations, fn(): int => $this->now, static function (): void {}, $notice );
	}

	private static function resumable( string $id ): FakeResumableDestination {
		$wrapped                   = new FakeResumableDestination( FakeCloud::$remotes[ $id ] );
		FakeCloud::$remotes[ $id ] = $wrapped;

		return $wrapped;
	}

	/**
	 * One call the way the Runner makes it: the start is counted and the job
	 * saved first; a call that dies loses everything it changed.
	 */
	private function call( CloudUploadStep $step, Job $job, bool $dies = false ): Job {
		AttemptGuard::record( $job, CloudUploadStep::KEY );
		$this->store->save( $job );
		$step->run( $job, new NoDeadline() );
		if ( $dies ) {
			return $this->store->load( $job->id );
		}
		$this->store->save( $job );

		return $job;
	}

	/**
	 * @param array<int, string> $calls Calls of a FakeDestination.
	 * @return array<int, string> Only "part:…" calls.
	 */
	private static function parts( array $calls ): array {
		return array_values( array_filter( $calls, static fn( string $call ): bool => str_starts_with( $call, 'part:' ) ) );
	}

	/**
	 * Review Focus 3.
	 */
	public function test_resume_runs_the_status_query_after_a_dead_request(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake = self::resumable( 'aaaa1111' );
		$step = $this->step( $d );
		$job  = $this->call( $step, $this->job( [ 'aaaa1111' ] ) ); // start
		$job  = $this->call( $step, $job ); // part @0
		$job  = $this->call( $step, $job, true ); // part @5, the request dies
		$calls = count( $fake->inner->calls );

		$job = $this->call( $step, $job ); // the Runner counts a second start from this cursor

		$this->assertSame( 1, $fake->resumes );
		$this->assertCount( $calls, $fake->inner->calls, 'No part is sent in the call that asks.' );
		$this->assertSame( 1, $job->cursor['deaths'] );

		$this->call( $step, $job ); // sends the part again
		$this->assertSame( 1, $fake->resumes, 'One question per death.' );
		$this->assertArrayNotHasKey( 'deaths', $job->cursor, 'A part that arrived clears the count.' );
	}

	public function test_a_transient_failure_makes_the_next_call_ask_first_and_a_good_part_clears_it(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake = self::resumable( 'aaaa1111' );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() );
		$fake->inner->failNext( 'part', new TransientFailure( 'busy', TransientFailure::SERVER, 503 ) );
		$step->run( $job, new NoDeadline() );
		$this->assertTrue( $job->cursor['verify'] );

		$this->now += 3600; // Past any retry wait.
		$step->run( $job, new NoDeadline() ); // asks
		$step->run( $job, new NoDeadline() ); // sends

		$this->assertSame( 1, $fake->resumes );
		$this->assertArrayNotHasKey( 'deaths', $job->cursor );
		$this->assertArrayNotHasKey( 'verify', $job->cursor );
	}

	/**
	 * Preflight P-I7 / M7b D27, through the real AttemptGuard: the resume
	 * call changes the cursor (the start count restarts), so the cursor
	 * counts the deaths; the second death on one part fails only that
	 * destination, and the guard never stops the job.
	 */
	public function test_two_deaths_on_one_part_with_a_resumable_destination_fail_only_that_destination(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'Drive', 'bbbb2222' => 'Box' ] );
		$fake = self::resumable( 'aaaa1111' );
		$step = $this->step( $d );
		$job  = $this->call( $step, $this->job( [ 'aaaa1111', 'bbbb2222' ] ) ); // start
		$job  = $this->call( $step, $job ); // part @0
		$job  = $this->call( $step, $job, true ); // part @5 dies
		$job  = $this->call( $step, $job ); // asks
		$job  = $this->call( $step, $job, true ); // part @5 dies again

		$job = $this->call( $step, $job );

		$one = CloudOutcome::get( $job, 'aaaa1111' );
		$this->assertSame( CloudOutcome::FAILED, $one['status'] );
		$this->assertStringContainsString( 'wp lw-migration upload ' . self::NAME . ' --to=aaaa1111', $one['message'] );
		$this->assertSame( [ 'part:' . self::NAME . '@0+5', 'part:' . self::NAME . '@5+5', 'part:' . self::NAME . '@5+5' ], self::parts( $fake->inner->calls ), 'The killer part went out twice, never a third time.' );
		$this->assertSame( 1, $fake->resumes );
		$this->assertSame( [], $fake->inner->sessions, 'The unfinished upload was aborted.' );

		for ( $i = 0; $i < 20 && CloudOutcome::OK !== ( CloudOutcome::get( $job, 'bbbb2222' )['status'] ?? null ); $i++ ) {
			$job = $this->call( $step, $job );
		}
		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'], 'The other destination goes on.' );
	}

	public function test_a_status_query_that_dies_too_still_ends_in_the_cut_off(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake = self::resumable( 'aaaa1111' );
		$step = $this->step( $d );
		$job  = $this->call( $step, $this->job( [ 'aaaa1111' ] ) ); // start
		$job  = $this->call( $step, $job, true ); // part @0 dies
		$job  = $this->call( $step, $job, true ); // the question dies

		$job = $this->call( $step, $job );

		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertSame( 1, $fake->resumes );
	}

	public function test_a_session_the_status_query_finds_complete_is_finished_without_another_part(): void {
		$d    = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake = self::resumable( 'aaaa1111' );
		$job  = $this->job( [ 'aaaa1111' ] );
		$step = $this->step( $d );
		for ( $i = 0; $i < 5; $i++ ) {
			$step->run( $job, new NoDeadline() ); // start and four parts: 20 of 23 bytes
		}
		$fake->resumeTo = static function ( UploadSession $s ) use ( $fake ): UploadSession {
			$fake->inner->uploadChunk( $s, substr( self::BYTES, $s->offset, $s->nextLength() ) ); // The last part had arrived.
			return $s->with( $s->size, $s->extra );
		};
		$job->cursor['verify'] = true;
		$sent                  = count( self::parts( $fake->inner->calls ) );

		$step->run( $job, new NoDeadline() ); // the check says: all there
		$step->run( $job, new NoDeadline() ); // finish

		$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
		$this->assertCount( $sent + 1, self::parts( $fake->inner->calls ), 'Only the part the fake replayed.' );
		$this->assertSame( self::BYTES, $fake->inner->files[ self::NAME ] );
	}

	public function test_an_answer_past_what_was_sent_fails_that_destination(): void {
		$d              = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake           = self::resumable( 'aaaa1111' );
		$fake->resumeTo = static fn( UploadSession $s ): UploadSession => $s->with( $s->size, $s->extra );
		$job            = $this->job( [ 'aaaa1111' ] );
		$step           = $this->step( $d );
		$step->run( $job, new NoDeadline() );
		$job->cursor['verify'] = true;

		$step->run( $job, new NoDeadline() );

		$one = CloudOutcome::get( $job, 'aaaa1111' );
		$this->assertSame( CloudOutcome::FAILED, $one['status'] );
		$this->assertStringContainsString( 'Drive', $one['message'] );
		$this->assertSame( Profile::ERROR, $d->find( 'aaaa1111' )->status );
	}

	/**
	 * Task 6 m2: a status answer below the confirmed offset starts the upload
	 * over once in a new session; a second loss fails that destination.
	 */
	public function test_an_answer_below_the_confirmed_offset_starts_over_once(): void {
		$d              = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$fake           = self::resumable( 'aaaa1111' );
		$fake->resumeTo = static fn( UploadSession $s ): UploadSession => $s->with( 0, $s->extra );
		$job            = $this->job( [ 'aaaa1111' ] );
		$step           = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		$step->run( $job, new NoDeadline() ); // part @0
		$step->run( $job, new NoDeadline() ); // part @5
		$job->cursor['verify'] = true;

		$step->run( $job, new NoDeadline() ); // the check says: back at 0

		$this->assertArrayNotHasKey( 'session', $job->cursor, 'The lost upload starts over.' );
		$this->assertTrue( $job->cursor['restarted'] );
		$step->run( $job, new NoDeadline() ); // a new session
		$step->run( $job, new NoDeadline() ); // part @0
		$step->run( $job, new NoDeadline() ); // part @5
		$job->cursor['verify'] = true;

		$step->run( $job, new NoDeadline() ); // lost again

		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
	}

	/**
	 * Review Focus 2.
	 */
	public function test_a_revoked_grant_mid_upload_marks_reauth_mails_once_and_goes_on(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'Drive', 'bbbb2222' => 'Box' ] );
		for ( $run = 0; $run < 2; $run++ ) {
			$job  = $this->job( [ 'aaaa1111', 'bbbb2222' ], 'cron' );
			$step = $this->step( $d );
			$step->run( $job, new NoDeadline() ); // start at the first destination
			FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( self::REVOKED, PermanentFailure::REAUTH ) );
			for ( $i = 0; $i < 30 && ! $step->run( $job, new NoDeadline() )->isDone(); $i++ ) {
				$this->store->save( $job );
			}

			$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
			$this->assertSame( CloudOutcome::OK, CloudOutcome::get( $job, 'bbbb2222' )['status'], 'The other destination goes on.' );
			$this->assertSame( Profile::REAUTH, $d->find( 'aaaa1111' )->status );
			$this->assertSame( [], FakeCloud::$remotes['aaaa1111']->sessions, 'The unfinished upload was aborted.' );
			$this->assertFileExists( $this->dir . '/' . self::NAME, 'The local backup stays.' );
		}
		$this->assertCount( 1, $this->mails, 'One mail, not one per run.' );
		$this->assertStringContainsString( 'Drive', $this->mails[0] );
	}

	/**
	 * One mail per revoked grant: an edit that starts the state over does not
	 * mail the same connection again; a reconnect (a new grant) does.
	 */
	public function test_an_oauth_connection_is_mailed_once_until_it_is_connected_again(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$this->connect( $d, 900 );
		$this->revokedRun( $d );
		$d->profiles()->replace( new Profile( 'aaaa1111', 'fake', 'Drive', [ 'clientId' => 'cid', 'note' => 'x' ] ), true );
		$this->assertSame( Profile::NEW, $d->find( 'aaaa1111' )->status );
		$this->revokedRun( $d );
		$this->assertCount( 1, $this->mails, 'The same connection.' );

		$this->connect( $d, 950 );
		$this->revokedRun( $d );

		$this->assertCount( 2, $this->mails );
	}

	public function test_a_manual_run_mails_nothing_and_leaves_the_mail_to_the_next_cron_run(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$this->connect( $d, 900 );

		$this->revokedRun( $d, 'manual' );
		$this->assertSame( [], $this->mails );
		$this->assertSame( Profile::REAUTH, $d->find( 'aaaa1111' )->status );

		$this->revokedRun( $d );
		$this->assertCount( 1, $this->mails );
	}

	/**
	 * Task 4 m4: a reconnect committed while the run worked with the old
	 * connection keeps its fresh state; nothing is mailed for it.
	 */
	public function test_a_reconnect_during_the_run_is_never_marked_reauth(): void {
		$d = FakeCloud::install( [ 'aaaa1111' => 'Drive' ] );
		$this->connect( $d, 900 );
		$job  = $this->job( [ 'aaaa1111' ], 'cron' );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		FakeCloud::$onOpen = function () use ( $d ): void {
			FakeCloud::$onOpen = null;
			$this->connect( $d, 950 ); // The admin reconnects while this call works with the old tokens.
		};
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( self::REVOKED, PermanentFailure::REAUTH ) );

		$step->run( $job, new NoDeadline() );

		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'], 'This run still failed there.' );
		$this->assertSame( [ Profile::NEW, 950 ], [ $d->find( 'aaaa1111' )->status, $d->find( 'aaaa1111' )->connection['connectedAt'] ] );
		$this->assertSame( [], $this->mails );
	}

	private function connect( Destinations $d, int $at ): void {
		$profile = $d->find( 'aaaa1111' );
		if ( 'cid' !== ( $profile->settings['clientId'] ?? null ) ) {
			$d->profiles()->replace( new Profile( 'aaaa1111', 'fake', 'Drive', [ 'clientId' => 'cid' ] ), false );
		}
		$this->assertTrue(
			$d->profiles()->connect(
				'aaaa1111',
				[
					'account'     => 'someone@example.com',
					'connectedAt' => $at,
					'client'      => 'mark',
					'id'          => sprintf( '%032x', $at ),
				],
				static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null )
			)
		);
	}

	private function revokedRun( Destinations $d, string $trigger = 'cron' ): void {
		$job  = $this->job( [ 'aaaa1111' ], $trigger );
		$step = $this->step( $d );
		$step->run( $job, new NoDeadline() ); // start
		FakeCloud::$remotes['aaaa1111']->failNext( 'part', new PermanentFailure( self::REVOKED, PermanentFailure::REAUTH ) );
		$step->run( $job, new NoDeadline() );
		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, 'aaaa1111' )['status'] );
	}
}
