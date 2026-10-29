<?php
/**
 * A cloud step that cannot go on.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Cloud;

defined( 'ABSPATH' ) || exit;

/**
 * Fails the whole job (the local backup to upload is gone, the backups
 * folder cannot take a download). A failure of one destination is never
 * this: it is recorded in CloudOutcome and the next destination goes on.
 */
final class CloudJobException extends \RuntimeException {
}
