<?php
/**
 * The other site of a push or pull, answering signed job calls from a script.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Link\LinkClients;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Support\SecretBox;

/**
 * Holds one confirmed outgoing link (OUT, to https://b.example, "Site B")
 * on a FakeWpdb, records every request (method, route, body, timeout,
 * headers) and answers it with $handler when set, else with the next
 * scripted answer. An answer is a job view (200 JSON), reply() (any status
 * and JSON) or a FakeWpError (the transport failed); like B's link routes,
 * every answer is signed for its request (unless it sets the signature
 * header itself). Needs
 * FakeWpdb::stubCache() and stubHttp() in the test's setUp().
 */
final class ScriptedPeer {

	public const OUT = '0123456789abcdef';

	public const ADDRESS = 'https://b.example';

	public Links $links;

	public string $key;

	/** @var array<int, array{method: string, route: string, body: string, timeout: int, headers: array<string, string>}> */
	public array $sent = [];

	/** @var array<int, mixed> */
	public array $answers = [];

	/**
	 * fn( string $method, string $route, string $body ): mixed — answers instead of the script.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $handler = null;

	public function __construct() {
		$this->links = new Links( new FakeWpdb(), new SecretBox( str_repeat( 'k', 32 ) ) );
		$this->key   = random_bytes( 32 );
		$this->links->add(
			[
				'id'         => self::OUT,
				'direction'  => Links::OUT,
				'address'    => self::ADDRESS,
				'name'       => 'Site B',
				'allow'      => [ 'pull', 'push' ],
				'createdBy'  => [ 'id' => 3, 'login' => 'me' ],
				'pairedFrom' => '',
			],
			$this->key,
			time() - 10,
			true
		);
	}

	/** The WordPress functions a Client needs to talk to this peer. */
	public static function stubHttp(): void {
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
	 * @param \Closure $now fn(): int.
	 */
	public function clients( \Closure $now ): LinkClients {
		return new LinkClients( $this->links, $now, $this->send() );
	}

	/**
	 * @param int          $code HTTP status.
	 * @param array<mixed> $json Answer.
	 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
	 */
	public static function reply( int $code, array $json ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => [ 'date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ],
			'body'     => (string) json_encode( $json ),
		];
	}

	/**
	 * @return array<int, string> The routes requested so far.
	 */
	public function routes(): array {
		return array_column( $this->sent, 'route' );
	}

	private function send(): \Closure {
		return function ( string $url, array $args ) {
			parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
			$request      = [
				'method'  => (string) $args['method'],
				'route'   => (string) ( $query['rest_route'] ?? '' ),
				'body'    => (string) ( $args['body'] ?? '' ),
				'timeout' => (int) $args['timeout'],
				'headers' => (array) $args['headers'],
			];
			$this->sent[] = $request;
			$answer       = null !== $this->handler ? ( $this->handler )( $request['method'], $request['route'], $request['body'] ) : array_shift( $this->answers );
			if ( null === $answer ) {
				throw new \LogicException( 'No answer scripted for ' . $request['method'] . ' ' . $request['route'] );
			}
			if ( $answer instanceof FakeWpError ) {
				return $answer;
			}

			return $this->signed( isset( $answer['response'] ) ? $answer : self::reply( 200, $answer ), $request['headers'] );
		};
	}

	/**
	 * The answer signed like B's link routes sign it, unless the script set
	 * the signature header itself.
	 *
	 * @param array<string, mixed>  $answer  Answer.
	 * @param array<string, string> $headers Request headers.
	 * @return array<string, mixed>
	 */
	private function signed( array $answer, array $headers ): array {
		$nonce = $headers[ Signer::HEADER_NONCE ] ?? null;
		if ( is_string( $nonce ) ) {
			$answer['headers'] = (array) ( $answer['headers'] ?? [] ) + [ 'x-lwm-response-signature' => Signer::answer( self::OUT, $this->key, $nonce, (int) $answer['response']['code'], (string) $answer['body'] ) ];
		}

		return $answer;
	}
}
