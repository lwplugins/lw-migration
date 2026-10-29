<?php
/**
 * A file server behind the WordPress HTTP functions (Brain Monkey).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Serves one body with or without range support, behind the guarded
 * transport (SafeWeb: every name is a public address). Like WordPress, it honours
 * `limit_response_size` (also when streaming) and writes a streamed body to
 * `filename`, truncating it first. `$handler` replaces the server for hostile
 * answers; its body still passes through the limit and the stream.
 */
final class FakeHttp {

	public static string $body = '';

	public static bool $ranges = true;

	public static string $contentType = 'application/octet-stream';

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	public static array $requests = [];

	public static int $failNext = 0;

	public static string $failMessage = 'Operation timed out';

	public static string $failCode = 'http_request_failed';

	/** @var (callable(string, array<string, mixed>): (array<string, mixed>|FakeWpError))|null */
	public static $handler = null;

	public static function install( string $body, bool $ranges = true, string $contentType = 'application/octet-stream' ): void {
		self::$body        = $body;
		self::$ranges      = $ranges;
		self::$contentType = $contentType;
		self::$requests    = [];
		self::$failNext    = 0;
		self::$failMessage = 'Operation timed out';
		self::$failCode    = 'http_request_failed';
		self::$handler     = null;
		SafeWeb::install( static fn( string $url, array $args ) => self::get( $url, $args ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $h ) => $r['headers'][ strtolower( (string) $h ) ] ?? '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
	}

	/**
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>|FakeWpError
	 */
	public static function get( string $url, array $args = [] ) {
		self::$requests[] = [ $url, $args ];
		if ( self::$failNext > 0 ) {
			--self::$failNext;
			return new FakeWpError( self::$failMessage, self::$failCode );
		}
		$response = null === self::$handler ? self::serve( $args ) : ( self::$handler )( $url, $args );
		if ( $response instanceof FakeWpError ) {
			return $response;
		}
		$body = (string) $response['body'];
		if ( isset( $args['limit_response_size'] ) ) {
			$body = substr( $body, 0, (int) $args['limit_response_size'] );
		}
		if ( ! empty( $args['stream'] ) ) {
			file_put_contents( (string) $args['filename'], $body );
			$body = '';
		}
		$response['body'] = $body;

		return $response;
	}

	/**
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>
	 */
	private static function serve( array $args ): array {
		$body    = self::$body;
		$code    = 200;
		$headers = [
			'content-type'   => self::$contentType,
			'content-length' => (string) strlen( $body ),
		];
		$range   = $args['headers']['Range'] ?? null;
		if ( is_string( $range ) && self::$ranges && 1 === preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $m ) ) {
			$from                      = (int) $m[1];
			$to                        = min( (int) $m[2], strlen( self::$body ) - 1 );
			$body                      = (string) substr( self::$body, $from, $to - $from + 1 );
			$code                      = 206;
			$headers['content-range']  = "bytes {$from}-{$to}/" . strlen( self::$body );
			$headers['content-length'] = (string) strlen( $body );
		}

		return [
			'response' => [ 'code' => $code ],
			'headers'  => $headers,
			'body'     => $body,
		];
	}
}
