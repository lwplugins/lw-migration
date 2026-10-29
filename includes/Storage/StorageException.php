<?php
/**
 * Storage folder failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A folder or protection file cannot be created.
 */
class StorageException extends \RuntimeException {
}
