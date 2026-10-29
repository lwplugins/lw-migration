<?php
/**
 * Wrong archive password.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Package;

use LightweightPlugins\Migration\Archive\ArchiveException;

defined( 'ABSPATH' ) || exit;

/**
 * The password does not decrypt the package.json signature.
 */
final class WrongPasswordException extends ArchiveException {
}
