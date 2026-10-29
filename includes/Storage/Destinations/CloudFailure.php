<?php
/**
 * A destination that did not do what it should.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.2. The message is for the user (translated by the thrower, no
 * secret, no signed address, the endpoint only in display form); $reason is
 * a code for programs; $retryAfter is the server's Retry-After in seconds.
 */
abstract class CloudFailure extends \RuntimeException {

	/**
	 * @param string                $message    Message for the user.
	 * @param string                $reason     One of the subclass constants.
	 * @param int                   $status     HTTP status (0 = none).
	 * @param int|null              $retryAfter Seconds the server asked to wait.
	 * @param array<string, string> $data       Facts for programs (JSON-safe, never a secret), e.g. a host key fingerprint the user must confirm.
	 */
	public function __construct( string $message, public string $reason, public int $status = 0, public ?int $retryAfter = null, public array $data = [] ) {
		parent::__construct( $message );
	}
}
