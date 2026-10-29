<?php
/**
 * A temporary-table run could not be reserved.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a reason code only; each caller words its own message.
 */
final class TmpRunException extends \RuntimeException {

	/** Every try hit a prefix in use. */
	public const NO_PREFIX = 'no_prefix';

	/** The run could not be recorded as this site's. */
	public const UNREGISTERED = 'unregistered';

	/**
	 * NO_PREFIX or UNREGISTERED.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * @param string $reason NO_PREFIX or UNREGISTERED.
	 */
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( 'Temporary tables could not be reserved: ' . $reason . '.' );
	}
}
