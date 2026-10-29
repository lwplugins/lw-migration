<?php
/**
 * Export failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

defined( 'ABSPATH' ) || exit;

/**
 * A backup step cannot continue; the message is shown to the user.
 */
class ExportException extends \RuntimeException {
}
