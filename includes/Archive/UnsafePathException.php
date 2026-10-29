<?php
/**
 * Unsafe path exception.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * An entry name would write outside the target directory.
 */
class UnsafePathException extends ArchiveException {
}
