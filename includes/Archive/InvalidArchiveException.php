<?php
/**
 * Invalid archive exception.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * The file is not a complete .wpress archive.
 */
class InvalidArchiveException extends ArchiveException {
}
