<?php
/**
 * A reset that may not start.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown before a reset job exists: the reason is a code the client and the
 * command line act on, the message is the translated text for the person
 * (ResetTexts::refusal()). Never carries the typed password or a path.
 */
final class ResetRefused extends \RuntimeException {

	public const NETWORK = 'network';

	public const DATABASE_SETUP = 'database_setup';

	public const FILE_MODS = 'file_mods';

	public const NOT_WRITABLE = 'not_writable';

	public const UPLOADS_UNSAFE = 'uploads_unsafe';

	public const AS_ROOT = 'as_root';

	public const PASSWORD = 'password';

	public const LOCKED = 'locked';

	public const UNRECORDED = 'unrecorded';

	public const NO_USER = 'no_user';

	public const CONFIRM = 'confirm';

	public const NO_ADDRESS = 'no_address';

	/**
	 * @param string $reason     One of the constants.
	 * @param string $message    Translated text.
	 * @param int    $retryAfter Seconds until a locked user may try again (LOCKED only).
	 */
	public function __construct( public string $reason, string $message, public int $retryAfter = 0 ) {
		parent::__construct( $message );
	}
}
