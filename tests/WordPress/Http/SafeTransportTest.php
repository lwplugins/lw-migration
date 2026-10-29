<?php
/**
 * Tests for the guarded transport of connections and URL imports (issue #2).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Http;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Http\SafeTransport;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\Support\SafeWeb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class SafeTransportTest extends MonkeyTestCase {

	/** @var array<int, array{0: string, 1: array<string, mixed>}> Requests that reached the transport. */
	private array $sent = [];

	/** @var array<int, array<string, mixed>|FakeWpError> Answers in order (default 200). */
	private array $answers = [];

	private CurlHooks $curl;

	/** @var array<string, array<int, string>> Addresses by host name. */
	private array $dns = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		RecordedHttp::install();
		Functions\when( 'get_option' )->justReturn( 'https://this.example' );
		$this->curl = SafeWeb::install(
			function ( string $url, array $args ) {
				$this->sent[] = [ $url, $args ];

				return [] === $this->answers ? RecordedHttp::answer( 200, 'ok' ) : array_shift( $this->answers );
			}
		);
		AddressGuard::useResolver( fn( string $host ): array => $this->dns[ $host ] ?? [ '93.184.216.34' ] );
	}

	private static function http(): SafeTransport {
		return new SafeTransport( null, null, [ 'proxy' => static fn(): bool => false ] );
	}

	/**
	 * @param mixed $response Transport answer.
	 */
	private function assertRefused( $response ): void {
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'http_request_failed', $response->get_error_code() );
		$this->assertSame( 'A valid URL was not provided.', $response->get_error_message(), 'Core\'s own refusal, so every caller classifies it.' );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function refusedTargets(): array {
		return [
			'metadata literal'        => [ 'http://169.254.169.254/latest/meta-data/', [] ],
			'alibaba metadata'        => [ 'http://100.100.100.200/latest/meta-data/', [] ],
			'cgnat literal'           => [ 'http://100.64.0.1/', [] ],
			'benchmark with a port'   => [ 'http://198.18.0.1:8080/', [] ],
			'name on link-local'      => [ 'https://meta.example/', [ '169.254.169.254' ] ],
			'name on cgnat'           => [ 'https://cgnat.example/x.wpress', [ '100.100.100.200' ] ],
			'name on multicast'       => [ 'https://multi.example/', [ '224.0.0.1' ] ],
			'name on reserved'        => [ 'https://reserved.example/', [ '240.0.0.1' ] ],
			'one bad address of many' => [ 'https://mixed.example/', [ '93.184.216.34', '198.19.0.1' ] ],
			'another scheme'          => [ 'ftp://files.example/x', [] ],
		];
	}

	/**
	 * @param array<int, string> $ips What the host resolves to.
	 */
	#[DataProvider( 'refusedTargets' )]
	public function test_a_refused_range_is_never_reached( string $url, array $ips ): void {
		$host               = (string) parse_url( $url, PHP_URL_HOST );
		$this->dns[ $host ] = $ips;
		Filters\expectApplied( 'http_request_host_is_external' )->andReturn( true ); // Even an allowed host never opens these.

		$this->assertRefused( self::http()->send( $url, [] ) );
		$this->assertSame( [], $this->sent );
	}

	public function test_loopback_and_private_addresses_need_a_host_wordpress_allows(): void {
		$this->dns['dev.test'] = [ '127.0.0.1' ];
		$this->dns['lan.test'] = [ '192.168.1.20' ];

		$this->assertRefused( self::http()->send( 'http://dev.test/x', [] ) );
		$this->assertRefused( self::http()->send( 'http://lan.test/x', [] ) );
		$this->assertRefused( self::http()->send( 'http://127.0.0.1:8093/x', [] ) );
		$this->assertSame( [], $this->sent );

		Filters\expectApplied( 'http_request_host_is_external' )->with( false, 'dev.test', 'http://dev.test/x' )->andReturn( true );
		$this->assertIsArray( self::http()->send( 'http://dev.test/x', [] ) );
		$this->assertCount( 1, $this->sent, 'The seam local and test setups use (http_request_host_is_external).' );
	}

	public function test_this_sites_own_host_may_be_internal(): void {
		$this->dns['this.example'] = [ '10.0.0.7' ];

		$this->assertIsArray( self::http()->send( 'https://this.example/x.wpress', [] ) );
		$this->assertCount( 1, $this->sent );
	}

	public function test_a_name_that_does_not_resolve_is_not_sent(): void {
		$this->dns['gone.example'] = [];

		$response = self::http()->send( 'https://gone.example/x', [] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertStringStartsWith( 'cURL error 6:', $response->get_error_message(), 'Classified like cURL\'s own lookup failure.' );
		$this->assertSame( [], $this->sent );
	}

	/**
	 * The connection goes to the checked address, whatever another lookup
	 * would say: a listening socket on the loopback takes it (nothing is sent
	 * anywhere else) and cURL's primary address shows where it went.
	 */
	public function test_the_checked_address_is_pinned_and_redirects_are_off(): void {
		$server = stream_socket_server( 'tcp://127.0.0.1:0' );
		$this->assertIsResource( $server );
		$port                         = (int) substr( (string) strrchr( (string) stream_socket_get_name( $server, false ), ':' ), 1 );
		$url                          = 'http://lwm-pin.invalid:' . $port . '/x';
		$this->dns['lwm-pin.invalid'] = [ '127.0.0.1' ];
		$connected                    = '';
		$seen                         = [];
		$curl                         = $this->curl;
		Functions\when( 'wp_safe_remote_request' )->alias(
			$curl->wrap(
				static function ( string $target, array $args ) use ( $curl, &$connected, &$seen ): array {
					$seen = $args;
					curl_setopt_array(
						$curl->handle,
						[
							CURLOPT_URL               => $target,
							CURLOPT_CONNECTTIMEOUT_MS => 2000,
							CURLOPT_TIMEOUT_MS        => 300,
							CURLOPT_RETURNTRANSFER    => true,
						]
					);
					curl_exec( $curl->handle );
					$connected = curl_getinfo( $curl->handle, CURLINFO_PRIMARY_IP );

					return RecordedHttp::answer( 200 );
				}
			)
		);

		try {
			$response = ( new SafeTransport( null, static fn(): bool => true, [ 'proxy' => static fn(): bool => false ] ) )->send( $url, [ 'redirection' => 5 ] );
		} finally {
			fclose( $server );
		}

		$this->assertIsArray( $response );
		$this->assertSame( '127.0.0.1', $connected );
		$this->assertSame( 0, $seen['redirection'], 'The transport never follows a redirect itself.' );
		$this->assertTrue( $this->curl->clean(), 'The one-shot hooks are gone.' );
	}

	public function test_a_transport_that_cannot_be_pinned_fails_closed(): void {
		$this->curl->curl = false;

		$response = self::http()->send( 'https://files.example/x', [] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'http_request_not_executed', $response->get_error_code() );
		$this->assertStringContainsString( 'cURL is needed', $response->get_error_message() );
	}

	public function test_without_curl_nothing_is_sent(): void {
		$http = new SafeTransport( null, null, [ 'curl' => static fn(): bool => false, 'proxy' => static fn(): bool => false ] );

		$response = $http->send( 'https://files.example/x', [] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'http_request_not_executed', $response->get_error_code() );
		$this->assertSame( [], $this->sent );
	}

	public function test_a_wordpress_proxy_is_refused_for_a_name(): void {
		$http = new SafeTransport( null, null, [ 'proxy' => static fn(): bool => true ] );

		$response = $http->send( 'https://files.example/x', [] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'http_request_not_executed', $response->get_error_code(), 'A proxy would look the name up itself.' );
		$this->assertSame( [], $this->sent );
	}

	public function test_a_refusal_before_curl_comes_back_as_it_is(): void {
		$this->curl->curl = false;
		$this->answers    = [ new FakeWpError( 'A valid URL was not provided.' ) ];

		$response = self::http()->send( 'https://files.example:9999/x', [] );

		$this->assertInstanceOf( FakeWpError::class, $response, 'Core\'s own refusal (here: the port) keeps its code and words.' );
		$this->assertSame( 'A valid URL was not provided.', $response->get_error_message() );
	}

	public function test_redirects_are_followed_by_hand_and_every_hop_is_checked(): void {
		$this->dns['cdn.example'] = [ '93.184.216.35' ];
		$this->answers            = [
			RecordedHttp::answer( 302, '', [ 'Location' => 'https://cdn.example/files/x.wpress' ] ),
			RecordedHttp::answer( 301, '', [ 'Location' => 'y.wpress?t=1' ] ),
			RecordedHttp::answer( 200, 'archive' ),
		];

		$response = self::http()->follow( 'https://files.example/x', [ 'headers' => [ 'Range' => 'bytes=0-9' ] ], 5 );

		$this->assertSame( 'archive', $response['body'] );
		$this->assertSame( [ 'https://files.example/x', 'https://cdn.example/files/x.wpress', 'https://cdn.example/files/y.wpress?t=1' ], array_column( $this->sent, 0 ) );
		foreach ( $this->sent as [ , $args ] ) {
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( 'bytes=0-9', $args['headers']['Range'] );
		}
	}

	public function test_a_redirect_into_a_refused_range_is_not_followed(): void {
		$this->dns['evil.example'] = [ '169.254.169.254' ];
		$this->answers             = [ RecordedHttp::answer( 302, '', [ 'Location' => 'http://evil.example/latest/meta-data/' ] ) ];

		$this->assertRefused( self::http()->follow( 'https://files.example/x', [], 5 ) );
		$this->assertCount( 1, $this->sent, 'Only the first hop was sent.' );
	}

	public function test_at_most_the_given_redirects_are_followed(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->answers[] = RecordedHttp::answer( 302, '', [ 'Location' => '/hop' . $i ] );
		}

		$response = self::http()->follow( 'https://files.example/x', [], 5 );

		$this->assertSame( 302, $response['response']['code'], 'The redirect after the last allowed one is answered as it is.' );
		$this->assertCount( 6, $this->sent );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function locations(): array {
		return [
			'absolute'          => [ 'https://cdn.example/a?b=1', 'https://cdn.example/a?b=1' ],
			'scheme relative'   => [ '//cdn.example/a', 'https://cdn.example/a' ],
			'absolute path'     => [ '/b/c', 'https://files.example:8443/b/c' ],
			'relative path'     => [ 'c.wpress', 'https://files.example:8443/dir/c.wpress' ],
			'query only'        => [ '?x=2', 'https://files.example:8443/dir/file?x=2' ],
			'fragment dropped'  => [ '/b#frag', 'https://files.example:8443/b' ],
			'another scheme'    => [ 'file:///etc/passwd', null ],
			'gopher'            => [ 'gopher://cdn.example/', null ],
			'empty'             => [ '', null ],
			'no host'           => [ 'http:///x', null ],
		];
	}

	#[DataProvider( 'locations' )]
	public function test_a_location_is_made_absolute_and_only_http_is_followed( string $location, ?string $expected ): void {
		$response = RecordedHttp::answer( 307, '', [ 'Location' => $location ] );

		$this->assertSame( $expected, SafeTransport::location( $response, 'https://files.example:8443/dir/file?old=1' ) );
	}

	public function test_an_answer_that_is_no_redirect_has_no_location(): void {
		$this->assertNull( SafeTransport::location( RecordedHttp::answer( 200, '', [ 'Location' => '/x' ] ), 'https://a.example/' ) );
		$this->assertNull( SafeTransport::location( new FakeWpError( 'cURL error 7: refused' ), 'https://a.example/' ) );
	}
}
