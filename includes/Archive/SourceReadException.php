<?php
/**
 * A file to archive cannot be read.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * The caller may skip the file; the archive itself is still sound.
 */
class SourceReadException extends ArchiveException {
}
