<?php
/**
 * Recorded answers behind an injected HTTP transport.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Plan D3: the cloud clients take a transport closure (default
 * wp_remote_request); tests hand them send() and get the next recorded
 * answer for every request, in order. An answer may be a closure
 * fn( string $url, array $args ): array|FakeWpError to inspect the request.
 * install() stubs the wp_remote_retrieve_* helpers like WordPress (header
 * names are matched in lower case).
 */
final class RecordedHttp {

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public array $requests = [];

	/**
	 * @param array<int, array<string, mixed>|FakeWpError|\Closure> $answers In order.
	 */
	public function __construct( private array $answers = [] ) {
	}

	/**
	 * @param array<string, mixed>|FakeWpError|\Closure $answer One more answer.
	 */
	public function push( $answer ): void {
		$this->answers[] = $answer;
	}

	public function send(): \Closure {
		return function ( string $url, array $args ) {
			$this->requests[] = [ $url, $args ];
			if ( [] === $this->answers ) {
				throw new \RuntimeException( 'No recorded answer for ' . ( $args['method'] ?? 'GET' ) . ' ' . $url );
			}
			$answer = array_shift( $this->answers );

			return $answer instanceof \Closure ? $answer( $url, $args ) : $answer;
		};
	}

	/**
	 * @param int                   $status  HTTP status.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers Headers (any case).
	 * @return array<string, mixed>
	 */
	public static function answer( int $status, string $body = '', array $headers = [] ): array {
		return [
			'response' => [ 'code' => $status ],
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
			'body'     => $body,
		];
	}

	public static function install(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $r ) => $r instanceof FakeWpError );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
	}
}
