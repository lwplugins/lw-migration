<?php
/**
 * A backups folder that may not be used.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a reason code; Storage\FolderMessages turns it into the user's text
 * (this class is used by WordPress-free code and never translates).
 */
final class FolderRefusedException extends \InvalidArgumentException {

	public const INVALID           = 'invalid';
	public const NOT_ABSOLUTE      = 'not_absolute';
	public const SYSTEM            = 'system';
	public const SITE_FOLDER       = 'site_folder';
	public const CONTENT_SUBFOLDER = 'content_subfolder';
	public const OVERLAP           = 'overlap';
	public const OPEN_BASEDIR      = 'open_basedir';
	public const NO_PARENT         = 'no_parent';
	public const NOT_WRITABLE      = 'not_writable';
	public const WORLD_WRITABLE    = 'world_writable';
	public const LINK              = 'link';
	public const NOT_FOLDER        = 'not_folder';
	public const NOT_EMPTY         = 'not_empty';
	public const FOREIGN           = 'foreign';
	public const MISSING           = 'missing';
	public const CHANGED           = 'changed';
	public const SAME              = 'same';
	public const PENDING           = 'pending';
	public const UNSUPPORTED       = 'unsupported';
	public const LOCKED            = 'locked';
	public const UNPROTECTED       = 'unprotected';
	public const RUN_AS_OWNER      = 'run_as_owner';
	public const NOTHING_PENDING   = 'nothing_pending';

	/**
	 * @param string $reason One of the constants.
	 */
	public function __construct( public string $reason ) {
		parent::__construct( 'Backups folder refused: ' . $reason );
	}
}
