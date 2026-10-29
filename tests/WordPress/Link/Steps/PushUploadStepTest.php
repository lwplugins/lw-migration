<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Link\Steps\PushUploadStep;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Support\TimeDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\ScriptedPeer;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Site A sends its archive; the transport plays site B's push and upload routes.
 */
final class PushUploadStepTest extends MonkeyTestCase {

	private const REMOTE = 'bbbbbbbbbbbbbbbb';

	private const MIB = 1048576;

	private const SIZE = 2621440;

	private string $dir;

	private JobStore $store;

	private ScriptedPeer $peer;

	private Job $job;

	private string $archive;

	/** What B received. */
	private string $buffer = '';

	/** B's import: pending, upload (waits for bytes) or running. */
	private string $bStatus = 'pending';

	/** The part size B's web server takes (larger parts answer 413). */
	private int $bLimit = PHP_INT_MAX;

	/** Whether B answers every upload with lw_migration_not_uploading. */
	private bool $notUploading = false;

	/** @var array<int, float> Seconds the step paused for. */
	private array $paused = [];

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		ScriptedPeer::stubHttp();
		Functions\when( 'size_format' )->alias( static fn( $b ) => round( $b / 1048576, 1 ) . ' MiB' );
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->peer  = new ScriptedPeer();
		$this->job   = $this->store->create( 'push', [ PushUploadStep::KEY ], [], 1790000000 );
		$this->job->state = [
			'site'   => SiteFixture::make( $this->dir . '/site' )->toArray(),
			'link'   => [
				'id'      => ScriptedPeer::OUT,
				'name'    => 'Site B',
				'address' => ScriptedPeer::ADDRESS,
			],
			'remote' => [ 'safetyBackup' => true ],
		];
		$this->store->save( $this->job );
		$this->archive = random_bytes( self::SIZE );
		file_put_contents( WorkDir::path( $this->store, $this->job ) . '/' . ExportFiles::ARCHIVE, $this->archive );
		$this->peer->handler = fn( string $method, string $route, string $body ) => $this->b( $method, $route, $body );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		// PHPUnit keeps every test object until the run ends: let go of the megabytes.
		$this->archive       = '';
		$this->buffer        = '';
		$this->peer->sent    = [];
		$this->peer->handler = null;
		parent::tearDown();
	}

	/**
	 * Site B's routes.
	 *
	 * @return array<mixed>
	 */
	private function b( string $method, string $route, string $body ) {
		$jobs = '/lw-migration/v1/link/jobs/' . self::REMOTE;
		if ( 'POST' === $method && '/lw-migration/v1/link/push' === $route ) {
			$this->bStatus = 'upload'; // B ticks a new import once before it answers (review C2).
			return $this->view();
		}
		if ( 'GET' === $method && $jobs === $route ) {
			return $this->view();
		}
		if ( 'POST' === $method && $jobs . '/tick' === $route ) {
			$this->bStatus = 'upload';
			return $this->view();
		}
		if ( 'POST' === $method && 1 === preg_match( '@^' . $jobs . '/upload/(\d+)$@', $route, $m ) ) {
			return $this->upload( (int) $m[1], $body );
		}
		throw new \LogicException( 'B has no route ' . $method . ' ' . $route );
	}

	/**
	 * @return array<mixed>
	 */
	private function upload( int $offset, string $bytes ) {
		if ( $this->notUploading || 'upload' !== $this->bStatus ) {
			return ScriptedPeer::reply( 409, [ 'code' => 'lw_migration_not_uploading', 'message' => 'This job is not waiting for an upload.', 'data' => [ 'status' => 409 ] ] );
		}
		if ( strlen( $bytes ) > $this->bLimit ) {
			return ScriptedPeer::reply( 413, [ 'code' => 'lw_migration_chunk_too_large', 'message' => 'Too large.', 'data' => [ 'status' => 413, 'chunkBytes' => $this->bLimit ] ] );
		}
		if ( strlen( $this->buffer ) !== $offset ) {
			return ScriptedPeer::reply( 409, [ 'code' => 'lw_migration_upload_offset', 'message' => 'Offset.', 'data' => [ 'status' => 409, 'received' => strlen( $this->buffer ) ] ] );
		}
		$this->buffer .= $bytes;
		if ( strlen( $this->buffer ) >= self::SIZE ) {
			$this->bStatus = 'running';
		}

		return $this->view();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function view(): array {
		$waits = 'upload' === $this->bStatus;

		return [
			'id'       => self::REMOTE,
			'type'     => 'import',
			'status'   => $waits ? Job::NEEDS_INPUT : $this->bStatus,
			'steps'    => [ 'upload', 'summary' ],
			'input'    => $waits ? [ 'kind' => 'upload', 'received' => strlen( $this->buffer ), 'size' => self::SIZE, 'chunkBytes' => self::MIB ] : null,
			'progress' => 0,
		];
	}

	private function step(): PushUploadStep {
		return new PushUploadStep(
			$this->store,
			$this->peer->clients( static fn(): int => time() ),
			static fn(): int => time(),
			function ( float $seconds ): void {
				$this->paused[] = $seconds;
			}
		);
	}

	/**
	 * @return array<int, string> Method and route of every request.
	 */
	private function calls(): array {
		return array_map( static fn( array $r ): string => $r['method'] . ' ' . $r['route'], $this->peer->sent );
	}

	/** Sent: B has started the import and $received bytes; the cursor says $offset. */
	private function started( int $received, int $offset ): void {
		$this->job->state['remote']['job'] = self::REMOTE;
		$this->job->cursor                 = [
			'offset' => $offset,
			'chunk'  => self::MIB,
		];
		$this->buffer                      = substr( $this->archive, 0, $received );
		$this->bStatus                     = 'upload';
	}

	public function test_the_first_run_starts_the_import_with_a_stable_name_and_ref(): void {
		$expected = [
			'ref'          => $this->job->id,
			'name'         => BackupName::forSite( 'https://old.test', 1790000000, 0 ),
			'size'         => self::SIZE,
			'safetyBackup' => true,
		];
		$this->peer->handler = static fn() => new FakeWpError( 'cURL error 28: timed out' );

		$this->assertFalse( $this->step()->run( $this->job, new NoDeadline() )->isDone(), 'A lost start waits.' );
		$this->assertSame( 1, $this->job->cursor['failures'] );
		$this->peer->handler = fn( string $method, string $route, string $body ) => $this->b( $method, $route, $body );
		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertSame( 'POST /lw-migration/v1/link/push', $this->calls()[0] );
		$this->assertSame( 'POST /lw-migration/v1/link/push', $this->calls()[1] );
		$this->assertSame( $expected, json_decode( $this->peer->sent[0]['body'], true ) );
		$this->assertSame( $this->peer->sent[0]['body'], $this->peer->sent[1]['body'], 'The repeated start is the same request.' );
		$this->assertSame( self::REMOTE, $this->job->state['remote']['job'] );
		$this->assertTrue( BackupName::isValid( $expected['name'] ) );

		$this->job->state['remote']['safetyBackup'] = false;
		unset( $this->job->state['remote']['job'] );
		$this->peer->sent = [];
		$this->buffer     = '';
		$this->step()->run( $this->job, new CountdownDeadline( 0 ) );
		$this->assertFalse( json_decode( $this->peer->sent[0]['body'], true )['safetyBackup'] );
	}

	public function test_the_archive_arrives_byte_for_byte_in_chunks(): void {
		$result = $this->step()->run( $this->job, new NoDeadline() );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( 'Sent to Site B.', $result->message() );
		$this->assertSame(
			[
				'POST /lw-migration/v1/link/push',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/0',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/1048576',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/2097152',
			],
			$this->calls()
		);
		$this->assertTrue( $this->archive === $this->buffer, 'B has the archive byte for byte.' );
		foreach ( array_slice( $this->peer->sent, 1 ) as $request ) {
			$this->assertSame( 'application/octet-stream', $request['headers']['Content-Type'] );
			$parts = Signer::parse( $request['headers'] );
			$this->assertNotNull( $parts );
			$this->assertTrue( Signer::verify( $parts, $this->peer->key, 'POST', $request['route'], $request['body'], time() ), 'Signed over the raw bytes.' );
		}
		$this->assertCount( 2, $this->paused, 'The next part waits its turn (the nonce cap of the other site).' );
	}

	public function test_a_lost_answer_resumes_at_the_received_offset(): void {
		$this->started( self::MIB, 0 );

		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertSame(
			[
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/0',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/1048576',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/2097152',
			],
			$this->calls()
		);
		$this->assertTrue( $this->archive === $this->buffer );
	}

	public function test_the_last_chunk_after_a_lost_answer_counts_as_sent(): void {
		$this->started( self::SIZE, 2 * self::MIB );
		$this->bStatus = 'running';

		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/2097152', 'GET /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb' ], $this->calls() );
		$this->assertSame( self::SIZE, $this->job->cursor['offset'] );
	}

	public function test_a_pending_job_is_ticked_before_the_first_chunk(): void {
		$this->started( 0, 0 );
		$this->bStatus = 'pending';

		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertSame(
			[
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/0',
				'GET /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/tick',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/0',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/1048576',
				'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/2097152',
			],
			$this->calls()
		);
		$this->assertTrue( $this->archive === $this->buffer, 'Never counted as sent without the bytes.' );

		$this->peer->sent = [];
		$this->started( self::MIB, 0 );
		$this->notUploading = true;
		$this->peer->handler = function ( string $method, string $route, string $body ) {
			if ( 'GET' === $method ) {
				$this->notUploading = false;
			}
			return $this->b( $method, $route, $body );
		};
		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );
		$this->assertSame( 'POST /lw-migration/v1/link/jobs/bbbbbbbbbbbbbbbb/upload/1048576', $this->calls()[2], 'The offset jumps to what B received.' );
	}

	public function test_a_413_halves_the_chunk_down_to_256_kib(): void {
		$this->started( 0, 0 );
		$this->bLimit = 300000;

		$this->step()->run( $this->job, new CountdownDeadline( 1 ) );

		$this->assertSame( 300000, strlen( $this->peer->sent[1]['body'] ) );
		$this->assertSame( 300000, $this->job->cursor['chunk'] );

		$this->job->cursor['chunk'] = PushUploadStep::MIN_CHUNK;
		$this->bLimit               = 1000;
		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'A part of MIN_CHUNK that is still too large fails the step.' );
		} catch ( LinkException $e ) {
			$this->assertSame( 413, $e->status );
		}
	}

	public function test_the_deadline_stops_between_chunks(): void {
		$this->started( 0, 0 );

		$result = $this->step()->run( $this->job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 40, $result->progress() );
		$this->assertSame( 'Sending to Site B: 1 MiB of 2.5 MiB…', $result->message() );
		$this->assertCount( 1, $this->peer->sent );
		$this->assertSame( self::MIB, $this->job->cursor['offset'] );
	}

	public function test_a_busy_other_site_waits_and_tries_again(): void {
		$this->started( 0, 0 );
		$this->peer->handler = static fn() => ScriptedPeer::reply( 503, [ 'code' => 'lw_migration_link_busy', 'message' => 'Busy.' ] );

		$result = $this->step()->run( $this->job, new NoDeadline() );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 1, $this->job->cursor['failures'] );
		$this->assertSame( 0, $this->job->cursor['offset'] );
		$this->assertSame( [ 2.0 ], $this->paused );
	}

	public function test_an_empty_answer_to_a_part_is_tried_again(): void {
		$this->started( 0, 0 );
		$this->peer->handler = static function () {
			$reply         = ScriptedPeer::reply( 200, [] );
			$reply['body'] = '';
			return $reply;
		};

		$result = $this->step()->run( $this->job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( [ 1, 0 ], [ $this->job->cursor['failures'], $this->job->cursor['offset'] ], 'A passing failure; the offset protocol makes the retry safe.' );
	}

	public function test_an_offset_refusal_at_the_same_offset_fails_at_once(): void {
		$this->started( 0, 0 );
		$this->peer->handler = static fn() => ScriptedPeer::reply( 409, [ 'code' => 'lw_migration_upload_offset', 'message' => 'Offset.', 'data' => [ 'status' => 409, 'received' => 0 ] ] );

		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'A refusal at the offset the other site names must not spin.' );
		} catch ( LinkException $e ) {
			$this->assertSame( LinkException::PROTOCOL, $e->reason );
		}
		$this->assertCount( 1, $this->peer->sent );
	}

	public function test_progress_in_a_tick_restarts_the_failure_count(): void {
		$this->started( 0, 0 );
		$this->job->cursor['failures']     = 5;
		$this->job->cursor['failingSince'] = 1;
		$calls                             = 0;
		$this->peer->handler               = function ( string $method, string $route, string $body ) use ( &$calls ) {
			return ++$calls > 1 ? new FakeWpError( 'cURL error 28: timed out' ) : $this->b( $method, $route, $body );
		};

		$this->step()->run( $this->job, new NoDeadline() );

		$this->assertSame( self::MIB, $this->job->cursor['offset'] );
		$this->assertSame( 1, $this->job->cursor['failures'], 'Counted afresh after a part arrived.' );
		$this->assertGreaterThan( 1, $this->job->cursor['failingSince'] );
	}

	public function test_a_wait_never_runs_past_the_tick(): void {
		$this->started( 0, 0 );
		$this->peer->handler = static fn() => ScriptedPeer::reply( 503, [ 'code' => 'lw_migration_link_busy', 'message' => 'Busy.' ] );

		$this->step()->run( $this->job, new TimeDeadline( 0.3 ) );

		$this->assertCount( 1, $this->paused );
		$this->assertLessThanOrEqual( 0.3, $this->paused[0] );
	}

	public function test_a_missing_archive_fails_clearly(): void {
		unlink( WorkDir::path( $this->store, $this->job ) . '/' . ExportFiles::ARCHIVE );

		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'The archive to send is missing from the job folder. Start the push again.' );
		$this->step()->run( $this->job, new NoDeadline() );
	}
}
