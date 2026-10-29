<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\S3;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Client;
use LightweightPlugins\Migration\Storage\Destinations\S3\S3Endpoint;
use LightweightPlugins\Migration\Storage\Destinations\S3\SigV4Signer;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\RecordedHttp;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class S3ClientTest extends MonkeyTestCase {

	private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

	protected function setUp(): void {
		parent::setUp();
		RecordedHttp::install();
	}

	/**
	 * The callbacks registered with add_action(), by hook name.
	 *
	 * @var array<string, array<int, callable>>
	 */
	private array $hooks = [];

	/** @var string|null URL the fired hooks announce (null = the real one). */
	private ?string $fireUrl = null;

	/** @var array<string, mixed> Requests options the before_request callbacks may change. */
	private array $options = [];

	/** The callbacks a send() sees registered on http_api_curl. */
	private int $seen = 0;

	/** @var array<int, string> Hosts the resolver was asked for. */
	private array $asked = [];

	/**
	 * Hooks stand in for WordPress'; the send closure fires http_api_curl like the cURL transport does.
	 */
	private function client( RecordedHttp $http, ?S3Endpoint $endpoint = null, array $ips = [ '93.184.216.34' ], bool $curl = true, array $probes = [] ): S3Client {
		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback ) {
				$this->hooks[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( string $hook, $callback ) {
				$this->hooks[ $hook ] = array_values( array_filter( $this->hooks[ $hook ] ?? [], static fn( $c ) => $c !== $callback ) );
				return true;
			}
		);
		$inner  = $http->send();
		$send   = function ( string $url, array $args ) use ( $inner, $curl ) {
			$this->seen = count( $this->hooks['http_api_curl'] ?? [] );
			$fireUrl    = $this->fireUrl ?? $url;
			foreach ( $curl ? ( $this->hooks['requests-requests.before_request'] ?? [] ) : [] as $callback ) {
				$headers = [];
				$data    = [];
				$type    = 'GET';
				$callback( $fireUrl, $headers, $data, $type, $this->options );
			}
			foreach ( $curl ? ( $this->hooks['http_api_curl'] ?? [] ) : [] as $callback ) {
				$handle = curl_init();
				$callback( $handle, $args, $fireUrl );
			}
			return $inner( $url, $args );
		};
		$guard  = new AddressGuard(
			function ( string $host ) use ( $ips ): array {
				$this->asked[] = $host;
				return $ips;
			}
		);
		$endpoint = $endpoint ?? new S3Endpoint( 'https', 's3.eu-central-1.amazonaws.com', 'eu-central-1', 'my-bucket', false );

		return new S3Client(
			$endpoint,
			new SigV4Signer( 'AKIDEXAMPLE', self::SECRET, 'eu-central-1' ),
			$send,
			static fn(): int => 1760241600,
			$guard,
			$probes
		);
	}

	public function test_sends_a_signed_request_that_follows_no_redirect_and_checks_the_certificate(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '', [ 'ETag' => '"abc"' ] ) ] );

		$answer = $this->client( $http )->request( 'PUT', 'site/a.wpress', [ 'partNumber' => '3', 'uploadId' => 'u/1' ], 'BYTES', [], 120, null );

		[ $url, $args ] = $http->requests[0];
		$this->assertSame( 'https://my-bucket.s3.eu-central-1.amazonaws.com/site/a.wpress?partNumber=3&uploadId=u%2F1', $url );
		$this->assertSame( [ 'PUT', 0, true, 120, 'BYTES' ], [ $args['method'], $args['redirection'], $args['sslverify'], $args['timeout'], $args['body'] ] );
		$this->assertArrayNotHasKey( 'limit_response_size', $args, 'null = no limit (a ranged read sets its own).' );
		$this->assertArrayNotHasKey( 'Host', $args['headers'], 'The transport sends the host of the URL.' );
		$this->assertSame( hash( 'sha256', 'BYTES' ), $args['headers']['x-amz-content-sha256'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20251012/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=', $args['headers']['Authorization'] );
		$this->assertSame( [ 200, '', '"abc"', -1 ], [ $answer['status'], $answer['body'], $answer['etag'], $answer['length'] ] );
	}

	public function test_an_xml_answer_is_capped(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '<x/>' ) ] );

		$this->client( $http )->request( 'GET', '', [ 'list-type' => '2' ] );

		$this->assertSame( S3Client::MAX_XML, $http->requests[0][1]['limit_response_size'] );
		$this->assertArrayNotHasKey( 'body', $http->requests[0][1] );
	}

	public function test_no_answer_is_a_passing_failure_in_fixed_words(): void {
		$http = new RecordedHttp( [ new FakeWpError( 'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received from /tmp/x' ) ] );

		try {
			$this->client( $http )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
			$this->assertStringContainsString( 'https://my-bucket.s3.eu-central-1.amazonaws.com', $e->getMessage() );
			$this->assertStringNotContainsString( '/tmp/x', $e->getMessage() );
		}
	}

	/**
	 * Review Focus 3: S3 echoes the string to sign and the signature in a
	 * SignatureDoesNotMatch answer; none of it, nor the secret, reaches a message.
	 */
	public function test_a_failure_message_never_carries_the_secret_or_the_signature(): void {
		$body = '<Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match</Message><AWSAccessKeyId>AKIDEXAMPLE</AWSAccessKeyId><StringToSign>AWS4-HMAC-SHA256 x</StringToSign><SignatureProvided>deadbeef</SignatureProvided></Error>';
		$http = new RecordedHttp( [ RecordedHttp::answer( 403, $body ) ] );

		try {
			$this->client( $http )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
			foreach ( [ self::SECRET, 'deadbeef', 'AWS4-HMAC-SHA256', $http->requests[0][1]['headers']['Authorization'] ] as $never ) {
				$this->assertStringNotContainsString( $never, $e->getMessage() );
			}
		}
	}

	public function test_a_busy_answer_carries_its_retry_after(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 503, '<Error><Code>SlowDown</Code></Error>', [ 'Retry-After' => '12' ] ) ] );

		try {
			$this->client( $http )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [ TransientFailure::BUSY, 12 ], [ $e->reason, $e->retryAfter ] );
		}
	}

	/**
	 * @return array<string, array{0: array<int, string>}>
	 */
	public static function refusedAddresses(): array {
		return [
			'metadata'          => [ [ '169.254.169.254' ] ],
			'one of two'        => [ [ '10.0.0.1', '169.254.169.254' ] ],
			'alibaba'           => [ [ '100.100.100.200' ] ],
			'mapped'            => [ [ '::ffff:a9fe:a9fe' ] ],
			'aws v6'            => [ [ 'fd00:ec2::254' ] ],
			'link-local v6'     => [ [ 'fe80::1' ] ],
			'multicast'         => [ [ '224.0.0.251' ] ],
		];
	}

	/**
	 * @param array<int, string> $ips What the name resolves to.
	 */
	#[DataProvider( 'refusedAddresses' )]
	public function test_a_name_that_resolves_to_a_refused_address_sends_nothing( array $ips ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		try {
			$this->client( $http, null, $ips )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			foreach ( $ips as $ip ) {
				$this->assertStringNotContainsString( $ip, $e->getMessage() );
			}
		}
		$this->assertSame( [], $http->requests );
		$this->assertSame( [ 'my-bucket.s3.eu-central-1.amazonaws.com' ], $this->asked );
	}

	public function test_a_name_that_does_not_resolve_sends_nothing(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		try {
			$this->client( $http, null, [] )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
		$this->assertSame( [], $http->requests );
	}

	public function test_the_checked_address_is_pinned_for_that_request_only(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ), new FakeWpError( 'x' ) ] );
		$c    = $this->client( $http, null, [ '93.184.216.34', '2606:4700::1111' ] );

		$c->request( 'GET', 'a.wpress' );
		$this->assertSame( 1, $this->seen, 'One http_api_curl callback while the request is sent.' );
		$this->assertSame( [], $this->hooks['http_api_curl'], 'Removed afterwards.' );

		try {
			$c->request( 'GET', 'a.wpress' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( 1, $this->seen );
		}
		$this->assertSame( [], $this->hooks['http_api_curl'], 'Removed after a failure too.' );
	}

	public function test_the_pin_entry_is_host_port_and_the_first_address_with_v6_in_brackets(): void {
		$this->assertSame( 'b.s3.test:443:93.184.216.34', S3Client::pinEntry( 'b.s3.test', 443, '93.184.216.34' ) );
		$this->assertSame( 'minio.test:9000:[::1]', S3Client::pinEntry( 'minio.test', 9000, '::1' ) );
	}

	public function test_the_pin_also_switches_environment_proxies_off(): void {
		$options = S3Client::pinOptions( 'b.s3.test', 443, '93.184.216.34' );

		$this->assertSame( [ 'b.s3.test:443:93.184.216.34' ], $options[ CURLOPT_RESOLVE ] );
		$this->assertSame( '', $options[ CURLOPT_PROXY ] );
		$this->assertSame( 2, count( $options ) );
	}

	public function test_a_hosted_preset_goes_through_the_wordpress_proxy_without_a_refusal(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		$this->client( $http, null, [ '93.184.216.34' ], true, [ 'proxy' => static fn( string $url ): bool => true ] )->request( 'GET', 'a.wpress' );

		$this->assertCount( 1, $http->requests );
		$this->assertSame( 0, $this->seen, 'No pin hook: its empty CURLOPT_PROXY would bypass the WordPress proxy.' );
		$this->assertArrayNotHasKey( 'transport', $this->options );
	}

	public function test_a_hosted_preset_through_the_wordpress_proxy_does_not_need_the_pin_backstop(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		$this->client( $http, null, [ '93.184.216.34' ], false, [ 'proxy' => static fn( string $url ): bool => true ] )->request( 'GET', 'a.wpress' );

		$this->assertCount( 1, $http->requests );
		$this->assertNotSame( [], $this->asked, 'The address is still checked.' );
	}

	public function test_a_transport_that_ignores_the_pin_fails_closed(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200, '<ok/>' ) ] );

		try {
			$this->client( $http, null, [ '93.184.216.34' ], false )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( '93.184.216.34', $e->getMessage() );
		}
		$this->assertSame( [], $this->hooks['http_api_curl'] );
	}

	public function test_an_address_written_out_needs_no_pin_but_is_still_checked(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ), RecordedHttp::answer( 200 ) ] );
		$ok   = new S3Endpoint( 'http', '10.0.0.5:9000', 'us-east-1', 'b-1', true );
		$bad  = new S3Endpoint( 'http', '169.254.169.254', 'us-east-1', 'b-1', true );

		$this->client( $http, $ok, [], false )->request( 'GET', 'a' );
		$this->assertCount( 1, $http->requests );
		try {
			$this->client( $http, $bad, [], false )->request( 'GET', 'a' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertCount( 1, $http->requests );
	}

	/**
	 * @return array<string, array{0: S3Endpoint}>
	 */
	public static function endpoints(): array {
		return [
			'virtual host'      => [ new S3Endpoint( 'https', 's3.eu-central-1.amazonaws.com', 'eu-central-1', 'my-bucket', false ) ],
			'custom with port'  => [ new S3Endpoint( 'http', 'minio.example.test:9000', 'eu-central-1', 'my-bucket', true ) ],
			'ipv6 literal port' => [ new S3Endpoint( 'http', '[::1]:9000', 'eu-central-1', 'my-bucket', true ) ],
		];
	}

	/**
	 * The signed Host must be what the transport sends: host[:port] of the URL.
	 */
	#[DataProvider( 'endpoints' )]
	public function test_the_signature_is_for_the_host_the_url_sends( S3Endpoint $endpoint ): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		$this->client( $http, $endpoint, [ '10.0.0.5' ] )->request( 'GET', 'site/a.wpress', [ 'list-type' => '2' ] );

		[ $url, $args ] = $http->requests[0];
		$parts          = parse_url( $url );
		$host           = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path           = ( $endpoint->pathStyle ? '/my-bucket' : '' ) . '/site/a.wpress';
		$expected       = ( new SigV4Signer( 'AKIDEXAMPLE', self::SECRET, 'eu-central-1' ) )->sign(
			'GET',
			$path,
			[ 'list-type' => '2' ],
			[
				'Host'                 => $host,
				'x-amz-content-sha256' => hash( 'sha256', '' ),
			],
			hash( 'sha256', '' ),
			1760241600
		);

		$this->assertSame( $expected['Authorization'], $args['headers']['Authorization'] );
	}

	public function test_curl_is_forced_for_this_request_only_and_the_hook_is_removed(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );
		$c    = $this->client( $http );

		$c->request( 'GET', 'a.wpress' );

		$this->assertSame( 'WpOrg\\Requests\\Transport\\Curl', ltrim( (string) ( $this->options['transport'] ?? '' ), '\\' ) );
		$this->assertSame( [], $this->hooks['requests-requests.before_request'] );
	}

	public function test_the_forcing_hook_leaves_another_url_alone(): void {
		$http         = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );
		$this->fireUrl = 'https://elsewhere.example.test/x';

		try {
			$this->client( $http )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason, 'The pin was not applied to this request: fail closed.' );
		}
		$this->assertArrayNotHasKey( 'transport', $this->options );
		$this->assertSame( [], $this->hooks['http_api_curl'] );
		$this->assertSame( [], $this->hooks['requests-requests.before_request'] );
	}

	public function test_without_curl_nothing_is_sent(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );

		try {
			$this->client( $http, null, [ '93.184.216.34' ], true, [ 'curl' => static fn(): bool => false ] )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'cURL', $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );
	}

	/**
	 * A WP_Error that came back before cURL saw the request (a pre_http_request
	 * short-circuit, a blocked host): it was never sent, and asking again
	 * cannot help. Final-review ruling m4: a refusal in our words, not
	 * "cURL is needed" and not a passing network failure.
	 */
	public function test_an_error_before_curl_is_a_refusal_in_our_words_not_a_missing_curl(): void {
		$http = new RecordedHttp( [ new FakeWpError( 'short-circuited by /srv/www/plugin.php', 'my_filter_said_no' ) ] );

		try {
			$this->client( $http, null, [ '93.184.216.34' ], false )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( 'short-circuited', $e->getMessage() );
			$this->assertStringNotContainsString( 'cURL', $e->getMessage() );
		}
		$this->assertSame( [], $this->hooks['http_api_curl'], 'Removed after the refusal too.' );
	}

	public function test_a_certificate_failure_at_the_pinned_address_stops_the_destination(): void {
		$http = new RecordedHttp( [ new FakeWpError( 'cURL error 60: SSL certificate problem: self-signed certificate (CA file /etc/ssl/cert.pem)' ) ] );

		try {
			$this->client( $http )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringNotContainsString( '/etc/ssl', $e->getMessage() );
		}
	}

	public function test_a_proxy_for_a_name_refuses_before_sending_but_not_for_an_address(): void {
		$http  = new RecordedHttp( [ RecordedHttp::answer( 200 ) ] );
		$proxy = [ 'proxy' => static fn( string $url ): bool => true ];

		try {
			$this->client( $http, new S3Endpoint( 'https', 'minio.example.test', 'us-east-1', 'b-1', true, true ), [ '93.184.216.34' ], true, $proxy )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'proxy', $e->getMessage() );
		}
		$this->assertSame( [], $http->requests );

		$this->client( $http, new S3Endpoint( 'http', '10.0.0.5:9000', 'us-east-1', 'b-1', true ), [], false, $proxy )->request( 'GET', 'a' );
		$this->assertCount( 1, $http->requests );
	}

	public function test_an_ipv6_pin_needs_libcurl_7_57(): void {
		$http = new RecordedHttp( [ RecordedHttp::answer( 200 ), RecordedHttp::answer( 200 ) ] );
		$old  = [ 'libcurl' => static fn(): int => 0x073800 ];

		try {
			$this->client( $http, null, [ '2606:4700::1111' ], true, $old )->request( 'GET', 'a.wpress' );
			$this->fail( 'Thrown.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $http->requests );

		$this->client( $http, null, [ '93.184.216.34' ], true, $old )->request( 'GET', 'a.wpress' );
		$this->client( $http, null, [ '2606:4700::1111' ], true, [ 'libcurl' => static fn(): int => 0x073900 ] )->request( 'GET', 'a.wpress' );
		$this->assertCount( 2, $http->requests );
	}
}
