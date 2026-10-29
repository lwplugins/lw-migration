<?php
/**
 * Stand-in for WP_Error in Brain Monkey tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

/**
 * Carries one error code and message, like a WP_Error with a single code.
 * The default code is the one WordPress gives every transport failure.
 */
final class FakeWpError {

	public function __construct( private string $message, private string $code = 'http_request_failed' ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}
