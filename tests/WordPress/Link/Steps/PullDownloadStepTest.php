<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Link\Steps\PullDownloadStep;
use LightweightPlugins\Migration\Link\Steps\PushUploadStep;
use LightweightPlugins\Migration\Link\Steps\Retry;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeHttp;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\ScriptedPeer;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Site A downloads site B's archive; FakeHttp plays B's archive route,
 * ScriptedPeer B's job routes.
 */
final class PullDownloadStepTest extends MonkeyTestCase {

	private const REMOTE = 'bbbbbbbbbbbbbbbb';

	private const ROUTE = '/lw-migration/v1/link/jobs/' . self::REMOTE . '/archive';

	private const CHUNK = 1000;

	public const SIZE = 2500;

	private string $dir;

	private JobStore $store;

	private ScriptedPeer $peer;

	private Job $job;

	private string $archive;

	private int $now;

	/** @var array<int, float> Seconds the step (and its downloader) paused for. */
	private array $paused = [];

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::stubCache();
		ScriptedPeer::stubHttp();
		Functions\when( 'size_format' )->alias( static fn( $b ) => $b . ' B' );
		$this->dir     = TempDir::create();
		$this->store   = new JobStore( $this->dir . '/jobs' );
		$this->peer    = new ScriptedPeer();
		$this->now     = time();
		$this->archive = random_bytes( self::SIZE );
		FakeHttp::install( $this->archive );
		$this->job        = $this->store->create(
			'import',
			[ PullDownloadStep::KEY ],
			[
				'source'       => 'link',
				'location'     => ScriptedPeer::OUT,
				'safetyBackup' => true,
			],
			$this->now
		);
		$this->job->state = [
			'site'     => SiteFixture::make( $this->dir . '/site' )->toArray(),
			'link'     => [
				'id'      => ScriptedPeer::OUT,
				'name'    => 'Site B',
				'address' => ScriptedPeer::ADDRESS,
			],
			'remote'   => [
				'job'    => self::REMOTE,
				'status' => Job::DONE,
			],
			// As RemoteJobStep::ready() leaves it.
			'download' => [
				'url'    => ScriptedPeer::ADDRESS . '/index.php?rest_route=' . self::ROUTE,
				'size'   => self::SIZE,
				'ranges' => true,
				'sha256' => hash( 'sha256', $this->archive ),
			],
		];
		$this->store->save( $this->job );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function step(): PullDownloadStep {
		return new PullDownloadStep(
			$this->store,
			$this->peer->clients( fn(): int => $this->now ),
			static fn( string $dir ): ?int => 10 * 1073741824,
			fn(): int => $this->now,
			function ( float $seconds ): void {
				$this->paused[] = $seconds;
			},
			self::CHUNK
		);
	}

	/**
	 * B's archive route answering with $status (and B's constant JSON) instead of the bytes from part $from on.
	 */
	private function archiveAnswers( int $status, string $code, int $fromPart = 0 ): void {
		$archive           = $this->archive;
		FakeHttp::$handler = static function ( string $url, array $args ) use ( $archive, $status, $code, $fromPart ): array {
			preg_match( '/^bytes=(\d+)-(\d+)$/', (string) $args['headers']['Range'], $m );
			if ( (int) $m[1] < $fromPart * self::CHUNK ) {
				$to = min( (int) $m[2], strlen( $archive ) - 1 );

				return [
					'response' => [ 'code' => 206 ],
					'headers'  => [ 'content-range' => 'bytes ' . $m[1] . '-' . $to . '/' . strlen( $archive ) ],
					'body'     => substr( $archive, (int) $m[1], $to - (int) $m[1] + 1 ),
				];
			}

			return [
				'response' => [ 'code' => $status ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) json_encode(
					[
						'code'    => $code,
						'message' => 'Busy or refused.',
						'data'    => [ 'status' => $status ],
					]
				),
			];
		};
	}

	public function test_the_archive_is_downloaded_with_signed_ranges(): void {
		$this->peer->answers = [ [ 'released' => true ] ];

		$result = $this->step()->run( $this->job, new NoDeadline() );

		$this->assertTrue( $result->isDone() );
		$this->assertCount( 3, FakeHttp::$requests );
		$nonces = [];
		foreach ( FakeHttp::$requests as [ $url, $args ] ) {
			$this->assertSame( ScriptedPeer::ADDRESS . '/index.php?rest_route=' . self::ROUTE, $url );
			$this->assertSame( 0, $args['redirection'], 'A signed request is never redirected.' );
			foreach ( Signer::HEADERS as $header ) {
				$this->assertArrayHasKey( $header, $args['headers'] );
			}
			$parts = Signer::parse( $args['headers'] );
			$this->assertNotNull( $parts );
			$this->assertTrue( Signer::verify( $parts, $this->peer->key, 'GET', self::ROUTE, '', time() ) );
			$nonces[] = $parts['nonce'];
		}
		$this->assertCount( 3, array_unique( $nonces ), 'Every part is signed afresh.' );
		$this->assertCount( 2, $this->paused, 'The next part waits its turn (the nonce cap of the other site).' );
		foreach ( $this->paused as $seconds ) {
			$this->assertGreaterThan( 0.0, $seconds );
			$this->assertLessThanOrEqual( PushUploadStep::MIN_INTERVAL, $seconds );
		}
		$target = WorkDir::path( $this->store, $this->job ) . '/' . ImportFiles::DOWNLOAD;
		$this->assertSame( $target, $this->job->state['archive'] );
		$this->assertSame( $this->archive, file_get_contents( $target ) );
		$this->assertTrue( $this->job->state['archiveOwned'] );
	}

	public function test_a_finished_download_releases_the_other_sites_archive(): void {
		$this->peer->answers = [ [ 'released' => true ] ];

		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertCount( 1, $this->peer->sent );
		$this->assertSame( [ 'DELETE', '/lw-migration/v1/link/jobs/' . self::REMOTE, '' ], [ $this->peer->sent[0]['method'], $this->peer->sent[0]['route'], $this->peer->sent[0]['body'] ] );
		$this->assertTrue( Signer::verify( (array) Signer::parse( $this->peer->sent[0]['headers'] ), $this->peer->key, 'DELETE', '/lw-migration/v1/link/jobs/' . self::REMOTE, '', time() ) );
		$this->assertTrue( $this->job->state['remote']['released'] );
		$this->assertSame( [], $this->job->warnings );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function otherChecksums(): array {
		return [
			'another archive' => [ hash( 'sha256', 'another archive' ) ],
			'none'            => [ null ],
		];
	}

	/**
	 * Issue #1: bytes that are not the archive the signed view described never reach the import.
	 *
	 * @param mixed $sha256 The checksum the job holds.
	 */
	#[DataProvider( 'otherChecksums' )]
	public function test_an_archive_that_differs_from_the_signed_checksum_is_refused( $sha256 ): void {
		$this->job->state['download']['sha256'] = $sha256;
		$target                                 = WorkDir::path( $this->store, $this->job ) . '/' . ImportFiles::DOWNLOAD;

		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'Refused.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'Site B', $e->getMessage() );
			$this->assertStringContainsString( 'changed on its way', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'archive', $this->job->state, 'No import step finds an archive to read.' );
		$this->assertFileDoesNotExist( $target );
		$this->assertSame( [], $this->peer->sent, 'The failed job releases the other site\'s job in its finish hook.' );
	}

	/** A deadline reached as soon as the last part is in. */
	private function deadlineAtTheLastPart(): Deadline {
		$job = $this->job;

		return new class( $job ) implements Deadline {

			public function __construct( private Job $job ) {
			}

			public function reached(): bool {
				return (int) ( $this->job->cursor['offset'] ?? 0 ) >= PullDownloadStepTest::SIZE;
			}
		};
	}

	public function test_an_archive_finished_at_the_deadline_is_checked_in_the_next_tick(): void {
		$result = $this->step()->run( $this->job, $this->deadlineAtTheLastPart() );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( self::SIZE, $this->job->cursor['offset'] );
		$this->assertArrayHasKey( 'sha256', $this->job->cursor, 'The check waits for a tick of its own.' );
		$this->assertSame( [], $this->peer->sent, 'Nothing is released before the archive is checked.' );

		$this->peer->answers = [ [ 'released' => true ] ];
		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );
		$this->assertCount( 3, FakeHttp::$requests, 'The check never downloads again.' );
		$this->assertCount( 1, $this->peer->sent );
		$this->assertSame( $this->archive, file_get_contents( (string) $this->job->state['archive'] ) );
	}

	public function test_a_large_archive_is_checked_over_several_ticks_within_the_deadline(): void {
		$this->archive = random_bytes( 30000 ); // Over three 8 KiB blocks of the test stream wrapper.
		FakeHttp::install( $this->archive );
		$this->job->state['download']['size']   = strlen( $this->archive );
		$this->job->state['download']['sha256'] = hash( 'sha256', $this->archive );
		$this->peer->answers                    = [ [ 'released' => true ] ];

		$ticks    = 0;
		$checking = 0;
		do {
			$result           = $this->step()->run( $this->job, new CountdownDeadline( 0 ) );
			$this->job->cursor = json_decode( (string) json_encode( $this->job->cursor ), true ); // Saved in job.json between ticks.
			$checking        += isset( $this->job->cursor['sha256']['context'] ) ? 1 : 0;
			++$ticks;
		} while ( ! $result->isDone() && $ticks < 200 );

		$this->assertTrue( $result->isDone() );
		$this->assertGreaterThan( 1, $checking, 'The SHA-256 went on over several ticks.' );
		$this->assertCount( 1, $this->peer->sent, 'Released once, after the check.' );
		$this->assertSame( $this->archive, file_get_contents( (string) $this->job->state['archive'] ) );
	}

	public function test_a_mismatch_found_in_a_later_tick_still_refuses_the_archive(): void {
		$this->job->state['download']['sha256'] = hash( 'sha256', 'another archive' );
		$this->assertFalse( $this->step()->run( $this->job, $this->deadlineAtTheLastPart() )->isDone() );
		$target = (string) $this->job->state['archive'];

		$this->expectException( ImportException::class );
		try {
			$this->step()->run( $this->job, new NoDeadline() );
		} finally {
			$this->assertArrayNotHasKey( 'archive', $this->job->state );
			$this->assertFileDoesNotExist( $target );
		}
	}

	public function test_a_release_that_fails_leaves_a_warning_and_the_step_done(): void {
		$this->peer->answers = [ new FakeWpError( 'cURL error 7: refused', 'http_request_failed' ) ];

		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );

		$this->assertArrayNotHasKey( 'released', $this->job->state['remote'] );
		$this->assertSame( [ 'The other site keeps its copy of the archive until its daily clean-up.' ], $this->job->warnings );
		$this->assertSame( $this->archive, file_get_contents( (string) $this->job->state['archive'] ) );
	}

	public function test_an_unfinished_download_continues_next_tick(): void {
		$result = $this->step()->run( $this->job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertCount( 1, FakeHttp::$requests );
		$this->assertSame( self::CHUNK, $this->job->cursor['offset'] );
		$this->assertSame( [], $this->peer->sent, 'Nothing is released before the whole archive is here.' );
		$this->assertArrayNotHasKey( 'archive', $this->job->state );

		$this->peer->answers = [ [ 'released' => true ] ];
		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );
		$this->assertSame( $this->archive, file_get_contents( (string) $this->job->state['archive'] ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function passingFailures(): array {
		return [
			'busy'        => [ 'busy' ],
			'unreachable' => [ 'unreachable' ],
		];
	}

	#[DataProvider( 'passingFailures' )]
	public function test_a_busy_or_unreachable_archive_waits_then_fails( string $kind ): void {
		if ( 'busy' === $kind ) {
			$this->archiveAnswers( 503, 'lw_migration_link_busy', 1 );
		}
		$step  = $this->step();
		$first = $step->run( $this->job, new CountdownDeadline( 0 ) );
		$this->assertFalse( $first->isDone() );
		$this->assertSame( self::CHUNK, $this->job->cursor['offset'] );
		if ( 'unreachable' === $kind ) {
			FakeHttp::$failNext = PHP_INT_MAX;
		}
		$start = $this->now;
		for ( $failure = 1; $failure < Retry::TOLERATED; ++$failure ) {
			$this->now = $start + ( $failure - 1 ) * 100;
			$result    = $step->run( $this->job, new NoDeadline() );
			$this->assertFalse( $result->isDone() );
			$this->assertStringStartsWith( 'Waiting for Site B: ', $result->message() );
			$this->assertSame( $failure, $this->job->cursor['failures'] );
			$this->assertSame( self::CHUNK, $this->job->cursor['offset'], 'The bytes so far are kept.' );
			$this->assertSame( self::CHUNK, filesize( WorkDir::path( $this->store, $this->job ) . '/' . ImportFiles::DOWNLOAD ) );
		}
		$this->assertNotSame( [], $this->paused, 'A failure waits before the next try.' );
		$this->assertSame( [], $this->peer->sent, 'A passing failure asks the other site nothing.' );

		$this->now = $start + Retry::SECONDS;
		try {
			$step->run( $this->job, new NoDeadline() );
			$this->fail( 'Patience did not run out.' );
		} catch ( LinkException $e ) {
			$this->assertTrue( $e->isPassing() );
			$this->assertStringStartsWith( 'Site B: ', $e->getMessage(), 'The final error names the other site like the waiting text.' );
		}
	}

	public function test_progress_after_a_failure_starts_the_count_again(): void {
		$this->archiveAnswers( 503, 'lw_migration_link_busy', 1 );
		$this->step()->run( $this->job, new CountdownDeadline( 0 ) );
		$this->step()->run( $this->job, new NoDeadline() );
		$this->assertSame( 1, $this->job->cursor['failures'] );

		FakeHttp::$handler   = null;
		$this->peer->answers = [ [ 'released' => true ] ];
		$this->assertTrue( $this->step()->run( $this->job, new NoDeadline() )->isDone() );
		$this->assertArrayNotHasKey( 'failures', $this->job->cursor );
	}

	public function test_parts_in_a_tick_that_then_fails_start_the_count_again(): void {
		$this->archiveAnswers( 503, 'lw_migration_link_busy', 2 );
		$this->job->cursor = [
			'offset'       => 0,
			'failures'     => Retry::TOLERATED - 1,
			'failingSince' => $this->now - Retry::SECONDS,
		];

		$result = $this->step()->run( $this->job, new NoDeadline() );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 2 * self::CHUNK, $this->job->cursor['offset'] );
		$this->assertSame( 1, $this->job->cursor['failures'], 'Bytes arrived in this tick: the failure count starts again.' );
		$this->assertSame( $this->now, $this->job->cursor['failingSince'] );
	}

	public function test_a_refused_archive_request_says_pair_again(): void {
		$this->archiveAnswers( 401, 'lw_migration_link_refused' );
		$this->peer->answers = [ ScriptedPeer::reply( 401, [ 'code' => 'lw_migration_link_refused' ] ) ];

		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'A refused archive was accepted.' );
		} catch ( LinkException $e ) {
			$this->assertSame( LinkException::REFUSED, $e->reason );
			$this->assertStringContainsString( 'no longer accepts this connection', $e->getMessage() );
			$this->assertStringNotContainsString( 'HTTP 401', $e->getMessage() );
		}
		$this->assertCount( 1, FakeHttp::$requests, 'A refusal is not tried again.' );
		$this->assertCount( 1, $this->peer->sent );
		$this->assertSame( [ 'GET', '/lw-migration/v1/link/jobs/' . self::REMOTE ], [ $this->peer->sent[0]['method'], $this->peer->sent[0]['route'] ] );
	}

	public function test_a_refused_archive_request_with_a_far_clock_says_so(): void {
		$this->archiveAnswers( 401, 'lw_migration_link_refused' );
		$answer                    = ScriptedPeer::reply( 401, [ 'code' => 'lw_migration_link_refused' ] );
		$answer['headers']['date'] = gmdate( 'D, d M Y H:i:s', $this->now + 600 ) . ' GMT';
		$this->peer->answers       = [ $answer ];

		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'A refused archive was accepted.' );
		} catch ( LinkException $e ) {
			$this->assertSame( LinkException::CLOCK, $e->reason );
			$this->assertStringContainsString( 'differ by about 10 minutes', $e->getMessage() );
		}
	}

	public function test_a_failed_part_the_other_site_cannot_explain_keeps_the_download_text(): void {
		$this->archiveAnswers( 409, 'lw_migration_archive_not_ready' );
		$this->peer->answers = [
			[
				'id'     => self::REMOTE,
				'status' => Job::DONE,
			],
		];

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The download from Site B stopped: The server answered with HTTP 409.' );
		$this->step()->run( $this->job, new NoDeadline() );
	}

	public function test_a_removed_link_says_pair_again(): void {
		$this->peer->links->remove( ScriptedPeer::OUT );

		try {
			$this->step()->run( $this->job, new NoDeadline() );
			$this->fail( 'A removed link downloaded.' );
		} catch ( LinkException $e ) {
			$this->assertSame( LinkException::GONE, $e->reason );
			$this->assertStringContainsString( 'Pair the two sites again.', $e->getMessage() );
		}
		$this->assertSame( [], FakeHttp::$requests );
	}

	public function test_the_download_address_always_follows_the_signed_route(): void {
		$this->job->state['download']['url'] = 'https://elsewhere.example/a.wpress';
		$this->peer->answers                 = [ [ 'released' => true ] ];

		$this->step()->run( $this->job, new NoDeadline() );

		$this->assertSame( ScriptedPeer::ADDRESS . '/index.php?rest_route=' . self::ROUTE, FakeHttp::$requests[0][0] );
	}
}
