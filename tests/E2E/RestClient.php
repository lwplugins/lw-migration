<?php
/**
 * Talks to an E2E site's REST API over HTTP.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * PHP's built-in web server on the site folder: no rewrite rules, so routes go
 * through ?rest_route=. The Host header names the site's own address, which is
 * what WordPress builds its cookie names and URLs from. Every request is its
 * own PHP request (four workers, the house memory limit), so a tick reloads
 * the job like it would under a real web server. A network served here has
 * 127.0.0.1:PORT as its domain (the Host header names it) and reaches a
 * subdirectory site's REST API as /<site>/?rest_route= (the `path` argument).
 */
final class RestClient {

	public const PORT = 8093;

	/** The memory limit of every request (the house harness value). */
	public const MEMORY_LIMIT = '256M';

	/** @var resource|null */
	private $server = null;

	/**
	 * @param string $site Site name (WpSites).
	 * @param string $host Host header; '' for site-<name>.test.
	 */
	public function __construct( private string $site, private string $host = '' ) {
	}

	public function start(): void {
		if ( self::listening() ) {
			throw new \RuntimeException( 'Port ' . self::PORT . ' is already in use; stop the old web server first.' );
		}
		$this->server = proc_open(
			[ (string) getenv( 'LWM_E2E_PHP' ), '-d', 'post_max_size=64M', '-d', 'memory_limit=' . self::MEMORY_LIMIT, '-S', '127.0.0.1:' . self::PORT, '-t', WpSites::path( $this->site ) ],
			[ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ],
			$pipes,
			null,
			array_merge( getenv(), [ 'PHP_CLI_SERVER_WORKERS' => '4' ] )
		);
		if ( ! is_resource( $this->server ) ) {
			throw new \RuntimeException( 'Cannot start the web server.' );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			if ( self::listening() ) {
				return;
			}
			usleep( 100000 );
		}
		throw new \RuntimeException( 'The web server did not start.' );
	}

	/** Stops the server and its workers (they outlive a terminated parent). */
	public function stop(): void {
		if ( is_resource( $this->server ) ) {
			$pid = (int) proc_get_status( $this->server )['pid'];
			$kill = proc_open( [ 'pkill', '-TERM', '-P', (string) $pid ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
			if ( is_resource( $kill ) ) {
				proc_close( $kill );
			}
			proc_terminate( $this->server );
			proc_close( $this->server );
			for ( $i = 0; $i < 50 && self::listening(); $i++ ) {
				usleep( 100000 );
			}
		}
		$this->server = null;
	}

	/**
	 * A new login session of the user (like a fresh login in a browser): its
	 * logged_in cookie and a REST nonce bound to that session.
	 *
	 * @return array{cookie: string, nonce: string}
	 */
	public function login( string $user ): array {
		$php  = '$u = get_user_by( "login", ' . var_export( $user, true ) . ' );'
			. ' $exp = time() + 3600; $token = WP_Session_Tokens::get_instance( $u->ID )->create( $exp );'
			. ' $cookie = wp_generate_auth_cookie( $u->ID, $exp, "logged_in", $token );'
			. ' $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; wp_set_current_user( $u->ID );'
			. ' echo wp_json_encode( [ "cookie" => LOGGED_IN_COOKIE . "=" . rawurlencode( $cookie ), "nonce" => wp_create_nonce( "wp_rest" ) ] );';
		$auth = json_decode( WpSites::ok( $this->site, 'eval', $php ), true );
		if ( ! is_array( $auth ) ) {
			throw new \RuntimeException( 'Cannot log in ' . $user . '.' );
		}

		return [
			'cookie' => (string) $auth['cookie'],
			'nonce'  => (string) $auth['nonce'],
		];
	}

	/**
	 * @param string                                    $method  HTTP method.
	 * @param string                                    $route   Route below lw-migration/v1.
	 * @param array{cookie: string, nonce: string}|null $auth    Login; a nonce '' sends the cookie only.
	 * @param array<string, scalar>                     $query   Query arguments.
	 * @param array<string, mixed>|string|null          $body    JSON object, raw bytes or nothing.
	 * @param array<int, string>                        $headers More request headers.
	 * @param string                                    $path    Path of a subdirectory site ('/b2'), '' for the root.
	 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
	 */
	public function call( string $method, string $route, ?array $auth, array $query = [], $body = null, array $headers = [], string $path = '' ): array {
		$url  = 'http://127.0.0.1:' . self::PORT . $path . '/?' . http_build_query( [ 'rest_route' => '/lw-migration/v1' . $route ] + $query );
		$send = array_merge( [ 'Host: ' . ( '' !== $this->host ? $this->host : 'site-' . $this->site . '.test' ), 'Expect:' ], $headers );
		if ( null !== $auth ) {
			$send[] = 'Cookie: ' . $auth['cookie'];
			if ( '' !== $auth['nonce'] ) {
				$send[] = 'X-WP-Nonce: ' . $auth['nonce'];
			}
		}
		$typed = [] !== preg_grep( '/^content-type:/i', $headers ); // A caller's own media type wins (multipart, garbled JSON).
		if ( is_array( $body ) ) {
			$send[] = $typed ? null : 'Content-Type: application/json';
			$body   = (string) json_encode( $body );
		} elseif ( is_string( $body ) && ! $typed ) {
			$send[] = 'Content-Type: application/octet-stream';
		}
		$send = array_values( array_filter( $send, 'is_string' ) );
		$curl    = curl_init( $url );
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $send,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => 300,
		];
		if ( null !== $body ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}
		if ( 'HEAD' === $method ) {
			$options[ CURLOPT_NOBODY ] = true; // Else curl waits for the Content-Length bytes a HEAD answer never has.
		}
		curl_setopt_array( $curl, $options );
		$raw = curl_exec( $curl );
		if ( ! is_string( $raw ) ) {
			throw new \RuntimeException( $method . ' ' . $route . ' failed: ' . curl_error( $curl ) );
		}
		$size  = (int) curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
		$named = [];
		foreach ( explode( "\r\n", substr( $raw, 0, $size ) ) as $line ) {
			if ( str_contains( $line, ':' ) ) {
				[ $key, $value ]                     = explode( ':', $line, 2 );
				$named[ strtolower( trim( $key ) ) ] = trim( $value );
			}
		}
		$content = substr( $raw, $size );

		return [
			'status'  => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'headers' => $named,
			'body'    => $content,
			'json'    => json_decode( $content, true ),
		];
	}

	private static function listening(): bool {
		$socket = @fsockopen( '127.0.0.1', self::PORT ); // phpcs:ignore -- a refused connection is the answer, not an error.
		if ( false === $socket ) {
			return false;
		}
		fclose( $socket );

		return true;
	}
}
