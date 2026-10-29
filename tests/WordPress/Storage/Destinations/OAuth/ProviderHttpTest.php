<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\GuardedHttp;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ProviderHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\Support\CurlHooks;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Preflight P-C2: the one send path of every OAuth provider request. Only
 * https on port 443 without userinfo or fragment to a DNS name; checked and
 * pinned by GuardedHttp; an Authorization header only to a provider API host.
 */
final class ProviderHttpTest extends MonkeyTestCase {

	private CurlHooks $curl;

	/** @var array<int, string> Hosts the resolver was asked for. */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
		$this->curl = ( new CurlHooks() )->install();
		AddressGuard::useResolver(
			function ( string $host ): array {
				$this->asked[] = $host;
				return 'metadata.example' === $host ? [ '169.254.169.254' ] : [ '93.184.216.34' ];
			}
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * @param RecordedHttp            $recorded Answers.
	 * @param array<string, \Closure> $probes   Environment checks.
	 */
	private function http( RecordedHttp $recorded, array $probes = [] ): ProviderHttp {
		return new ProviderHttp( $this->curl->wrap( $recorded->send() ), null, $probes );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refused(): array {
		return [
			'http'           => [ 'http://graph.microsoft.com/v1.0/me' ],
			'other port'     => [ 'https://graph.microsoft.com:8443/v1.0/me' ],
			'userinfo'       => [ 'https://user:pass@graph.microsoft.com/v1.0/me' ],
			'fragment'       => [ 'https://graph.microsoft.com/v1.0/me#x' ],
			'ipv4 literal'   => [ 'https://203.0.113.5/upload' ],
			'ipv6 literal'   => [ 'https://[2001:db8::1]/upload' ],
			'upper case'     => [ 'https://Graph.Microsoft.com/v1.0/me' ],
			'no dot'         => [ 'https://intranet/upload' ],
			'space'          => [ 'https://graph.microsoft.com/v1.0/a b' ],
			'line break'     => [ "https://graph.microsoft.com/v1.0/a\r\nX: y" ],
			'not a url'      => [ 'https:///nothing' ],
			'upper scheme'   => [ 'HTTPS://graph.microsoft.com/v1.0/me' ],
		];
	}

	#[DataProvider( 'refused' )]
	public function test_an_address_off_the_rules_is_refused_before_sending( string $url ): void {
		$recorded = new RecordedHttp();

		try {
			$this->http( $recorded )->send( $url, [ 'method' => 'GET' ], 'OneDrive' );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'microsoft.com', $e->getMessage(), 'The address is never echoed.' );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_a_provider_given_host_on_443_is_sent_without_redirects_and_tls_checked(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200, '{}' ) ] );

		$answer = $this->http( $recorded )->send(
			'https://my.sharepoint.example:443/upload?x=1',
			[
				'method'      => 'PUT',
				'redirection' => 5,
				'sslverify'   => false,
			],
			'OneDrive'
		);

		$this->assertSame( 200, $answer['response']['code'] );
		$this->assertSame( 0, $recorded->requests[0][1]['redirection'] );
		$this->assertTrue( $recorded->requests[0][1]['sslverify'] );
		$this->assertSame( 1, $this->curl->seen );
	}

	public function test_bearer_goes_only_to_a_provider_api_host(): void {
		foreach ( ProviderHttp::BEARER_HOSTS as $host ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );
			$this->http( $recorded )->send( 'https://' . $host . '/x', [ 'headers' => [ 'Authorization' => 'Bearer t' ] ], 'Google Drive' );
			$this->assertCount( 1, $recorded->requests, $host );
		}

		$recorded = new RecordedHttp();
		try {
			$this->http( $recorded )->send( 'https://upload.example.com/session', [ 'headers' => [ 'authorization' => 'Bearer secret-token' ] ], 'OneDrive' );
			$this->fail( 'A token to another host is a programming error.' );
		} catch ( \LogicException $e ) {
			$this->assertStringNotContainsString( 'secret-token', $e->getMessage() );
		}
		$this->assertSame( [], $recorded->requests );
	}

	/**
	 * WordPress parses a string of raw header lines before sending (WP_Http::request()),
	 * so anything but an array of headers could carry a hidden Authorization line.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function opaqueHeaders(): array {
		return [
			'raw string'  => [ "Authorization: Bearer secret-token\r\nAccept: */*" ],
			'dictionary'  => [ new \ArrayObject( [ 'Authorization' => 'Bearer secret-token' ] ) ], // Like Requests' CaseInsensitiveDictionary (ArrayAccess + iterable), not loaded in this suite.
			'plain value' => [ 42 ],
		];
	}

	/**
	 * @param mixed $headers Headers that are not an array.
	 */
	#[DataProvider( 'opaqueHeaders' )]
	public function test_headers_that_are_not_an_array_are_refused_before_sending( $headers ): void {
		$recorded = new RecordedHttp();

		try {
			$this->http( $recorded )->send( 'https://upload.example.com/session', [ 'headers' => $headers ], 'OneDrive' );
			$this->fail( 'Opaque headers are a programming error.' );
		} catch ( \LogicException $e ) {
			$this->assertStringNotContainsString( 'secret-token', $e->getMessage() );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_the_host_is_read_like_curl_and_a_look_alike_never_gets_a_token(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200 ), RecordedHttp::answer( 200 ) ] );
		$this->http( $recorded )->send( 'https://graph.microsoft.com?@evil.example/x', [ 'headers' => [ 'Authorization' => 'Bearer t' ] ], 'OneDrive' );
		$this->http( $recorded )->send( 'https://graph.microsoft.com.evil.com/x', [], 'OneDrive' );

		$this->assertSame( [ 'graph.microsoft.com', 'graph.microsoft.com.evil.com' ], $this->asked, 'The query after ? is not the host; a look-alike is just another host.' );

		foreach ( [ 'https://evil.example?@graph.microsoft.com/x', 'https://graph.microsoft.com.evil.com/x' ] as $url ) {
			try {
				$this->http( new RecordedHttp() )->send( $url, [ 'headers' => [ 'Authorization' => 'Bearer t' ] ], 'OneDrive' );
				$this->fail( 'Not a Bearer host: ' . $url );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'Authorization', $e->getMessage() );
			}
		}
	}

	public function test_behind_a_wordpress_proxy_it_is_checked_then_sent_through_the_proxy_unpinned(): void {
		$recorded = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		$this->http( $recorded, [ 'proxy' => static fn( string $url ): bool => true ] )->send( 'https://graph.microsoft.com/v1.0/me', [], 'OneDrive' );

		$this->assertSame( [ 'graph.microsoft.com' ], $this->asked, 'The address is still checked.' );
		$this->assertCount( 1, $recorded->requests );
		$this->assertSame( 0, $this->curl->seen, 'No pin hook: the proxy resolves the name (hosted-preset rule).' );
		$this->assertArrayNotHasKey( 'transport', $this->curl->options );
		$this->assertTrue( $this->curl->clean() );

		$refused = new RecordedHttp();
		try {
			$this->http( $refused, [ 'proxy' => static fn( string $url ): bool => true ] )->send( 'https://metadata.example/x', [], 'OneDrive' );
			$this->fail( 'A refused address stays refused behind a proxy.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $refused->requests );
	}

	public function test_the_url_and_the_arguments_are_marked_sensitive(): void {
		$checks = [
			[ ProviderHttp::class, 'send', [ 'url', 'args' ] ],
			[ ProviderHttp::class, 'host', [ 'url' ] ],
			[ GuardedHttp::class, 'send', [ 'args' ] ],
		];
		foreach ( $checks as [ $class, $method, $names ] ) {
			foreach ( ( new \ReflectionMethod( $class, $method ) )->getParameters() as $parameter ) {
				if ( in_array( $parameter->getName(), $names, true ) ) {
					$this->assertCount( 1, $parameter->getAttributes( \SensitiveParameter::class ), $class . '::' . $method . ' $' . $parameter->getName() );
				}
			}
		}
	}

	/**
	 * Task 3 review minor: a padded Authorization key is still an Authorization header.
	 */
	public function test_a_padded_authorization_key_is_still_held_to_the_api_hosts(): void {
		foreach ( [ ' Authorization', "Authorization\t", ' AUTHORIZATION ' ] as $key ) {
			$recorded = new RecordedHttp();
			try {
				$this->http( $recorded )->send( 'https://upload.example.com/session', [ 'headers' => [ $key => 'Bearer secret-token' ] ], 'OneDrive' );
				$this->fail( 'Refused: ' . json_encode( $key ) );
			} catch ( \LogicException $e ) {
				$this->assertStringNotContainsString( 'secret-token', $e->getMessage() );
			}
			$this->assertSame( [], $recorded->requests );
		}
	}

	/**
	 * A line break in a header key or value would start another header line.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function brokenHeaders(): array {
		return [
			'CR LF in a value' => [ [ 'Accept' => "application/json\r\nAuthorization: Bearer secret-token" ] ],
			'LF in a value'    => [ [ 'Accept' => "application/json\nAuthorization: Bearer secret-token" ] ],
			'CR in a value'    => [ [ 'Accept' => "application/json\rX: secret-token" ] ],
			'CR LF in a key'   => [ [ "X-A\r\nAuthorization" => 'Bearer secret-token' ] ],
			'LF in a key'      => [ [ "X-A\nAuthorization" => 'Bearer secret-token' ] ],
		];
	}

	/**
	 * @param array<string, string> $headers Headers with a line break.
	 */
	#[DataProvider( 'brokenHeaders' )]
	public function test_a_line_break_in_a_header_is_refused_before_sending( array $headers ): void {
		$recorded = new RecordedHttp();

		try {
			$this->http( $recorded )->send( 'https://graph.microsoft.com/v1.0/me', [ 'headers' => $headers ], 'OneDrive' );
			$this->fail( 'A header line break is a programming error.' );
		} catch ( \LogicException $e ) {
			$this->assertStringNotContainsString( 'secret-token', $e->getMessage() );
		}
		$this->assertSame( [], $recorded->requests );
	}

	public function test_no_headers_or_null_headers_are_sent(): void {
		foreach ( [ [], [ 'headers' => null ] ] as $args ) {
			$recorded = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );
			$this->http( $recorded )->send( 'https://upload.example.com/session', $args, 'OneDrive' );
			$this->assertCount( 1, $recorded->requests );
		}
	}
}
