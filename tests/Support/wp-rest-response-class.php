<?php
/**
 * Minimal global WP_REST_Response for tests that call a route callback (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Data, a status and headers, like core's response.
	 */
	final class WP_REST_Response {

		/** @var array<string, string> */
		private array $headers = [];

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		/**
		 * Like core's WP_HTTP_Response::header().
		 *
		 * @param string $key     Header name.
		 * @param string $value   Header value.
		 * @param bool   $replace Whether to replace an existing value.
		 */
		public function header( $key, $value, $replace = true ): void {
			$this->headers[ $key ] = $replace || ! isset( $this->headers[ $key ] ) ? $value : $this->headers[ $key ] . ', ' . $value;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}
