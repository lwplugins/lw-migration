<?php
/**
 * REST calls whose every answer is checked for leaks and memory.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use PHPUnit\Framework\Assert;

/**
 * Wraps RestClient: every JSON answer is scanned for the site's folders,
 * secrets and the caller's own login cookie, and the peak memory a request
 * reported (header of the test-only probe) is noted per route and held well
 * below the request limit.
 */
final class CheckedRest {

	private const PEAK_HEADER = 'x-lwm-peak-memory';

	/** Half of RestClient::MEMORY_LIMIT: a request near the limit is a finding. */
	private const PEAK_CEILING = 134217728;

	/** @var array<string, int> Highest peak memory per route kind. */
	private array $peaks = [];

	/**
	 * @param RestClient         $client  The site's client.
	 * @param array<int, string> $secrets Texts no JSON answer may contain.
	 */
	public function __construct( private RestClient $client, private array $secrets ) {
	}

	/**
	 * @param string                                    $method  HTTP method.
	 * @param string                                    $route   Route below lw-migration/v1.
	 * @param array{cookie: string, nonce: string}|null $auth    Login.
	 * @param array<string, scalar>                     $query   Query arguments.
	 * @param array<string, mixed>|string|null          $body    JSON object, raw bytes or nothing.
	 * @param array<int, string>                        $headers More request headers.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 */
	public function call( string $method, string $route, ?array $auth, array $query = [], $body = null, array $headers = [] ): array {
		$response = $this->client->call( $method, $route, $auth, $query, $body, $headers );
		if ( str_starts_with( $response['headers']['content-type'] ?? '', 'application/json' ) ) {
			$this->assertNothingPrivate( $response, $auth );
		}
		if ( isset( $response['headers'][ self::PEAK_HEADER ] ) ) {
			$kind                 = $method . ' ' . preg_replace( '/[a-f0-9]{16}/', '{id}', $route );
			$peak                 = (int) $response['headers'][ self::PEAK_HEADER ];
			$this->peaks[ $kind ] = max( $this->peaks[ $kind ] ?? 0, $peak );
			Assert::assertLessThan( self::PEAK_CEILING, $peak, $kind . ' stays well below the request memory limit.' );
		}

		return $response;
	}

	/**
	 * Ticks until the job is finished or waits for input.
	 *
	 * @param array{cookie: string, nonce: string} $auth Login.
	 * @return array<string, mixed> The job view.
	 */
	public function drive( array $auth, string $id ): array {
		for ( $i = 0; $i < 300; $i++ ) {
			$tick = $this->call( 'POST', '/jobs/' . $id . '/tick', $auth );
			Assert::assertSame( 200, $tick['status'], $tick['body'] );
			$job = (array) $tick['json'];
			if ( in_array( $job['status'] ?? '', [ 'done', 'failed', 'cancelled', 'needs_input' ], true ) ) {
				return $job;
			}
		}
		throw new \RuntimeException( 'The job did not finish in 300 ticks.' );
	}

	/** @return array<string, int> Highest peak memory (bytes) per route kind. */
	public function peaks(): array {
		return $this->peaks;
	}

	/**
	 * @param array{status: int, headers: array<string, string>, body: string, json: mixed} $response Answer.
	 * @param array{cookie: string, nonce: string}|null                                     $auth     The login used.
	 */
	private function assertNothingPrivate( array $response, ?array $auth ): void {
		$texts = [ $response['body'], (string) json_encode( $response['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ];
		$never = $this->secrets;
		if ( null !== $auth ) {
			$never[] = rawurldecode( substr( $auth['cookie'], (int) strpos( $auth['cookie'], '=' ) + 1 ) );
		}
		foreach ( $texts as $text ) {
			foreach ( $never as $secret ) {
				Assert::assertStringNotContainsString( $secret, $text );
			}
		}
	}
}
