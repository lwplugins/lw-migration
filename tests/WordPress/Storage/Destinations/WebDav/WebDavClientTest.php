<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\DavXml;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\FileSlice;
use LightweightPlugins\Migration\Storage\Destinations\WebDav\WebDavClient;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Preflight P-C1: every WebDAV request goes through GuardedHttp (custom
 * host): the address is checked before anything is sent, a name is pinned
 * and cURL forced, a WordPress proxy or a missing cURL refuses, and the
 * password never reaches an address the guard refuses.
 */
final class WebDavClientTest extends MonkeyTestCase {

	private const PASSWORD = 'app-pass-s3cr3t';

	private CurlHooks $curl;

	/** @var array<int, string> Hosts the resolver was asked for. */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
	}

	/**
	 * @param RecordedHttp            $http   Answers.
	 * @param array<int, string>      $ips    What every name resolves to.
	 * @param array<string, \Closure> $probes Environment checks.
	 * @param string                  $where  Server in display form.
	 */
	private function client( RecordedHttp $http, array $ips = [ '93.184.216.34' ], array $probes = [], string $where = 'https://cloud.example' ): WebDavClient {
		$guard = new AddressGuard(
			function ( string $host ) use ( $ips ): array {
				$this->asked[] = $host;
				return $ips;
			}
		);

		return new WebDavClient( 'alice', self::PASSWORD, $where, $this->curl->wrap( $http->send() ), static fn(): int => 1760000000, $guard, $probes );
	}

	public function test_sends_basic_auth_with_tls_checked_no_redirect_and_a_capped_answer(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207, '<d:multistatus xmlns:d="DAV:"/>', [ 'Content-Length' => '31' ] ) ] );

		$answer = $this->client( $http )->request( 'PROPFIND', 'https://cloud.example/remote.php/dav/files/alice/', [ 'Depth' => '1' ], DavXml::PROPFIND );

		[ $url, $args ] = $http->requests[0];
		$this->assertSame( 'https://cloud.example/remote.php/dav/files/alice/', $url );
		$this->assertSame( 'PROPFIND', $args['method'] );
		$this->assertSame( 'Basic ' . base64_encode( 'alice:' . self::PASSWORD ), $args['headers']['Authorization'] );
		$this->assertSame( '1', $args['headers']['Depth'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( WebDavClient::MAX_XML, $args['limit_response_size'] );
		$this->assertSame( 120, $args['timeout'] );
		$this->assertSame( DavXml::PROPFIND, $args['body'] );
		$this->assertSame( [ 207, 31 ], [ $answer['status'], $answer['length'] ] );
	}

	public function test_a_name_is_checked_pinned_and_sent_with_curl_forced(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );

		$this->client( $http )->request( 'MKCOL', 'https://cloud.example/f/new/' );

		$this->assertSame( [ 'cloud.example' ], $this->asked );
		$this->assertSame( 1, $this->curl->seen, 'One pin hook while the request is sent.' );
		$this->assertSame( 'WpOrg\\Requests\\Transport\\Curl', ltrim( (string) ( $this->curl->options['transport'] ?? '' ), '\\' ) );
		$this->assertTrue( $this->curl->clean(), 'Both hooks removed afterwards.' );
	}

	public function test_a_file_body_travels_as_a_slice_never_as_a_string(): void {
		$dir = TempDir::create();
		file_put_contents( $dir . '/a.wpress', str_repeat( 'x', 20 ) );
		$http  = new RecordedHttp( [ RecordedHttp::answer( 201 ) ] );
		$slice = new FileSlice( $dir . '/a.wpress', 5, 10 );

		try {
			$this->client( $http )->request( 'PUT', 'https://cloud.example/f/a.wpress.part', [], $slice, 300, 65536 );
		} finally {
			TempDir::remove( $dir );
		}

		$args = $http->requests[0][1];
		$this->assertSame( $slice, $args[ WebDavClient::BODY_KEY ] );
		$this->assertArrayNotHasKey( 'body', $args );
		$this->assertSame( 300, $args['timeout'] );
	}

	public function test_failures_are_classified_and_never_carry_the_password(): void {
		$cases = [
			[ RecordedHttp::answer( 401 ), PermanentFailure::AUTH ],
			[ RecordedHttp::answer( 507 ), PermanentFailure::FULL ],
			[ RecordedHttp::answer( 503, '', [ 'Retry-After' => '7' ] ), TransientFailure::BUSY ],
			[ new FakeWpError( 'cURL error 28: Operation timed out after 120000 milliseconds' ), TransientFailure::NETWORK ],
			[ new FakeWpError( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), PermanentFailure::INVALID ],
		];
		foreach ( $cases as [ $answer, $reason ] ) {
			try {
				$this->client( new RecordedHttp( [ $answer ] ) )->request( 'GET', 'https://cloud.example/f/x.wpress' );
				$this->fail( 'A failure.' );
			} catch ( CloudFailure $e ) {
				$this->assertSame( $reason, $e->reason );
				$this->assertStringNotContainsString( self::PASSWORD, $e->getMessage() );
				$this->assertStringNotContainsString( 'Basic', $e->getMessage() );
			}
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function refused(): array {
		return [
			'metadata address written out' => [ 'http://169.254.169.254', [ '10.0.0.1' ] ],
			'name of the metadata service' => [ 'https://cloud.example', [ '169.254.169.254' ] ],
			'one address of two'           => [ 'https://cloud.example', [ '10.0.0.1', 'fd00:ec2::254' ] ],
		];
	}

	/**
	 * @param string             $where Server.
	 * @param array<int, string> $ips   What the name resolves to.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused' )]
	public function test_a_refused_address_gets_nothing_not_even_the_password( string $where, array $ips ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );

		try {
			$this->client( $http, $ips, [], $where )->request( 'PROPFIND', $where . '/dav/' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( self::PASSWORD, $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_a_name_that_does_not_resolve_sends_nothing(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );

		try {
			$this->client( $http, [] )->request( 'PROPFIND', 'https://cloud.example/dav/' );
			$this->fail( 'Thrown.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_a_wordpress_proxy_refuses_before_sending_because_every_webdav_host_is_typed_by_an_admin(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );

		try {
			$this->client( $http, [ '93.184.216.34' ], [ 'proxy' => static fn( string $url ): bool => true ] )->request( 'PROPFIND', 'https://cloud.example/dav/' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( "The site's WordPress proxy setting prevents checking the address of", $e->getMessage() );
			$this->assertStringContainsString( 'WP_PROXY_BYPASS_HOSTS', $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_without_curl_nothing_is_sent(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );

		try {
			$this->client( $http, [ '93.184.216.34' ], [ 'curl' => static fn(): bool => false ] )->request( 'PROPFIND', 'https://cloud.example/dav/' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'cURL', $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_an_answer_the_pin_never_saw_is_dropped(): void {
		$http             = new RecordedHttp( [ RecordedHttp::answer( 207, '<d:multistatus xmlns:d="DAV:"/>' ) ] );
		$this->curl->curl = false;

		try {
			$this->client( $http )->request( 'PROPFIND', 'https://cloud.example/dav/' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertTrue( $this->curl->clean() );
	}

	public function test_a_url_on_another_server_is_refused_before_anything_is_sent(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );

		foreach ( [ 'https://cloud.example.evil.test/dav/', 'http://cloud.example/dav/', 'https://cloud.example:8443/dav/', 'https://cloud.example@169.254.169.254/' ] as $url ) {
			try {
				$this->client( $http )->request( 'PROPFIND', $url );
				$this->fail( 'Thrown for ' . $url );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
			}
		}
		$this->assertSame( [], $http->requests );
		$this->assertSame( [], $this->asked );
	}

	/** Task 3 m3: a URL on another server has its own message, not the address guard's. */
	public function test_a_url_on_another_server_says_so(): void {
		try {
			$this->client( new RecordedHttp() )->request( 'GET', 'https://other.example/status.php' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertStringContainsString( 'another server', $e->getMessage() );
			$this->assertStringContainsString( 'https://cloud.example', $e->getMessage() );
			$this->assertStringNotContainsString( 'other.example', $e->getMessage() );
			$this->assertStringNotContainsString( 'not allowed', $e->getMessage() );
		}
	}

	/**
	 * Task 3 m2: the server address is scheme://host[:port] only (lower-case
	 * host, IPv6 in brackets); anything else sends nothing.
	 */
	public function test_a_server_address_that_is_not_plain_sends_nothing(): void {
		foreach ( [ 'https://Cloud.Example', 'https://cloud.example/dav', 'ftp://cloud.example', 'https://cloud.example:', 'https://u@cloud.example', 'https://::1', 'https://cloud.example:123456', 'https://cloud.example\x' ] as $where ) {
			$http = new RecordedHttp( [ RecordedHttp::answer( 207 ) ] );
			try {
				$this->client( $http, [ '93.184.216.34' ], [], $where )->request( 'PROPFIND', $where . '/x/' );
				$this->fail( 'Thrown for ' . $where );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason, $where );
				$this->assertStringNotContainsString( $where, $e->getMessage() );
			}
			$this->assertSame( [], $http->requests, $where );
		}
		$this->assertSame( [], $this->asked );
		$ok = new RecordedHttp( [ RecordedHttp::answer( 207 ), RecordedHttp::answer( 207 ) ] );
		$this->client( $ok, [ '93.184.216.34' ], [], 'http://[fd00::5]:8080' )->request( 'PROPFIND', 'http://[fd00::5]:8080/x/' );
		$this->client( $ok, [ '93.184.216.34' ], [], 'https://cloud.example:8443' )->request( 'PROPFIND', 'https://cloud.example:8443/x/' );
		$this->assertCount( 2, $ok->requests );
	}
}
