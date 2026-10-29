<?php
/**
 * Minimal global WP_Error for tests that build one (load only in a separate process).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * One code, one message and its data, like a WP_Error with a single code.
	 */
	final class WP_Error {

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( private string $code = '', private string $message = '', private $data = '' ) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}
