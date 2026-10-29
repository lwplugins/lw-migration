<?php
/**
 * Import failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

defined( 'ABSPATH' ) || exit;

/**
 * An import step cannot continue; the message is shown to the user.
 */
class ImportException extends \RuntimeException {
}
