<?php
/**
 * Minimal global WP_HTTP_Response for tests of response filters (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	/**
	 * Data, a status and headers, like core's response (header() replaces a value).
	 */
	class WP_HTTP_Response {

		/** @var array<string, string> */
		private array $headers = [];

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		public function header( string $key, string $value, bool $replace = true ): void {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;
			} else {
				$this->headers[ $key ] .= ', ' . $value;
			}
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
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
	}
}
