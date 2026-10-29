<?php
/**
 * WordPress' action hooks around one request, as the cURL transport fires them.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use Brain\Monkey\Functions;

/**
 * install() stands in for add_action()/remove_action(); wrap() turns a
 * RecordedHttp transport into one that fires requests-requests.before_request
 * and http_api_curl (with a fresh cURL handle) like WordPress' cURL transport
 * does, or fires nothing when $curl is false (another transport, or a
 * pre_http_request answer). The handle stays in $handle so a test can run it.
 */
final class CurlHooks {

	/** @var array<string, array<int, callable>> Callbacks by hook name. */
	public array $hooks = [];

	/** @var array<string, mixed> Requests options the before_request callbacks may change. */
	public array $options = [];

	/** Whether the transport fires the hooks (cURL). */
	public bool $curl = true;

	/** The http_api_curl callbacks registered while the last request was sent. */
	public int $seen = 0;

	/** @var \CurlHandle|resource|null Handle of the last request. */
	public $handle = null;

	public function install(): self {
		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback ): bool {
				$this->hooks[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( string $hook, $callback ): bool {
				$this->hooks[ $hook ] = array_values( array_filter( $this->hooks[ $hook ] ?? [], static fn( $c ) => $c !== $callback ) );
				return true;
			}
		);

		return $this;
	}

	/**
	 * @param \Closure $inner fn( string $url, array $args ): array|FakeWpError.
	 */
	public function wrap( \Closure $inner ): \Closure {
		return function ( string $url, array $args ) use ( $inner ) {
			$this->seen   = count( $this->hooks['http_api_curl'] ?? [] );
			$this->handle = curl_init();
			foreach ( $this->curl ? ( $this->hooks['requests-requests.before_request'] ?? [] ) : [] as $callback ) {
				$headers = [];
				$data    = [];
				$type    = $args['method'] ?? 'GET';
				$target  = $url;
				$callback( $target, $headers, $data, $type, $this->options );
			}
			foreach ( $this->curl ? ( $this->hooks['http_api_curl'] ?? [] ) : [] as $callback ) {
				$callback( $this->handle, $args, $url );
			}

			return $inner( $url, $args );
		};
	}

	/** Whether no hook is left registered. */
	public function clean(): bool {
		return [] === ( $this->hooks['http_api_curl'] ?? [] ) && [] === ( $this->hooks['requests-requests.before_request'] ?? [] );
	}
}
