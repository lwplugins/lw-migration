<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudJobs;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Storage\Cloud\Steps\CloudUploadStep;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeCloud;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * M7c final review I1: an upload session belongs to the connection that
 * started it. After a reconnect (another grant, maybe another account) the
 * stored session is dropped before any request — no part and no abort goes
 * to the old upload address — and the upload starts once more under the new
 * connection.
 */
final class CloudUploadStepReconnectTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	private const BYTES = 'abcdefghijklmnopqrstuvw'; // 23 bytes: parts of 5, 5, 5, 5, 3.

	private const ID = 'aaaa1111';

	private string $dir;

	private JobStore $store;

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

	public function test_a_reconnect_restarts_the_upload_without_a_request_to_the_old_session(): void {
		$d    = $this->connected( 900 );
		$step = new CloudUploadStep( $d, static fn(): int => 5000, static function (): void {} );
		$job  = $this->job();
		$step->run( $job, new NoDeadline() ); // start under the first connection
		$step->run( $job, new NoDeadline() ); // part @0
		$old  = $job->cursor['session']['remote'];
		$fake = FakeCloud::$remotes[ self::ID ];
		$seen = count( $fake->calls );
		$this->connect( $d, 950 ); // Reconnected, perhaps as another account.

		$step->run( $job, new NoDeadline() );

		$this->assertSame( [ 'start:' . self::NAME ], array_slice( $fake->calls, $seen ), 'No part and no abort for the old session.' );
		$this->assertNotSame( $old, $job->cursor['session']['remote'] );
		$this->assertSame( 5, strlen( $fake->sessions[ $old ]['data'] ), 'The old session was not touched.' );
		for ( $i = 0; $i < 20 && CloudOutcome::OK !== ( CloudOutcome::get( $job, self::ID )['status'] ?? null ); $i++ ) {
			$step->run( $job, new NoDeadline() );
		}
		$this->assertSame( self::BYTES, $fake->files[ self::NAME ] ?? null );
	}

	public function test_a_cut_off_after_a_reconnect_sends_no_abort_for_the_old_session(): void {
		$d    = $this->connected( 900 );
		$step = new CloudUploadStep( $d, static fn(): int => 5000, static function (): void {} );
		$job  = $this->call( $step, $this->job() ); // start
		$job  = $this->call( $step, $job, true ); // part @0 dies
		$job  = $this->call( $step, $job, true ); // and dies again
		$this->connect( $d, 950 );

		$this->call( $step, $job );

		$this->assertSame( CloudOutcome::FAILED, CloudOutcome::get( $job, self::ID )['status'] );
		$this->assertNotContains( 'abort:' . self::NAME, FakeCloud::$remotes[ self::ID ]->calls );
	}

	public function test_a_cancelled_job_sends_no_abort_for_a_session_of_an_older_connection(): void {
		$d    = $this->connected( 900 );
		$step = new CloudUploadStep( $d, static fn(): int => 5000, static function (): void {} );
		$job  = $this->job();
		$step->run( $job, new NoDeadline() ); // start
		$this->connect( $d, 950 );

		CloudJobs::abortUpload( $d, $job );

		$this->assertNotContains( 'abort:' . self::NAME, FakeCloud::$remotes[ self::ID ]->calls );
	}

	public function test_a_cancelled_job_still_aborts_a_session_of_the_current_connection(): void {
		$d    = $this->connected( 900 );
		$step = new CloudUploadStep( $d, static fn(): int => 5000, static function (): void {} );
		$job  = $this->job();
		$step->run( $job, new NoDeadline() ); // start

		CloudJobs::abortUpload( $d, $job );

		$this->assertContains( 'abort:' . self::NAME, FakeCloud::$remotes[ self::ID ]->calls );
	}

	private function job(): Job {
		$job                  = $this->store->create( 'export', [ CloudUploadStep::KEY ], [], 1 );
		$job->state['backup'] = [
			'name' => self::NAME,
			'path' => $this->dir . '/' . self::NAME,
			'size' => strlen( self::BYTES ),
		];
		$job->state['cloud']  = CloudPlan::manual( [ self::ID ], true )->toArray();
		$this->store->save( $job );

		return $job;
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

	private function connected( int $at ): Destinations {
		$d = FakeCloud::install( [ self::ID => 'Drive' ] );
		$d->profiles()->replace( new Profile( self::ID, 'fake', 'Drive', [ 'clientId' => 'cid' ] ), false );
		$this->connect( $d, $at );

		return $d;
	}

	private function connect( Destinations $d, int $at ): void {
		$this->assertTrue(
			$d->profiles()->connect(
				self::ID,
				[
					'account'     => 'someone-' . $at . '@example.com',
					'connectedAt' => $at,
					'client'      => 'mark',
					'id'          => sprintf( '%032x', $at ),
				],
				static fn( array $settings ): bool => 'cid' === ( $settings['clientId'] ?? null )
			)
		);
	}
}
