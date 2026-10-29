<?php
/**
 * The other site of a connection, answering like its link routes would.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Link\Codes;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Link\Nonces;
use LightweightPlugins\Migration\Link\Pairing;
use LightweightPlugins\Migration\Link\Signer;
use LightweightPlugins\Migration\Support\SecretBox;

/**
 * Site B on its own FakeWpdb and sealing key: /link/pair, /link/hello and
 * DELETE /link mapped like Rest\LinkPeerRoutes maps them, every other route
 * refused. `$as` runs B's work while the WordPress stubs answer as B
 * (site_url, get_bloginfo). Like core, a streamed answer is written to
 * `filename` and cut at `limit_response_size`. Needs the wp_remote_retrieve_*
 * stubs and is_wp_error() recognising FakeWpError.
 */
final class FakeLinkPeer {

	public FakeWpdb $db;

	public Links $links;

	public Codes $codes;

	public Nonces $nonces;

	public Pairing $pairing;

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public array $requests = [];

	/** @var array<int, array<string, mixed>> Links B's admins were told about. */
	public array $notified = [];

	/** Whether B cannot be reached at all. */
	public bool $down = false;

	/** Seconds B's clock is ahead of the real one (for its Date header). */
	public int $skew = 0;

	/**
	 * @param \Closure $as fn( \Closure $work ): mixed — runs $work as site B.
	 */
	public function __construct( private \Closure $as ) {
		$box           = new SecretBox( str_repeat( 'b', 32 ) );
		$this->db      = new FakeWpdb();
		$this->links   = new Links( $this->db, $box );
		$this->codes   = new Codes( $this->db, $box );
		$this->nonces  = new Nonces( $this->db );
		$this->pairing = new Pairing(
			$this->links,
			$this->codes,
			static fn(): int => time(),
			null,
			function ( array $link ): void {
				$this->notified[] = $link;
			}
		);
	}

	/**
	 * A new code of B.
	 *
	 * @param array<int, string> $allow Rights.
	 */
	public function code( array $allow = [ 'pull' ] ): string {
		return ( $this->as )( fn(): string => $this->pairing->createCode( [ 'id' => 7, 'login' => 'boss' ], $allow )['code'] );
	}

	/**
	 * The transport of the other side: fn( string $url, array $args ): array|FakeWpError.
	 */
	public function send(): \Closure {
		return function ( string $url, array $args ) {
			$this->requests[] = [ $url, $args ];
			if ( $this->down ) {
				return new FakeWpError( 'cURL error 7: Failed to connect' );
			}
			$response = ( $this->as )( fn(): array => $this->answer( $url, $args ) );
			$body     = substr( (string) $response['body'], 0, (int) ( $args['limit_response_size'] ?? PHP_INT_MAX ) );
			if ( ! empty( $args['stream'] ) ) {
				file_put_contents( (string) $args['filename'], $body );
			}
			$response['body'] = $body;

			return $response;
		};
	}

	/**
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
	 */
	private function answer( string $url, array $args ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$route  = (string) ( $query['rest_route'] ?? '' );
		$body   = (string) ( $args['body'] ?? '' );
		$method = (string) ( $args['method'] ?? 'GET' );
		if ( Pairing::ROUTE === $route ) {
			$json = json_decode( $body, true );
			try {
				$answer = $this->pairing->opens( $json ) ? $this->pairing->accept( $json, '203.0.113.5' ) : null;
			} catch ( \RuntimeException $e ) {
				return Links::CODE_USED === $e->getCode()
					? $this->reply( 409, [ 'code' => 'lw_migration_link_code_used', 'message' => 'The code was used but the connection could not be saved. Make a new code.' ] )
					: $this->reply( 409, [ 'code' => 'lw_migration_link_full', 'message' => 'This site already has 20 connections. Remove one before pairing.' ] );
			}

			return null === $answer ? $this->refused() : $this->reply( 200, $answer );
		}
		$hello = '/lw-migration/v1/link/hello' === $route && 'POST' === $method;
		$drop  = Guard::ROOT === $route && 'DELETE' === $method;
		$link  = $hello || $drop ? Guard::verify( $method, $route, $query, (array) ( $args['headers'] ?? [] ), $body, $hello, $this->links, $this->nonces, time() + $this->skew ) : '';
		if ( ! is_array( $link ) ) {
			return $this->refused();
		}
		$answer = $drop ? $this->reply( 200, [ 'removed' => $this->links->remove( (string) $link['id'] ) ] ) : $this->reply( 200, $this->pairing->hello( $link ) );
		$nonce  = (string) ( $args['headers'][ Signer::HEADER_NONCE ] ?? '' );
		$answer['headers']['x-lwm-response-signature'] = Signer::answer( (string) $link['id'], (string) $link['key'], $nonce, 200, $answer['body'] ); // As the routes sign it (LinkAnswers).

		return $answer;
	}

	/**
	 * @param array<mixed> $json Answer.
	 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
	 */
	private function reply( int $code, array $json ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => [ 'date' => gmdate( 'D, d M Y H:i:s', time() + $this->skew ) . ' GMT' ],
			'body'     => (string) json_encode( $json ),
		];
	}

	/**
	 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
	 */
	private function refused(): array {
		return $this->reply(
			401,
			[
				'code'    => 'lw_migration_link_refused',
				'message' => 'This request is not accepted.',
				'data'    => [ 'status' => 401 ],
			]
		);
	}
}
