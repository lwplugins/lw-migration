<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\Client;
use LightweightPlugins\Migration\Link\LinkException;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Tests\Support\FakeWpError;
use LightweightPlugins\Migration\Tests\Support\SafeWeb;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ClientTest extends MonkeyTestCase {

	private const NOW = 1790000000;

	private const ID = '0123456789abcdef';

	/**
	 * Requests sent: [ url, args ].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * The next answer.
	 *
	 * @var array<string, mixed>|FakeWpError
	 */
	private $answer;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		$this->key    = random_bytes( 32 );
		$this->answer = self::reply( 200, [ 'ok' => true ] );
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => $r instanceof FakeWpError );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $base ) => $base . '?' . $key . '=' . $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
	}

	/**
	 * @param array<mixed>|string $json Body (array: JSON-encoded).
	 * @return array<string, mixed>
	 */
	private static function reply( int $code, $json, string $date = '' ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => '' === $date ? [] : [ 'date' => $date ],
			'body'     => is_string( $json ) ? $json : (string) json_encode( $json ),
		];
	}

	private function send(): \Closure {
		return function ( string $url, array $args ) {
			$this->sent[] = [ $url, $args ];
			$answer       = $this->signed( $this->answer, $args );
			if ( is_array( $answer ) && isset( $args['limit_response_size'] ) ) {
				$answer['body'] = substr( (string) $answer['body'], 0, (int) $args['limit_response_size'] );
			}

			return $answer;
		};
	}

	/**
	 * The answer as the other site's link routes sign it (for a signed
	 * request), unless it already carries a signature header.
	 *
	 * @param array<string, mixed>|FakeWpError $answer Answer.
	 * @param array<string, mixed>             $args   Request arguments.
	 * @return array<string, mixed>|FakeWpError
	 */
	private function signed( $answer, array $args ) {
		$nonce = $args['headers'][ Signer::HEADER_NONCE ] ?? null;
		if ( ! is_array( $answer ) || ! is_string( $nonce ) ) {
			return $answer;
		}
		$answer['headers'] += [ 'x-lwm-response-signature' => Signer::answer( self::ID, $this->key, $nonce, (int) $answer['response']['code'], (string) $answer['body'] ) ];

		return $answer;
	}

	private function client( string $address = 'https://b.example' ): Client {
		return new Client(
			[
				'id'      => self::ID,
				'address' => $address,
				'key'     => $this->key,
			],
			$this->send(),
			static fn(): int => self::NOW
		);
	}

	private function failure( \Closure $call ): LinkException {
		try {
			$call();
		} catch ( LinkException $e ) {
			return $e;
		}
		$this->fail( 'A LinkException was expected.' );
	}

	public function test_requests_are_signed_for_their_route_and_never_follow_redirects(): void {
		$client = $this->client();
		$this->assertSame( [ 'status' => 200, 'json' => [ 'ok' => true ] ], $client->json( 'POST', '/hello', [ 'x' => 1 ] ) );
		[ $url, $args ] = $this->sent[0];
		$this->assertSame( 'https://b.example/index.php?rest_route=/lw-migration/v1/link/hello', $url );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 30, $args['timeout'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( '{"x":1}', $args['body'] );
		$parts = Signer::parse( $args['headers'] );
		$this->assertNotNull( $parts );
		$this->assertTrue( Signer::verify( $parts, $this->key, 'POST', '/lw-migration/v1/link/hello', $args['body'], self::NOW ) );

		$bytes = random_bytes( 100 );
		$client->raw( 'POST', '/jobs/aaaaaaaaaaaaaaaa/upload/0', $bytes );
		[ $url, $args ] = $this->sent[1];
		$this->assertSame( 'https://b.example/index.php?rest_route=/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/upload/0', $url );
		$this->assertSame( 'application/octet-stream', $args['headers']['Content-Type'] );
		$this->assertSame( 120, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( Signer::verify( (array) Signer::parse( $args['headers'] ), $this->key, 'POST', '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/upload/0', $bytes, self::NOW ) );
		$this->assertNotSame( $this->sent[0][1]['headers'][ Signer::HEADER_NONCE ], $args['headers'][ Signer::HEADER_NONCE ] );

		$client->json( 'GET', '/jobs/aaaaaaaaaaaaaaaa' );
		$args = $this->sent[2][1];
		$this->assertArrayNotHasKey( 'body', $args );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertTrue( Signer::verify( (array) Signer::parse( $args['headers'] ), $this->key, 'GET', '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa', '', self::NOW ) );

		$signer = $client->signerFor( '/jobs/aaaaaaaaaaaaaaaa/archive' );
		$first  = $signer();
		$this->assertTrue( Signer::verify( (array) Signer::parse( $first ), $this->key, 'GET', '/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/archive', '', self::NOW ) );
		$this->assertNotSame( $first[ Signer::HEADER_NONCE ], $signer()[ Signer::HEADER_NONCE ] );
		$this->assertSame( 'https://b.example/index.php?rest_route=/lw-migration/v1/link/jobs/aaaaaaaaaaaaaaaa/archive', $client->urlOf( '/jobs/aaaaaaaaaaaaaaaa/archive' ) );
		$this->assertSame( self::ID, $client->id() );
		$this->assertSame( 'https://b.example', $client->address() );
	}

	public function test_a_post_without_a_body_still_declares_json(): void {
		$this->client()->json( 'POST', '/hello' );
		$args = $this->sent[0][1];
		$this->assertArrayNotHasKey( 'body', $args );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'], 'Otherwise core\'s transports send a form media type the guard used to refuse.' );
		$this->assertTrue( Signer::verify( (array) Signer::parse( $args['headers'] ), $this->key, 'POST', '/lw-migration/v1/link/hello', '', self::NOW ) );
	}

	public function test_a_refusal_with_a_far_clock_says_so(): void {
		$refused = [ 'code' => 'lw_migration_link_refused', 'message' => 'This request is not accepted.' ];
		$client  = $this->client();

		$this->answer = self::reply( 401, $refused, gmdate( 'D, d M Y H:i:s', self::NOW + 600 ) . ' GMT' );
		$e            = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
		$this->assertSame( LinkException::CLOCK, $e->reason );
		$this->assertStringContainsString( '10', $e->getMessage() );
		$this->assertSame( 401, $e->status );
		$this->assertFalse( $e->isPassing() );

		$this->answer = self::reply( 401, $refused, gmdate( 'D, d M Y H:i:s', self::NOW - 600 ) . ' GMT' );
		$this->assertSame( LinkException::CLOCK, $this->failure( fn() => $client->json( 'POST', '/hello' ) )->reason, 'Behind as well.' );

		foreach ( [ gmdate( 'D, d M Y H:i:s', self::NOW + 180 ) . ' GMT', '', 'not a date' ] as $date ) {
			$this->answer = self::reply( 401, $refused, $date );
			$e            = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
			$this->assertSame( LinkException::REFUSED, $e->reason, $date );
			$this->assertStringContainsString( 'no longer accepts this connection', $e->getMessage() );
		}

		$this->answer = self::reply( 401, $refused, gmdate( 'D, d M Y H:i:s', self::NOW + 600 ) . ' GMT' );
		$e            = $this->failure( fn() => Client::unsigned( $this->send(), 'https://b.example', '/lw-migration/v1/link/pair', [ 'a' => 1 ], self::NOW ) );
		$this->assertSame( LinkException::REFUSED, $e->reason, 'An unsigned request is never refused for its clock.' );
	}

	public function test_a_missing_route_says_the_other_site_offers_no_connections(): void {
		$this->answer = self::reply( 404, [ 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ] );
		$e            = $this->failure( fn() => $this->client()->json( 'GET', '/jobs/aaaaaaaaaaaaaaaa' ) );
		$this->assertSame( LinkException::NOT_AVAILABLE, $e->reason );
		$this->assertSame( 404, $e->status );
	}

	public function test_transport_errors_5xx_and_busy_are_passing_others_not(): void {
		$client       = $this->client();
		$this->answer = new FakeWpError( 'cURL error 28: Operation timed out' );
		$e            = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
		$this->assertSame( LinkException::UNREACHABLE, $e->reason );
		$this->assertTrue( $e->isPassing() );
		$this->assertStringContainsString( 'did not answer in time', $e->getMessage() );

		$passing = [
			[ 503, 'lw_migration_link_busy' ],
			[ 409, 'lw_migration_job_busy' ],
			[ 502, '' ],
			[ 500, 'internal_server_error' ],
		];
		foreach ( $passing as [ $status, $code ] ) {
			$this->answer = self::reply( $status, '' === $code ? '<html>Bad gateway</html>' : [ 'code' => $code, 'message' => 'Busy.' ] );
			$e            = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
			$this->assertSame( LinkException::REMOTE, $e->reason, (string) $status );
			$this->assertTrue( $e->isPassing(), $status . ' ' . $code );
			$this->assertSame( $status, $e->status );
			$this->assertSame( $code, $e->remoteCode );
		}
		$final = [
			[ 400, 'lw_migration_invalid' ],
			[ 403, 'lw_migration_link_owner' ],
			[ 404, 'lw_migration_no_job' ],
			[ 409, 'lw_migration_site_busy' ],
		];
		foreach ( $final as [ $status, $code ] ) {
			$this->answer = self::reply( $status, [ 'code' => $code, 'message' => 'No.' ] );
			$e            = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
			$this->assertSame( LinkException::REMOTE, $e->reason );
			$this->assertFalse( $e->isPassing(), $status . ' ' . $code );
			$this->assertSame( $code, $e->remoteCode );
		}

		$this->answer = self::reply(
			409,
			[
				'code'    => 'lw_migration_upload_offset',
				'message' => 'Expected another offset.',
				'data'    => [
					'status'   => 409,
					'received' => 1234,
				],
			]
		);
		$e            = $this->failure( fn() => $client->raw( 'POST', '/jobs/aaaaaaaaaaaaaaaa/upload/0', 'x' ) );
		$this->assertSame( 'lw_migration_upload_offset', $e->remoteCode );
		$this->assertSame( 1234, $e->data['received'] );
		$this->assertStringContainsString( 'Expected another offset.', $e->getMessage() );
	}

	public function test_the_other_sites_text_is_reduced_and_clipped(): void {
		$this->answer = self::reply( 400, [ 'code' => 'lw_migration_invalid', 'message' => '<b>Bad</b> https://x.example/?token=abc ' . str_repeat( 'é', 1000 ) ] );
		$e            = $this->failure( fn() => $this->client()->json( 'POST', '/hello' ) );
		$this->assertStringNotContainsString( 'abc', $e->getMessage() );
		$this->assertStringNotContainsString( '<b>', $e->getMessage() );
		$this->assertStringContainsString( 'https://x.example', $e->getMessage() );
		$this->assertLessThanOrEqual( 600, strlen( $e->getMessage() ) );
		$this->assertSame( 1, preg_match( '//u', $e->getMessage() ) );

		foreach ( [ '"text"', '[1,2', 'null', '' ] as $body ) {
			$this->answer = self::reply( 200, $body );
			$this->assertSame( LinkException::PROTOCOL, $this->failure( fn() => $this->client()->json( 'POST', '/hello' ) )->reason, $body );
		}
	}

	public function test_an_unsigned_answer_is_refused_with_its_own_error(): void {
		$this->answer = self::reply( 200, [ 'status' => 'done' ] );
		$this->answer['headers']['x-lwm-response-signature'] = '';
		$e = $this->failure( fn() => $this->client( 'http://b.example' )->json( 'POST', '/jobs/aaaaaaaaaaaaaaaa/tick' ) );
		$this->assertSame( LinkException::UNSIGNED, $e->reason );
		$this->assertFalse( $e->isPassing(), 'A changed answer is never retried as if the network had failed.' );
		$this->assertSame( 200, $e->status );
		$this->assertStringContainsString( 'http://b.example', $e->getMessage() );
		$this->assertStringContainsString( 'signed', $e->getMessage() );
	}

	public function test_an_answer_signed_for_another_request_body_status_or_key_is_refused(): void {
		$client = $this->client();
		$cases  = [
			'another request' => static fn( array $args, string $key ): string => Signer::answer( self::ID, $key, str_repeat( 'd', 32 ), 200, '{"status":"done"}' ),
			'another body'    => static fn( array $args, string $key ): string => Signer::answer( self::ID, $key, $args['headers'][ Signer::HEADER_NONCE ], 200, '{"status":"running"}' ),
			'another status'  => static fn( array $args, string $key ): string => Signer::answer( self::ID, $key, $args['headers'][ Signer::HEADER_NONCE ], 201, '{"status":"done"}' ),
			'another key'     => static fn( array $args, string $key ): string => Signer::answer( self::ID, str_repeat( 'z', 32 ), $args['headers'][ Signer::HEADER_NONCE ], 200, '{"status":"done"}' ),
			'another link'    => static fn( array $args, string $key ): string => Signer::answer( 'fedcba9876543210', $key, $args['headers'][ Signer::HEADER_NONCE ], 200, '{"status":"done"}' ),
		];
		foreach ( $cases as $label => $signature ) {
			$key    = $this->key;
			$client = new Client(
				[
					'id'      => self::ID,
					'address' => 'https://b.example',
					'key'     => $key,
				],
				static fn( string $url, array $args ): array => [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'x-lwm-response-signature' => $signature( $args, $key ) ],
					'body'     => '{"status":"done"}',
				],
				static fn(): int => self::NOW
			);
			$this->assertSame( LinkException::UNSIGNED, $this->failure( fn() => $client->json( 'POST', '/jobs/aaaaaaaaaaaaaaaa/tick' ) )->reason, $label );
		}
	}

	public function test_a_signed_answer_is_accepted_and_errors_need_no_signature(): void {
		$this->answer = self::reply( 201, [ 'id' => 'bbbbbbbbbbbbbbbb' ] );
		$this->assertSame( 201, $this->client()->json( 'POST', '/pull', [ 'ref' => 'x' ] )['status'] );

		$this->answer = self::reply( 404, [ 'code' => 'lw_migration_no_job', 'message' => 'No.' ] );
		$this->answer['headers']['x-lwm-response-signature'] = '';
		$this->assertSame( LinkException::REMOTE, $this->failure( fn() => $this->client()->json( 'GET', '/jobs/aaaaaaaaaaaaaaaa' ) )->reason, 'A refusal (401 included) cannot carry a signature: the guard never knew the link.' );

		$this->answer = self::reply( 200, [ 'ok' => true ] );
		$this->answer['headers']['x-lwm-response-signature'] = '';
		$this->assertSame( [ 'ok' => true ], Client::unsigned( $this->send(), 'https://b.example', '/lw-migration/v1/link/pair', [ 'a' => 1 ], self::NOW ), 'The pairing answer proves itself with the code (PairingMessage).' );
	}

	public function test_http_is_sent_and_marked_other_schemes_are_not(): void {
		$this->client( 'http://b.example' )->json( 'POST', '/hello' );
		$this->assertSame( 'http://b.example/index.php?rest_route=/lw-migration/v1/link/hello', $this->sent[0][0] );
		$this->assertTrue( Client::insecure( 'http://b.example' ) );
		$this->assertTrue( Client::insecure( 'HTTP://b.example' ) );
		$this->assertFalse( Client::insecure( 'https://b.example' ) );

		foreach ( [ 'ftp://b.example', 'file:///etc', 'b.example' ] as $address ) {
			$this->assertSame( LinkException::SCHEME, $this->failure( fn() => $this->client( $address )->json( 'POST', '/hello' ) )->reason, $address );
			$this->assertSame( LinkException::SCHEME, $this->failure( fn() => Client::unsigned( $this->send(), $address, '/lw-migration/v1/link/pair', [], self::NOW ) )->reason );
		}
		$this->assertCount( 1, $this->sent, 'Nothing is sent to another scheme.' );
	}

	public function test_answers_are_read_up_to_one_mebibyte(): void {
		$client = $this->client();
		$client->json( 'POST', '/hello' );
		$client->raw( 'POST', '/jobs/aaaaaaaaaaaaaaaa/upload/0', 'x' );
		Client::unsigned( $this->send(), 'https://b.example', '/lw-migration/v1/link/pair', [ 'a' => 1 ], self::NOW );
		$this->assertCount( 3, $this->sent );
		foreach ( $this->sent as [ , $args ] ) {
			$this->assertSame( 1048576, $args['limit_response_size'] );
			$this->assertSame( 0, $args['redirection'] );
		}
		$this->assertSame( 1048576, Client::MAX_ANSWER );

		$this->answer = self::reply( 200, '{"a":"' . str_repeat( 'x', 2000000 ) . '"}' );
		$this->assertSame( LinkException::PROTOCOL, $this->failure( fn() => $client->json( 'POST', '/hello' ) )->reason );
		$this->answer = self::reply( 200, '{"a":"' . str_repeat( 'x', 1048576 - 8 ) . '"}' );
		$this->assertSame( LinkException::PROTOCOL, $this->failure( fn() => $client->json( 'POST', '/hello' ) )->reason, 'Exactly the limit: it may have been cut.' );
		$this->answer = self::reply( 200, '{"a":"' . str_repeat( 'x', 1048576 - 9 ) . '"}' );
		$this->assertSame( 200, $client->json( 'POST', '/hello' )['status'], 'One byte below the limit is read.' );
	}

	/**
	 * Core's transport, streaming the body to the requested file and honouring limit_response_size there.
	 *
	 * @param string                    $body    The answer the other site sends (as decoded by the transport).
	 * @param array<int, array<string, mixed>> $calls Requests seen (args), filled in.
	 */
	private function core( string $body, array &$calls, string $dir ): void {
		Functions\when( 'get_temp_dir' )->justReturn( $dir . '/' );
		$key = $this->key;
		SafeWeb::install(
			static function ( string $url, array $args ) use ( $body, &$calls, $key ): array {
				$calls[] = $args;
				$full    = $body;
				if ( ! empty( $args['stream'] ) ) {
					file_put_contents( (string) $args['filename'], isset( $args['limit_response_size'] ) ? substr( $body, 0, (int) $args['limit_response_size'] ) : $body );
					$body = '';
				}

				$nonce = $args['headers'][ Signer::HEADER_NONCE ] ?? null;

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => is_string( $nonce ) ? [ 'x-lwm-response-signature' => Signer::answer( self::ID, $key, $nonce, 200, $full ) ] : [],
					'body'     => $body,
					'filename' => $args['filename'] ?? null,
				];
			}
		);
	}

	public function test_the_default_transport_streams_the_answer_to_a_capped_file(): void {
		$dir   = TempDir::create();
		$calls = [];
		try {
			$this->core( '{"ok":true}', $calls, $dir );
			$client = new Client(
				[
					'id'      => self::ID,
					'address' => 'https://b.example',
					'key'     => $this->key,
				],
				null,
				static fn(): int => self::NOW
			);
			$this->assertSame( [ 'ok' => true ], $client->json( 'POST', '/hello', [ 'x' => 1 ] )['json'] );
			$this->assertSame( [ 'ok' => true ], Client::unsigned( null, 'https://b.example', '/lw-migration/v1/link/pair', [ 'a' => 1 ], self::NOW ) );
			$this->assertCount( 2, $calls );
			foreach ( $calls as $args ) {
				$this->assertTrue( $args['stream'], 'Streamed: core never inflates a streamed body a second time.' );
				$this->assertStringStartsWith( $dir . '/', (string) $args['filename'] );
				$this->assertSame( 1048576, $args['limit_response_size'] );
				$this->assertSame( 'identity', $args['headers']['Accept-Encoding'] );
				$this->assertSame( 0, $args['redirection'] );
			}
			$this->assertSame( [ '.', '..' ], scandir( $dir ), 'The answer file is removed.' );

			$calls = [];
			$this->core( '{"a":"' . str_repeat( 'x', 3 * 1048576 ) . '"}', $calls, $dir );
			$this->assertSame( LinkException::PROTOCOL, $this->failure( fn() => $client->json( 'POST', '/hello' ) )->reason );
			$this->assertSame( [ '.', '..' ], scandir( $dir ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_a_transport_failure_is_told_in_fixed_words_without_its_paths(): void {
		$cases = [
			'Could not open handle for fopen() to /private/var/folders/x/T/lwm-link-a1B2c3' => 'no answer arrived',
			'cURL error 28: Operation timed out after 30001 milliseconds'                    => 'did not answer in time',
			'cURL error 6: Could not resolve host: b.example'                               => 'its name cannot be looked up',
			'cURL error 7: Failed to connect to b.example port 443'                         => 'the connection was refused',
			'cURL error 60: SSL certificate problem: unable to get local issuer certificate' => 'the secure connection failed',
			'cURL error 77: error setting certificate verify locations: CAfile: /etc/ssl/cert.pem' => 'the secure connection failed',
			'A valid URL was not provided.'                                                 => 'does not send requests to this address',
			'cURL error 286: something /home/site/x'                                        => 'no answer arrived',
		];
		foreach ( $cases as $transport => $text ) {
			$this->answer = new FakeWpError( $transport );
			$e            = $this->failure( fn() => $this->client()->json( 'POST', '/hello' ) );
			$this->assertSame( LinkException::UNREACHABLE, $e->reason );
			$this->assertStringContainsString( 'https://b.example', $e->getMessage() );
			$this->assertStringContainsString( $text, $e->getMessage(), $transport );
			foreach ( [ '/private', '/etc', '/home', 'lwm-link', 'cURL' ] as $piece ) {
				$this->assertStringNotContainsString( $piece, $e->getMessage(), $transport );
			}
		}
	}

	public function test_a_request_never_sent_names_the_wordpress_proxy_setting_among_the_reasons(): void {
		$this->answer = new FakeWpError( 'The proxy of this server prevents checking the address of https://b.example.', 'http_request_not_executed' );
		$e            = $this->failure( fn() => $this->client()->json( 'POST', '/hello' ) );

		$this->assertSame( LinkException::UNREACHABLE, $e->reason );
		$this->assertStringContainsString( "the site's WordPress proxy setting", $e->getMessage() );
		$this->assertStringContainsString( 'lacks the cURL extension', $e->getMessage() );
	}

	public function test_the_answer_file_is_removed_when_the_transport_fails_or_throws(): void {
		$dir = TempDir::create();
		try {
			Functions\when( 'get_temp_dir' )->justReturn( $dir . '/' );
			$client = new Client(
				[
					'id'      => self::ID,
					'address' => 'https://b.example',
					'key'     => $this->key,
				],
				null,
				static fn(): int => self::NOW
			);
			$seen = [];
			SafeWeb::install(
				static function ( string $url, array $args ) use ( &$seen ): FakeWpError {
					$seen[] = $args['filename'];
					file_put_contents( (string) $args['filename'], 'partial' );

					return new FakeWpError( 'Could not open handle for fopen() to ' . $args['filename'] );
				}
			);
			$e = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
			$this->assertStringNotContainsString( $dir, $e->getMessage() );
			$this->assertFileDoesNotExist( (string) $seen[0] );

			SafeWeb::install(
				static function ( string $url, array $args ) use ( &$seen ): array {
					$seen[] = $args['filename'];
					throw new \RuntimeException( 'Transport broke.' );
				}
			);
			try {
				$client->json( 'POST', '/hello' );
				$this->fail( 'The exception goes on.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'Transport broke.', $e->getMessage() );
			}
			$this->assertFileDoesNotExist( (string) $seen[1] );
			$this->assertSame( [ '.', '..' ], scandir( $dir ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * Issue #2: a connection never reaches the metadata service, carrier-grade
	 * NAT or benchmark ranges, also when its name resolves there; nothing is sent.
	 */
	#[RunInSeparateProcess]
	public function test_a_connection_to_an_internal_range_is_refused_before_a_request(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$dir   = TempDir::create();
		$calls = [];
		try {
			$this->core( '{"ok":true}', $calls, $dir );
			AddressGuard::useResolver( static fn( string $host ): array => 'meta.example' === $host ? [ '169.254.169.254' ] : [ '100.64.0.9' ] );
			foreach ( [ 'https://meta.example', 'https://cgnat.example', 'http://198.18.0.1' ] as $address ) {
				$client = new Client(
					[
						'id'      => self::ID,
						'address' => $address,
						'key'     => $this->key,
					],
					null,
					static fn(): int => self::NOW
				);
				$e      = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
				$this->assertSame( LinkException::UNREACHABLE, $e->reason, $address );
				$this->assertStringContainsString( 'does not send requests to this address', $e->getMessage() );
				$e = $this->failure( fn() => Client::unsigned( null, $address, '/lw-migration/v1/link/pair', [ 'a' => 1 ], self::NOW ) );
				$this->assertSame( LinkException::UNREACHABLE, $e->reason, $address );
			}
			$this->assertSame( [], $calls, 'Nothing was sent.' );
			$this->assertSame( [ '.', '..' ], scandir( $dir ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	#[RunInSeparateProcess]
	public function test_an_answer_that_could_not_be_pinned_is_dropped_and_explained(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		$dir   = TempDir::create();
		$calls = [];
		try {
			$this->core( '{"ok":true}', $calls, $dir );
			$curl       = SafeWeb::install(
				static function ( string $url, array $args ) use ( &$calls ): array {
					$calls[] = $args;

					return self::reply( 200, [ 'ok' => true ] );
				}
			);
			$curl->curl = false; // Another transport: the pin hook never fires.
			$client     = new Client(
				[
					'id'      => self::ID,
					'address' => 'https://b.example',
					'key'     => $this->key,
				],
				null,
				static fn(): int => self::NOW
			);
			$e          = $this->failure( fn() => $client->json( 'POST', '/hello' ) );
			$this->assertSame( LinkException::UNREACHABLE, $e->reason );
			$this->assertStringContainsString( 'lacks the cURL extension', $e->getMessage() );
			$this->assertTrue( $curl->clean() );
		} finally {
			TempDir::remove( $dir );
		}
	}
}
