<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The configure closure of a request (a streamed body) shares the one-shot
 * pin hook: it runs only on that request's handle, and the pin is applied
 * after it, so nothing a closure sets can undo the checked address.
 */
final class GuardedHttpTest extends MonkeyTestCase {

	private CurlHooks $curl;

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
	}

	/**
	 * @param string   $where     scheme://host:port.
	 * @param \Closure $transport fn( string $url, array $args ).
	 */
	private function http( string $where, \Closure $transport ): GuardedHttp {
		return new GuardedHttp(
			$where,
			(string) substr( $where, 7 ),
			'http',
			true,
			$this->curl->wrap( $transport ),
			new AddressGuard( static fn(): array => [ '127.0.0.1' ] ),
			[ 'proxy' => static fn(): bool => false ]
		);
	}

	/**
	 * Task 3 m1: a closure that sets a proxy and another address for the host
	 * loses to the pin. A listening socket on the loopback takes the
	 * connection (nothing is sent anywhere else); cURL then times out waiting
	 * for an answer, and the address it connected to shows who won.
	 */
	public function test_the_pin_is_applied_after_the_configure_closure(): void {
		$server = stream_socket_server( 'tcp://127.0.0.1:0' );
		$this->assertIsResource( $server );
		$port      = (int) substr( (string) strrchr( (string) stream_socket_get_name( $server, false ), ':' ), 1 );
		$url       = 'http://lwm-pin.invalid:' . $port . '/x';
		$connected = '';
		$transport = function () use ( $url, &$connected ): array {
			curl_setopt_array(
				$this->curl->handle,
				[
					CURLOPT_URL               => $url,
					CURLOPT_CONNECTTIMEOUT_MS => 2000,
					CURLOPT_TIMEOUT_MS        => 300,
					CURLOPT_RETURNTRANSFER    => true,
				]
			);
			curl_exec( $this->curl->handle );
			$connected = curl_getinfo( $this->curl->handle, CURLINFO_PRIMARY_IP ) . ':' . curl_getinfo( $this->curl->handle, CURLINFO_PRIMARY_PORT );

			return RecordedHttp::answer( 200 );
		};
		$configure = static function ( $handle ) use ( $port ): void {
			curl_setopt_array(
				$handle,
				[
					CURLOPT_PROXY   => 'http://127.0.0.1:9',
					CURLOPT_RESOLVE => [ 'lwm-pin.invalid:' . $port . ':127.0.0.3' ],
				]
			);
		};

		try {
			$this->http( 'http://lwm-pin.invalid:' . $port, $transport )->send( $url, [], $configure );
		} finally {
			fclose( $server );
		}

		$this->assertSame( '127.0.0.1:' . $port, $connected );
	}

	/** Task 3 m5: another request's handle (same hook, other URL) never gets the closure. */
	public function test_the_configure_closure_is_not_applied_to_a_foreign_url(): void {
		$handles   = [];
		$foreign   = curl_init();
		$transport = function () use ( $foreign ): array {
			foreach ( $this->curl->hooks['http_api_curl'] ?? [] as $callback ) {
				$callback( $foreign, [], 'http://other.example/x' );
			}

			return RecordedHttp::answer( 200 );
		};
		$configure = static function ( $handle ) use ( &$handles ): void {
			$handles[] = $handle;
		};

		$this->http( 'http://dav.example:8080', $transport )->send( 'http://dav.example:8080/x', [], $configure );

		$this->assertCount( 1, $handles, 'Applied once, to its own request.' );
		$this->assertSame( $this->curl->handle, $handles[0] );
		$this->assertNotSame( $foreign, $handles[0] );
	}

	/** Final review M7: a redirect another plugin's http_request_args turned back on is never followed unpinned. */
	public function test_a_redirect_re_enabled_by_another_plugin_is_not_followed(): void {
		$this->curl->options = [
			'follow_redirects' => true,
			'redirects'        => 5,
		];

		$this->http( 'http://dav.example:8080', static fn(): array => RecordedHttp::answer( 200 ) )->send( 'http://dav.example:8080/x', [] );

		$this->assertFalse( $this->curl->options['follow_redirects'] );
		$this->assertSame( \WpOrg\Requests\Transport\Curl::class, $this->curl->options['transport'] );
		$this->assertTrue( $this->curl->clean() );
	}
}
