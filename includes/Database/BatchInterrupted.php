<?php
/**
 * Control-flow signal: stop streaming the current batch early.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown from the row callback by Dumper and caught by Dumper only.
 */
final class BatchInterrupted extends \RuntimeException {
}
