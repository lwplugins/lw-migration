<?php
/**
 * Minimal global WP_REST_Request for tests of permission callbacks (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Method, route, headers (canonical names, a repeated header joined with "," like core),
	 * query, route pattern parameters, raw body and the parameters core fills from $_POST and $_FILES.
	 */
	final class WP_REST_Request {

		/** @var array<string, array<int, string>> */
		private array $headers = [];

		/** @var array<string, mixed> */
		public array $query = [];

		/** @var array<string, mixed> */
		public array $bodyParams = [];

		/** @var array<string, mixed> */
		public array $fileParams = [];

		/** @var array<string, mixed> Parameters core takes from the matched route pattern. */
		private array $urlParams = [];

		public function __construct( private string $method = 'GET', private string $route = '', private string $body = '' ) {
			$this->query = [ 'rest_route' => $route ];
		}

		public function add_header( string $key, string $value ): void {
			$this->headers[ str_replace( '-', '_', strtolower( $key ) ) ][] = $value;
		}

		public function get_header( string $key ): ?string {
			$key = str_replace( '-', '_', strtolower( $key ) );

			return isset( $this->headers[ $key ] ) ? implode( ',', $this->headers[ $key ] ) : null;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_body(): string {
			return $this->body;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_query_params(): array {
			return $this->query;
		}

		/**
		 * @param array<string, mixed> $params Parameters of the matched route pattern.
		 */
		public function set_url_params( array $params ): void {
			$this->urlParams = $params;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_url_params(): array {
			return $this->urlParams;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_body_params(): array {
			return $this->bodyParams;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_file_params(): array {
			return $this->fileParams;
		}

		/**
		 * Like core: a parameter of the query, the matched route, the form or the JSON body.
		 *
		 * @param string $key Parameter name.
		 */
		public function has_param( string $key ): bool {
			$json = $this->get_json_params();

			return array_key_exists( $key, $this->query ) || array_key_exists( $key, $this->urlParams ) || array_key_exists( $key, $this->bodyParams ) || ( is_array( $json ) && array_key_exists( $key, $json ) );
		}

		/**
		 * Like core: parsed only for a JSON content type.
		 *
		 * @return mixed
		 */
		public function get_json_params() {
			$type = strtolower( trim( explode( ';', (string) $this->get_header( 'Content-Type' ), 2 )[0] ) );

			return 'application/json' === $type ? json_decode( $this->body, true ) : null;
		}
	}
}
