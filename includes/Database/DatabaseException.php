<?php
/**
 * Database layer failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A database operation cannot continue.
 */
class DatabaseException extends \RuntimeException {
}
