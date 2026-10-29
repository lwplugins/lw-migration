<?php
/**
 * Tests for the range downloader.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Http;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Tests\Support\FakeHttp;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RangeDownloaderTest extends MonkeyTestCase {

	private string $dir;

	/** @var array<int, int> Seconds the downloader waited between attempts. */
	private array $sleeps = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	private function http( int $chunk = RangeDownloader::CHUNK, int $retries = 3, int $timeout = 60 ): RangeDownloader {
		return new RangeDownloader(
			$chunk,
			$retries,
			$timeout,
			function ( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		);
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_probe_reports_ranges_size_and_first_header(): void {
		FakeHttp::install( str_repeat( 'a', 5000 ) . 'tail' );
		$probe = $this->http()->probe( 'https://files.test/a.wpress' );

		$this->assertSame( 206, $probe['status'] );
		$this->assertTrue( $probe['ranges'] );
		$this->assertSame( 5004, $probe['size'] );
		$this->assertSame( str_repeat( 'a', 4377 ), $probe['head'] );
		$this->assertSame( 'application/octet-stream', $probe['contentType'] );
		$this->assertSame( 'bytes=0-4376', FakeHttp::$requests[0][1]['headers']['Range'] );
		$this->assertSame( 4377, FakeHttp::$requests[0][1]['limit_response_size'] );
	}

	public function test_probe_of_a_server_without_ranges_reads_only_the_first_header(): void {
		FakeHttp::install( str_repeat( 'b', 9000 ), false, 'Application/Octet-Stream' );
		$probe = $this->http()->probe( 'https://files.test/a.wpress' );

		$this->assertSame( 200, $probe['status'] );
		$this->assertFalse( $probe['ranges'] );
		$this->assertSame( 9000, $probe['size'] );
		$this->assertSame( 4377, strlen( $probe['head'] ) );
		$this->assertSame( 'application/octet-stream', $probe['contentType'] );
		$this->assertSame( 4377, FakeHttp::$requests[0][1]['limit_response_size'] );
	}

	public function test_probe_refuses_an_error_status_and_a_part_that_does_not_start_at_zero(): void {
		FakeHttp::install( 'x' );
		FakeHttp::$handler = static fn() => self::response( 404, [], 'missing' );
		try {
			$this->http()->probe( 'https://files.test/a.wpress' );
			$this->fail( 'A 404 was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertStringContainsString( '404', $e->getMessage() );
		}

		FakeHttp::$handler = static fn() => self::response( 206, [ 'content-range' => 'bytes 100-4476/9000' ], str_repeat( 'c', 4377 ) );
		$this->expectException( DownloadException::class );
		$this->http()->probe( 'https://files.test/a.wpress' );
	}

	public function test_a_part_of_unknown_total_size_counts_as_no_range_support(): void {
		FakeHttp::install( 'x' );
		FakeHttp::$handler = static fn() => self::response( 206, [ 'content-range' => 'bytes 0-4376/*' ], str_repeat( 'c', 4377 ) );
		$probe             = $this->http()->probe( 'https://files.test/a.wpress' );

		$this->assertFalse( $probe['ranges'] );
		$this->assertNull( $probe['size'] );
	}

	public function test_every_request_goes_through_the_safe_api_without_transport_redirects_and_with_a_timeout(): void {
		FakeHttp::install( 'abcdefghij' );
		$http = $this->http( 4, 3, 45 );
		$http->probe( 'https://files.test/x' );
		$http->page( 'https://files.test/x', 100 );
		$http->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3 );
		FakeHttp::$ranges = false;
		$http->fetchAll( 'https://files.test/x', $this->dir . '/all', 10 );

		$this->assertCount( 4, FakeHttp::$requests );
		foreach ( FakeHttp::$requests as [ $url, $args ] ) {
			$this->assertSame( 'https://files.test/x', $url );
			$this->assertSame( 0, $args['redirection'], 'Redirects are followed by hand, each hop checked (issue #2).' );
			$this->assertGreaterThan( 0, $args['timeout'] );
			$this->assertArrayNotHasKey( 'sslverify', $args, 'Certificate checks stay at the WordPress default.' );
			$this->assertArrayNotHasKey( 'reject_unsafe_urls', $args );
			$this->assertArrayHasKey( 'limit_response_size', $args, 'Every answer is bounded.' );
		}
		$this->assertSame( 45, FakeHttp::$requests[0][1]['timeout'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function notHttpUrls(): array {
		return [
			'file'     => [ 'file:///etc/passwd' ],
			'ftp'      => [ 'ftp://files.test/a.wpress' ],
			'php'      => [ 'php://filter/resource=/etc/passwd' ],
			'relative' => [ '/wp-content/a.wpress' ],
			'no host'  => [ 'https:///a.wpress' ],
		];
	}

	#[DataProvider( 'notHttpUrls' )]
	public function test_a_url_that_is_not_http_is_refused_without_a_request( string $url ): void {
		FakeHttp::install( 'abc' );
		try {
			$this->http()->probe( $url );
			$this->fail( 'A non-http URL was requested.' );
		} catch ( DownloadException $e ) {
			$this->assertSame( [], FakeHttp::$requests );
		}
	}

	public function test_fetch_range_appends_and_retries(): void {
		FakeHttp::install( 'abcdefghij' );
		$target = $this->dir . '/t';
		file_put_contents( $target, 'abc' );
		FakeHttp::$failNext = 2;

		$this->assertSame( 4, $this->http( 4, 3 )->fetchRange( 'https://files.test/x', $target, 3, 6 ) );
		$this->assertSame( 'abcdefg', file_get_contents( $target ) );
		$this->assertFileDoesNotExist( $target . RangeDownloader::PART_SUFFIX );
		$this->assertCount( 3, FakeHttp::$requests );
		$last = FakeHttp::$requests[2][1];
		$this->assertSame( 'bytes=3-6', $last['headers']['Range'] );
		$this->assertSame( 5, $last['limit_response_size'], 'One byte more than wanted shows an oversized answer.' );
		$this->assertSame( [ 1, 2 ], $this->sleeps );
		$this->assertTrue( $last['stream'] );
		$this->assertSame( $target . RangeDownloader::PART_SUFFIX, $last['filename'] );
	}

	public function test_retries_a_failed_chunk_then_gives_up(): void {
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$failNext = 3;
		try {
			$this->http( 4, 3 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3 );
			$this->fail( 'The third failure was not reported.' );
		} catch ( DownloadException $e ) {
			$this->assertCount( 3, FakeHttp::$requests );
			$this->assertSame( [ 1, 2 ], $this->sleeps );
			$this->assertFileDoesNotExist( $this->dir . '/t' . RangeDownloader::PART_SUFFIX );
		}
	}

	public function test_a_server_that_ignores_the_range_is_an_error_for_chunks(): void {
		FakeHttp::install( 'abcdefghij', false );
		$target = $this->dir . '/t';
		try {
			$this->http( 4, 1 )->fetchRange( 'https://files.test/x', $target, 0, 3 );
			$this->fail( 'A 200 answer was accepted as a part.' );
		} catch ( DownloadException $e ) {
			$this->assertFileDoesNotExist( $target . RangeDownloader::PART_SUFFIX );
			$this->assertFalse( is_file( $target ) && filesize( $target ) > 0, 'Nothing was appended.' );
		}
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function statuses(): array {
		return [
			'too many requests' => [ 429, 3 ],
			'unavailable'       => [ 503, 3 ],
			'bad gateway'       => [ 502, 3 ],
			'not found'         => [ 404, 1 ],
			'forbidden'         => [ 403, 1 ],
		];
	}

	#[DataProvider( 'statuses' )]
	public function test_only_passing_failures_are_retried_with_a_growing_pause( int $status, int $requests ): void {
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$handler = static fn() => self::response( $status, [], 'busy' );

		try {
			$this->http( 4, 3 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3 );
			$this->fail( 'An error answer was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertStringContainsString( (string) $status, $e->getMessage() );
		}
		$this->assertCount( $requests, FakeHttp::$requests );
		$this->assertSame( 3 === $requests ? [ 1, 2 ] : [], $this->sleeps );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function transportErrors(): array {
		return [
			'blocked by the site'    => [ 'http_request_not_executed', 'User has blocked requests through HTTP to the URL: https://files.test/x.', 1 ],
			'refused address'        => [ 'http_request_failed', 'A valid URL was not provided.', 1 ],
			'refused address, local' => [ 'http_request_failed', 'Nem adtál meg érvényes URL-t.', 1 ],
			'timeout'                => [ 'http_request_failed', 'cURL error 28: Operation timed out', 3 ],
			'reset'                  => [ 'http_request_failed', 'cURL error 56: Connection reset by peer', 3 ],
			'too many redirects'     => [ 'http_request_failed', 'Too many redirects.', 3 ],
		];
	}

	#[DataProvider( 'transportErrors' )]
	public function test_a_refusal_of_the_safe_api_is_not_tried_again( string $code, string $message, int $requests ): void {
		Functions\when( '__' )->alias( static fn( $text ) => 'A valid URL was not provided.' === $text ? 'Nem adtál meg érvényes URL-t.' : $text );
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$failNext    = 3;
		FakeHttp::$failCode    = $code;
		FakeHttp::$failMessage = $message;

		try {
			$this->http( 4, 3 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3 );
			$this->fail( 'The failure was not reported.' );
		} catch ( DownloadException $e ) {
			$this->assertStringContainsString( 'The download failed:', $e->getMessage() );
		}
		$this->assertCount( $requests, FakeHttp::$requests );
		$this->assertSame( 3 === $requests ? [ 1, 2 ] : [], $this->sleeps );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function wrongParts(): array {
		return [
			'other start'      => [ 'bytes 2-5/10', 'cdef' ],
			'beyond the end'   => [ 'bytes 3-9/10', 'defghij' ],
			'no content range' => [ '', 'defg' ],
			'short body'       => [ 'bytes 3-6/10', 'de' ],
			'end before start' => [ 'bytes 3-2/10', '' ],
			'end past total'   => [ 'bytes 3-6/5', 'defg' ],
			'other total'      => [ 'bytes 3-6/99', 'defg' ],
			'oversized body'   => [ 'bytes 3-6/10', 'defgh' ],
		];
	}

	#[DataProvider( 'wrongParts' )]
	public function test_a_part_that_is_not_exactly_the_requested_range_is_never_appended( string $contentRange, string $body ): void {
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$handler = static fn() => self::response( 206, '' === $contentRange ? [] : [ 'content-range' => $contentRange ], $body );
		$target            = $this->dir . '/t';
		file_put_contents( $target, 'abc' );

		try {
			$this->http( 4, 2 )->fetchRange( 'https://files.test/x', $target, 3, 6, 10 );
			$this->fail( 'A wrong part was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertSame( 'abc', file_get_contents( $target ) );
			$this->assertFileDoesNotExist( $target . RangeDownloader::PART_SUFFIX );
		}
	}

	public function test_a_content_length_that_differs_from_the_range_is_refused(): void {
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$handler = static fn() => self::response(
			206,
			[
				'content-range'  => 'bytes 0-3/10',
				'content-length' => '7',
			],
			'abcd'
		);

		$this->expectException( DownloadException::class );
		$this->http( 4, 1 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3 );
	}

	public function test_a_part_of_unknown_or_unchecked_total_is_accepted(): void {
		FakeHttp::install( 'abcdefghij' );
		FakeHttp::$handler = static fn() => self::response( 206, [ 'content-range' => 'bytes 0-3/*' ], 'abcd' );
		$this->assertSame( 4, $this->http( 4, 1 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 0, 3, 10 ) );

		FakeHttp::$handler = static fn() => self::response( 206, [ 'content-range' => 'bytes 4-7/12' ], 'efgh' );
		$this->assertSame( 4, $this->http( 4, 1 )->fetchRange( 'https://files.test/x', $this->dir . '/t', 4, 7 ) );
		$this->assertSame( 'abcdefgh', file_get_contents( $this->dir . '/t' ) );
	}

	public function test_a_target_that_does_not_end_at_the_range_start_is_refused_without_a_request(): void {
		FakeHttp::install( 'abcdefghij' );
		$target = $this->dir . '/t';
		file_put_contents( $target, 'abcde' );

		try {
			$this->http( 4, 3 )->fetchRange( 'https://files.test/x', $target, 3, 6 );
			$this->fail( 'Bytes would have been appended at the wrong place.' );
		} catch ( DownloadException $e ) {
			$this->assertSame( [], FakeHttp::$requests );
			$this->assertSame( 'abcde', file_get_contents( $target ) );
		}
	}

	public function test_fetch_all_streams_the_whole_file(): void {
		FakeHttp::install( 'whole file', false );
		$this->assertSame( 10, $this->http()->fetchAll( 'https://files.test/x', $this->dir . '/all' ) );
		$this->assertSame( 'whole file', file_get_contents( $this->dir . '/all' ) );
		$this->assertFileDoesNotExist( $this->dir . '/all' . RangeDownloader::PART_SUFFIX );

		$args = FakeHttp::$requests[0][1];
		$this->assertTrue( $args['stream'] );
		$this->assertSame( $this->dir . '/all' . RangeDownloader::PART_SUFFIX, $args['filename'] );
		$this->assertArrayNotHasKey( 'headers', $args );
	}

	public function test_fetch_all_refuses_more_bytes_than_announced(): void {
		FakeHttp::install( 'whole file and more', false );
		FakeHttp::$handler = static fn() => self::response( 200, [ 'content-length' => '10' ], 'whole file and more' );

		try {
			$this->http( 4, 3 )->fetchAll( 'https://files.test/x', $this->dir . '/all', 10 );
			$this->fail( 'An oversized answer was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertFileDoesNotExist( $this->dir . '/all' );
			$this->assertFileDoesNotExist( $this->dir . '/all' . RangeDownloader::PART_SUFFIX );
		}
		$this->assertSame( 11, FakeHttp::$requests[0][1]['limit_response_size'] );
		$this->assertCount( 1, FakeHttp::$requests, 'A server that sends too much is not asked again.' );
	}

	public function test_fetch_all_of_unknown_size_stops_at_the_free_space(): void {
		FakeHttp::install( str_repeat( 'x', 50 ), false );
		FakeHttp::$handler = static fn() => self::response( 200, [], str_repeat( 'x', 50 ) );

		try {
			$this->http( 4, 3 )->fetchAll( 'https://files.test/x', $this->dir . '/all', null, 20 );
			$this->fail( 'A download beyond the free space was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertSame( 'The download is larger than the free disk space allows.', $e->getMessage() );
		}
		$this->assertSame( 21, FakeHttp::$requests[0][1]['limit_response_size'] );
		$this->assertCount( 1, FakeHttp::$requests );
		$this->assertFileDoesNotExist( $this->dir . '/all' );
		$this->assertFileDoesNotExist( $this->dir . '/all' . RangeDownloader::PART_SUFFIX );
	}

	public function test_fetch_all_of_unknown_size_within_the_free_space_succeeds(): void {
		FakeHttp::install( str_repeat( 'x', 20 ), false );
		FakeHttp::$handler = static fn() => self::response( 200, [], str_repeat( 'x', 20 ) );

		$this->assertSame( 20, $this->http()->fetchAll( 'https://files.test/x', $this->dir . '/all', null, 20 ) );
		$this->assertSame( 20, filesize( $this->dir . '/all' ) );
	}

	public function test_fetch_all_of_a_known_size_above_the_free_space_sends_no_request(): void {
		FakeHttp::install( 'whole file', false );
		try {
			$this->http()->fetchAll( 'https://files.test/x', $this->dir . '/all', 10, 9 );
			$this->fail( 'A download beyond the free space was started.' );
		} catch ( DownloadException $e ) {
			$this->assertSame( 'The download is larger than the free disk space allows.', $e->getMessage() );
		}
		$this->assertSame( [], FakeHttp::$requests );
	}

	public function test_fetch_all_refuses_a_file_of_the_wrong_size(): void {
		FakeHttp::install( 'short', false );
		try {
			$this->http( 4, 2 )->fetchAll( 'https://files.test/x', $this->dir . '/all', 10 );
			$this->fail( 'A file that changed its size was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertFileDoesNotExist( $this->dir . '/all' );
			$this->assertFileDoesNotExist( $this->dir . '/all' . RangeDownloader::PART_SUFFIX );
			$this->assertCount( 1, FakeHttp::$requests );
		}

		FakeHttp::$requests = [];
		FakeHttp::$handler  = static fn() => self::response( 200, [ 'content-length' => '10' ], 'short' );
		try {
			$this->http( 4, 2 )->fetchAll( 'https://files.test/x', $this->dir . '/all' );
			$this->fail( 'A cut transfer was accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertFileDoesNotExist( $this->dir . '/all' );
		}
		$this->assertCount( 2, FakeHttp::$requests, 'A cut transfer is tried again.' );
		$this->assertSame( [ 1 ], $this->sleeps );
	}

	public function test_fetch_all_refuses_a_partial_or_error_answer(): void {
		FakeHttp::install( 'whole file' );
		FakeHttp::$handler = static fn() => self::response( 206, [ 'content-range' => 'bytes 0-4/10' ], 'whole' );
		$this->expectException( DownloadException::class );
		$this->http( 4, 1 )->fetchAll( 'https://files.test/x', $this->dir . '/all' );
	}

	public function test_resume_cuts_back_extra_bytes_and_restarts_a_short_file(): void {
		$target = $this->dir . '/t';
		file_put_contents( $target, 'abcdefGARBAGE' );
		file_put_contents( $target . RangeDownloader::PART_SUFFIX, 'stale' );
		$this->assertSame( 6, RangeDownloader::resumeFrom( $target, 6 ) );
		$this->assertSame( 'abcdef', file_get_contents( $target ) );
		$this->assertFileDoesNotExist( $target . RangeDownloader::PART_SUFFIX );

		$this->assertSame( 6, RangeDownloader::resumeFrom( $target, 6 ) );
		$this->assertSame( 'abcdef', file_get_contents( $target ) );

		$this->assertSame( 0, RangeDownloader::resumeFrom( $target, 9 ), 'Missing bytes are never padded.' );
		$this->assertSame( '', file_get_contents( $target ) );

		$this->assertSame( 0, RangeDownloader::resumeFrom( $this->dir . '/new', 4 ) );
		$this->assertSame( '', file_get_contents( $this->dir . '/new' ) );
	}

	public function test_error_messages_carry_no_credentials_or_query(): void {
		$url = 'https://admin:s3cret@files.test/dl/site.wpress?token=TOPSECRET&dl=1#frag';
		FakeHttp::install( 'abc' );
		FakeHttp::$failNext    = 1;
		FakeHttp::$failMessage = 'cURL error 28: Operation timed out for ' . $url . ' (redirected from https://files.test/s?sig=SIGNATURE)';

		try {
			$this->http( 4, 1 )->probe( $url );
			$this->fail( 'The failure was not reported.' );
		} catch ( DownloadException $e ) {
			$message = $e->getMessage();
			$this->assertStringNotContainsString( 's3cret', $message );
			$this->assertStringNotContainsString( 'admin', $message );
			$this->assertStringNotContainsString( 'TOPSECRET', $message );
			$this->assertStringNotContainsString( 'SIGNATURE', $message );
			$this->assertStringContainsString( 'https://files.test/…/site.wpress', $message );
		}
	}

	public function test_a_blocked_request_message_shows_no_part_of_a_spaced_address(): void {
		$url = 'https://example.com/a b/SHAREKEY123/site.wpress?token=TOPSECRET&x="q"';
		FakeHttp::install( 'abc' );
		FakeHttp::$failNext    = 1;
		FakeHttp::$failMessage = 'User has blocked requests through HTTP to the URL: ' . $url . '.';

		$this->expectException( DownloadException::class );
		$this->expectExceptionMessage( 'The download failed: User has blocked requests through HTTP to the URL: https://example.com/…/site.wpress.' );
		$this->http( 4, 1 )->probe( $url );
	}

	public function test_signed_requests_get_fresh_headers_and_never_follow_redirects(): void {
		FakeHttp::install( str_repeat( 'x', 3000 ) );
		$calls = 0;
		$http  = new RangeDownloader(
			1000,
			3,
			60,
			static function (): void {
			},
			static function () use ( &$calls ): array {
				++$calls;
				return [ 'X-LWM-Nonce' => sprintf( '%032x', $calls ) ];
			}
		);
		$target = $this->dir . '/download';
		$http->fetchRange( 'https://b.example/index.php?rest_route=/x', $target, 0, 999, 3000 );
		$http->fetchRange( 'https://b.example/index.php?rest_route=/x', $target, 1000, 1999, 3000 );

		$this->assertSame( 2, $calls );
		$this->assertCount( 2, FakeHttp::$requests );
		foreach ( FakeHttp::$requests as $i => [ , $args ] ) {
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( sprintf( '%032x', $i + 1 ), $args['headers']['X-LWM-Nonce'] );
			$this->assertArrayHasKey( 'Range', $args['headers'] );
		}
		$this->assertSame( str_repeat( 'x', 2000 ), file_get_contents( $target ) );
	}

	public function test_an_unsigned_downloader_still_follows_redirects(): void {
		FakeHttp::install( str_repeat( 'x', 3000 ) );
		$hops              = 0;
		FakeHttp::$handler = static function ( string $url, array $args ) use ( &$hops ): array {
			if ( ++$hops <= 5 ) {
				return self::response( 302, [ 'location' => '/hop' . $hops ], '' );
			}

			return self::response( 206, [ 'content-range' => 'bytes 0-999/3000' ], str_repeat( 'x', 1000 ) );
		};
		$this->http( 1000 )->fetchRange( 'https://files.test/x', $this->dir . '/download', 0, 999, 3000 );

		$this->assertCount( 6, FakeHttp::$requests, 'Five redirects, followed by hand.' );
		$this->assertSame( 'https://files.test/hop5', FakeHttp::$requests[5][0] );
		foreach ( FakeHttp::$requests as [ , $args ] ) {
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( [ 'Range' ], array_keys( $args['headers'] ) );
		}
	}

	public function test_a_sixth_redirect_is_not_followed(): void {
		FakeHttp::install( 'x' );
		$hops              = 0;
		FakeHttp::$handler = static function () use ( &$hops ): array {
			return self::response( 302, [ 'location' => '/hop' . ( ++$hops ) ], '' );
		};
		try {
			$this->http( 1000, 1 )->probe( 'https://files.test/x' );
			$this->fail( 'A redirect loop is refused.' );
		} catch ( DownloadException $e ) {
			$this->assertStringContainsString( '302', $e->getMessage() );
		}
		$this->assertCount( 6, FakeHttp::$requests );
	}

	/**
	 * Issue #2: the address is checked before anything is sent, also on every redirect.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function internalTargets(): array {
		return [
			'metadata literal'   => [ 'http://169.254.169.254/latest/meta-data/', null ],
			'alibaba metadata'   => [ 'http://100.100.100.200/latest/meta-data/', null ],
			'cgnat'              => [ 'http://100.64.0.1/a.wpress', null ],
			'benchmark'          => [ 'http://198.18.0.1/a.wpress', null ],
			'redirect to metada' => [ 'https://files.test/x', 'http://169.254.169.254/latest/meta-data/' ],
		];
	}

	#[DataProvider( 'internalTargets' )]
	#[RunInSeparateProcess]
	public function test_an_internal_address_is_refused_before_a_request( string $url, ?string $redirect ): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		FakeHttp::install( 'x' );
		if ( null !== $redirect ) {
			FakeHttp::$handler = static fn(): array => self::response( 302, [ 'location' => $redirect ], '' );
		}
		try {
			$this->http()->probe( $url );
			$this->fail( 'Refused.' );
		} catch ( DownloadException $e ) {
			$this->assertTrue( $e->isAddressRefused() );
			$this->assertFalse( $e->isPassing(), 'Not tried again.' );
		}
		$this->assertCount( null === $redirect ? 0 : 1, FakeHttp::$requests );
	}

	public function test_every_retry_is_signed_again(): void {
		FakeHttp::install( str_repeat( 'x', 3000 ) );
		FakeHttp::$failNext = 1;
		$calls              = 0;
		$http               = new RangeDownloader(
			1000,
			3,
			60,
			static function (): void {
			},
			static function () use ( &$calls ): array {
				++$calls;
				return [ 'X-LWM-Nonce' => sprintf( '%032x', $calls ) ];
			}
		);
		$http->fetchRange( 'https://b.example/x', $this->dir . '/download', 0, 999, 3000 );

		$this->assertCount( 2, FakeHttp::$requests );
		$this->assertNotSame( FakeHttp::$requests[0][1]['headers']['X-LWM-Nonce'], FakeHttp::$requests[1][1]['headers']['X-LWM-Nonce'] );
		$this->assertSame( 0, FakeHttp::$requests[1][1]['redirection'] );
	}

	/**
	 * @param array<string, string> $headers Lower-case header names.
	 * @return array<string, mixed>
	 */
	private static function response( int $code, array $headers, string $body ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => $headers,
			'body'     => $body,
		];
	}
}
