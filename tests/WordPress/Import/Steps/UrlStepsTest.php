<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Import\Steps\UrlResolveStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeHttp;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class UrlStepsTest extends MonkeyTestCase {

	private const FIXTURE = __DIR__ . '/../../../Fixtures/wpress/plain.wpress';

	private const SHARE = 'https://www.dropbox.com/s/secrettoken123/site.wpress?dl=0';

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) $b );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function job( string $location = self::SHARE ): Job {
		return ImportJobFixture::make( $this->store, $this->dir, '', [ 'source' => 'url', 'location' => $location ] );
	}

	private static function http( int $chunk = RangeDownloader::CHUNK ): RangeDownloader {
		return new RangeDownloader( $chunk, 3, 60, static function (): void {} );
	}

	/**
	 * @param \Closure(): ?int $free Free disk space.
	 */
	private function download( RangeDownloader $http, ?\Closure $free = null ): UrlDownloadStep {
		return new UrlDownloadStep( $this->store, $http, $free );
	}

	public function test_range_download_resumes_and_cuts_back_a_partial_chunk(): void {
		$bytes = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes );
		$http = self::http( 100000 );
		$job  = $this->job();

		( new UrlResolveStep( $http ) )->run( $job, new NoDeadline() );
		$this->assertSame( 'https://www.dropbox.com/s/secrettoken123/site.wpress?dl=1', $job->state['download']['url'] );
		$this->assertSame( strlen( $bytes ), $job->state['download']['size'] );
		$this->assertTrue( $job->state['download']['ranges'] );

		$step   = $this->download( $http );
		$target = $this->store->dir( $job->id ) . '/' . ImportFiles::DOWNLOAD;
		$first  = $step->run( $job, new CountdownDeadline( 0 ) );
		$this->assertFalse( $first->isDone() );
		$this->assertSame( 100000, $job->cursor['offset'] );
		$this->assertSame( 15, $first->progress() );
		file_put_contents( $target, 'GARBAGE FROM A DEAD REQUEST', FILE_APPEND );
		$runs = 1;
		do {
			$result = $step->run( $job, new CountdownDeadline( 0 ) );
			++$runs;
		} while ( ! $result->isDone() && $runs < 50 );

		$this->assertTrue( $result->isDone() );
		$this->assertSame( 7, $runs, 'One chunk per run, nothing fetched twice.' );
		$this->assertSame( $bytes, file_get_contents( $target ) );
		$this->assertSame( $target, $job->state['archive'] );
		$this->assertTrue( $job->state['archiveOwned'] );
		$this->assertFileDoesNotExist( $target . RangeDownloader::PART_SUFFIX );
	}

	public function test_url_that_is_not_an_archive_is_refused_before_downloading(): void {
		FakeHttp::install( str_repeat( "\x01", 20000 ) );
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A non-archive was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertCount( 1, FakeHttp::$requests, 'Only the probe was sent.' );
			$this->assertSame( 4377, FakeHttp::$requests[0][1]['limit_response_size'] );
		}

		FakeHttp::install( '<html><body>Sign in</body></html>', true, 'text/html; charset=utf-8' );
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A web page was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'https://www.dropbox.com/…/site.wpress', $e->getMessage() );
			$this->assertStringNotContainsString( 'secrettoken123', $e->getMessage() );
		}
	}

	public function test_download_errors_show_the_address_without_its_token(): void {
		FakeHttp::install( 'x' );
		FakeHttp::$handler = static fn() => [
			'response' => [ 'code' => 404 ],
			'headers'  => [],
			'body'     => '',
		];
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A 404 was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( '404', $e->getMessage() );
			$this->assertStringContainsString( 'https://www.dropbox.com/…/site.wpress', $e->getMessage() );
			$this->assertStringNotContainsString( 'secrettoken123', $e->getMessage() );
			$this->assertStringNotContainsString( 'dl=', $e->getMessage() );
		}
	}

	#[RunInSeparateProcess]
	public function test_a_local_address_is_refused_before_a_request_and_explained(): void {
		require_once dirname( __DIR__, 3 ) . '/Support/wp-error-class.php';
		FakeHttp::install( 'x' );
		Functions\when( 'get_option' )->justReturn( 'https://new.test' );
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job( 'http://127.0.0.1:8080/site.wpress' ), new NoDeadline() );
			$this->fail( 'A refused address was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'A valid URL was not provided.', $e->getMessage() );
			$this->assertStringContainsString( 'Local or internal addresses are refused for safety; use a public address.', $e->getMessage() );
		}
		$this->assertSame( [], FakeHttp::$requests, 'Refused before anything was sent (issue #2), and not tried again.' );
	}

	public function test_a_refusal_of_the_safe_api_is_explained(): void {
		FakeHttp::install( 'x' );
		FakeHttp::$failNext    = 1;
		FakeHttp::$failCode    = 'http_request_failed';
		FakeHttp::$failMessage = 'A valid URL was not provided.';
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job( 'https://files.test:9999/site.wpress' ), new NoDeadline() );
			$this->fail( 'A refused address was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'Local or internal addresses are refused for safety; use a public address.', $e->getMessage() );
		}
		$this->assertCount( 1, FakeHttp::$requests, 'A refusal is not tried again.' );
	}

	public function test_other_download_errors_get_no_local_address_explanation(): void {
		FakeHttp::install( 'x' );
		FakeHttp::$failNext    = 1;
		FakeHttp::$failCode    = 'http_request_not_executed';
		FakeHttp::$failMessage = 'User has blocked requests through HTTP.';
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job(), new NoDeadline() );
			$this->fail( 'A blocked request was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringNotContainsString( 'Local or internal addresses', $e->getMessage() );
		}
	}

	public function test_server_without_ranges_is_fetched_in_one_request_with_a_warning(): void {
		$bytes = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes, false );
		$http = self::http();
		$job  = $this->job();
		( new UrlResolveStep( $http ) )->run( $job, new NoDeadline() );
		$this->assertFalse( $job->state['download']['ranges'] );
		$this->assertSame( strlen( $bytes ), $job->state['download']['size'] );
		$this->assertNotEmpty( $job->warnings );

		$this->assertTrue( $this->download( $http )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $bytes, file_get_contents( $job->state['archive'] ) );
		$this->assertTrue( $job->state['archiveOwned'] );
	}

	public function test_a_range_answer_without_a_total_takes_the_whole_file_path_capped_by_free_space(): void {
		$bytes             = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes );
		FakeHttp::$handler = static function ( string $url, array $args ) use ( $bytes ): array {
			$range = $args['headers']['Range'] ?? null;
			if ( null !== $range ) {
				return [
					'response' => [ 'code' => 206 ],
					'headers'  => [ 'content-range' => 'bytes 0-4376/*' ],
					'body'     => substr( $bytes, 0, 4377 ),
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => $bytes,
			];
		};
		$job = $this->job();
		( new UrlResolveStep( self::http() ) )->run( $job, new NoDeadline() );
		$this->assertFalse( $job->state['download']['ranges'] );
		$this->assertNull( $job->state['download']['size'] );

		$margin = UrlDownloadStep::DISK_MARGIN;
		try {
			$this->download( self::http(), static fn(): int => $margin + 1000 )->run( $job, new NoDeadline() );
			$this->fail( 'A download larger than the free space was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'free disk space', $e->getMessage() );
		}
		$this->assertSame( 1001, FakeHttp::$requests[ count( FakeHttp::$requests ) - 1 ][1]['limit_response_size'] );

		$this->assertTrue( $this->download( self::http(), static fn(): int => $margin + strlen( $bytes ) )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( $bytes, file_get_contents( $job->state['archive'] ) );
	}

	public function test_download_confirmation_page_is_followed_only_to_google_hosts(): void {
		$bytes = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes );
		$page              = static fn( string $action ): string => '<form id="download-form" action="' . $action . '" method="get"><input type="hidden" name="id" value="abc"></form>';
		$action            = 'https://drive.usercontent.google.com/download';
		FakeHttp::$handler = static function ( string $url ) use ( $bytes, &$action, $page ): array {
			if ( str_starts_with( $url, 'https://drive.google.com/' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'text/html; charset=utf-8' ],
					'body'     => $page( $action ),
				];
			}

			return [
				'response' => [ 'code' => 206 ],
				'headers'  => [ 'content-range' => 'bytes 0-4376/' . strlen( $bytes ) ],
				'body'     => substr( $bytes, 0, 4377 ),
			];
		};
		$drive = 'https://drive.google.com/file/d/abc/view';

		$job = $this->job( $drive );
		( new UrlResolveStep( self::http() ) )->run( $job, new NoDeadline() );
		$this->assertSame( 'https://drive.usercontent.google.com/download?id=abc', $job->state['download']['url'] );
		$this->assertTrue( $job->state['download']['ranges'] );

		$action            = 'https://evil.test/download';
		FakeHttp::$requests = [];
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job( $drive ), new NoDeadline() );
			$this->fail( 'A confirmation form pointing elsewhere was followed.' );
		} catch ( ImportException $e ) {
			$this->assertCount( 2, FakeHttp::$requests, 'Probe and page only.' );
		}

		$action             = 'https://google.com.evil.test/download';
		FakeHttp::$requests = [];
		try {
			( new UrlResolveStep( self::http() ) )->run( $this->job( $drive ), new NoDeadline() );
			$this->fail( 'A lookalike host was followed.' );
		} catch ( ImportException $e ) {
			$sent = array_map( static fn( array $r ): string => $r[0] . ' ' . ( $r[1]['headers']['Range'] ?? 'page' ), FakeHttp::$requests );
			$this->assertSame(
				[
					'https://drive.google.com/uc?export=download&id=abc bytes=0-4376',
					'https://drive.google.com/uc?export=download&id=abc page',
				],
				$sent
			);
		}
	}

	public function test_range_download_larger_than_the_free_space_is_refused_before_any_chunk(): void {
		$bytes = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes );
		$job = $this->job();
		( new UrlResolveStep( self::http() ) )->run( $job, new NoDeadline() );
		FakeHttp::$requests = [];

		try {
			$this->download( self::http(), static fn(): int => UrlDownloadStep::DISK_MARGIN + 1000 )->run( $job, new NoDeadline() );
			$this->fail( 'A download larger than the free space was started.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'free disk space', $e->getMessage() );
		}
		$this->assertSame( [], FakeHttp::$requests );

		$step = $this->download( self::http( 100000 ), static fn(): int => UrlDownloadStep::DISK_MARGIN + strlen( $bytes ) );
		$this->assertFalse( $step->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$tight = $this->download( self::http( 100000 ), static fn(): int => UrlDownloadStep::DISK_MARGIN + strlen( $bytes ) - 100000 );
		do {
			$result = $tight->run( $job, new NoDeadline() );
		} while ( ! $result->isDone() );
		$this->assertSame( $bytes, file_get_contents( $job->state['archive'] ), 'Only the bytes still missing must fit.' );
	}

	public function test_unknown_free_space_caps_a_range_download_at_the_ceiling(): void {
		FakeHttp::install( (string) file_get_contents( self::FIXTURE ) );
		$job = $this->job();
		( new UrlResolveStep( self::http() ) )->run( $job, new NoDeadline() );
		FakeHttp::$requests = [];
		Filters\expectApplied( 'lw_migration_max_download_bytes' )->once()->with( UrlDownloadStep::MAX_DOWNLOAD )->andReturn( 1000 );

		$this->expectException( ImportException::class );
		try {
			$this->download( self::http(), static fn(): ?int => null )->run( $job, new NoDeadline() );
		} finally {
			$this->assertSame( [], FakeHttp::$requests );
		}
	}

	public function test_unknown_free_space_caps_a_whole_file_download_at_the_ceiling(): void {
		$bytes             = (string) file_get_contents( self::FIXTURE );
		FakeHttp::install( $bytes, false );
		FakeHttp::$handler = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => $bytes,
		];
		$job               = $this->job();
		( new UrlResolveStep( self::http() ) )->run( $job, new NoDeadline() );
		$this->assertNull( $job->state['download']['size'] );
		$this->assertTrue( $this->download( self::http(), static fn(): ?int => null )->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( UrlDownloadStep::MAX_DOWNLOAD + 1, FakeHttp::$requests[ count( FakeHttp::$requests ) - 1 ][1]['limit_response_size'] );
		$this->assertSame( $bytes, file_get_contents( $job->state['archive'] ) );

		Filters\expectApplied( 'lw_migration_max_download_bytes' )->once()->andReturn( 1000 );
		try {
			$this->download( self::http(), static fn(): ?int => null )->run( $job, new NoDeadline() );
			$this->fail( 'A download above the ceiling was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'free disk space', $e->getMessage() );
		}
	}

	/**
	 * A job whose range download (state.download) fails with a 404 on its first part.
	 */
	private function failingDownload(): Job {
		FakeHttp::install( 'x' );
		FakeHttp::$handler = static fn(): array => [
			'response' => [ 'code' => 404 ],
			'headers'  => [],
			'body'     => '',
		];
		$job                    = $this->job();
		$job->state['download'] = [
			'url'    => 'https://files.test/site.wpress',
			'size'   => 5000,
			'ranges' => true,
		];

		return $job;
	}

	public function test_without_a_failure_hook_a_failed_part_stops_the_import_as_before(): void {
		$job = $this->failingDownload();
		try {
			$this->download( self::http() )->run( $job, new NoDeadline() );
			$this->fail( 'A 404 was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'The download from https://files.test/site.wpress stopped: The server answered with HTTP 404.', $e->getMessage() );
		}
	}

	public function test_the_failure_hook_sees_the_download_failure_and_may_replace_it(): void {
		$job  = $this->failingDownload();
		$seen = [];
		$step = new UrlDownloadStep(
			$this->store,
			self::http(),
			null,
			static function ( DownloadException $e ) use ( &$seen ): void {
				$seen[] = $e->getMessage();
			}
		);
		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'A 404 was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'HTTP 404', $e->getMessage(), 'A hook that returns keeps the import failure.' );
		}
		$this->assertSame( [ 'The server answered with HTTP 404.' ], $seen );

		$replacing = new UrlDownloadStep(
			$this->store,
			self::http(),
			null,
			static function ( DownloadException $e ): void {
				throw new \DomainException( 'explained: ' . $e->getMessage() );
			}
		);
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'explained: The server answered with HTTP 404.' );
		$replacing->run( $job, new NoDeadline() );
	}
}
