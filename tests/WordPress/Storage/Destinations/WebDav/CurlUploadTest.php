<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\FileSlice;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Plan D22 with preflight P-C1: the upload options are set by the guard's
 * one-shot pin hook (CurlUpload is its configure closure). Here a real cURL
 * handle runs them against a file:// URL, so what cURL would send is what
 * lands in the file — no network. The 150 MiB PUT is the Docker harness'.
 */
final class CurlUploadTest extends MonkeyTestCase {

	private string $dir;

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		$this->dir  = TempDir::create();
		file_put_contents( $this->dir . '/b.wpress', 'abcdef' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	/**
	 * A transport that runs the handle the hooks configured, writing the body to $out.
	 *
	 * @param string $out Local file the upload lands in.
	 */
	private function runsTheHandle( string $out ): \Closure {
		return function () use ( $out ): array {
			curl_setopt( $this->curl->handle, CURLOPT_URL, 'file://' . $out );
			$this->assertTrue( curl_exec( $this->curl->handle ), (string) curl_error( $this->curl->handle ) );

			return RecordedHttp::answer( 201 );
		};
	}

	private function client( RecordedHttp $http, string $where = 'https://cloud.example' ): WebDavClient {
		return new WebDavClient( 'alice', 'pw', $where, $this->curl->wrap( $http->send() ), static fn(): int => 1760000000, new AddressGuard( static fn(): array => [ '93.184.216.34' ] ) );
	}

	public function test_the_pin_hook_streams_exactly_the_slice_and_is_removed(): void {
		$http = new RecordedHttp( [ $this->runsTheHandle( $this->dir . '/out' ) ] );

		$answer = $this->client( $http )->request( 'PUT', 'https://cloud.example/f/b.wpress.part', [], new FileSlice( $this->dir . '/b.wpress', 2, 3 ) );

		$this->assertSame( 201, $answer['status'] );
		$this->assertSame( 'cde', file_get_contents( $this->dir . '/out' ) );
		$this->assertSame( 1, $this->curl->seen, 'One hook carries the pin and the body.' );
		$this->assertTrue( $this->curl->clean() );
	}

	public function test_an_address_written_out_still_streams_through_forced_curl(): void {
		$http = new RecordedHttp( [ $this->runsTheHandle( $this->dir . '/out' ) ] );

		$this->client( $http, 'http://10.0.0.5:8080' )->request( 'PUT', 'http://10.0.0.5:8080/f/b.wpress.part', [], new FileSlice( $this->dir . '/b.wpress', 0, 6 ) );

		$this->assertSame( 'abcdef', file_get_contents( $this->dir . '/out' ) );
		$this->assertSame( 'WpOrg\\Requests\\Transport\\Curl', ltrim( (string) ( $this->curl->options['transport'] ?? '' ), '\\' ) );
		$this->assertTrue( $this->curl->clean() );
	}

	public function test_a_request_that_did_not_go_through_curl_is_refused(): void {
		$this->curl->curl = false;

		foreach ( [ 'https://cloud.example', 'http://10.0.0.5:8080' ] as $where ) {
			try {
				$this->client( new RecordedHttp( [ RecordedHttp::answer( 201 ) ] ), $where )->request( 'PUT', $where . '/f/b.wpress.part', [], new FileSlice( $this->dir . '/b.wpress', 0, 6 ) );
				$this->fail( 'Without cURL the body was empty.' );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
			$this->assertTrue( $this->curl->clean() );
		}
	}

	public function test_the_hooks_are_removed_when_the_request_throws(): void {
		$http = new RecordedHttp(
			[
				static function (): array {
					throw new \RuntimeException( 'transport broke' );
				},
			]
		);

		try {
			$this->client( $http )->request( 'PUT', 'https://cloud.example/x', [], new FileSlice( $this->dir . '/b.wpress', 0, 6 ) );
			$this->fail( 'Thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'transport broke', $e->getMessage() );
		}
		$this->assertTrue( $this->curl->clean() );
	}
}
