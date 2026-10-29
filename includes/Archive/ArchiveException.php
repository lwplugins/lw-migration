<?php
/**
 * Base exception of the archive engine.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Any archive read/write failure.
 */
class ArchiveException extends \RuntimeException {
}
