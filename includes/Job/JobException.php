<?php
/**
 * Job store / runner failure.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Invalid job id, unreadable job file, unknown step.
 */
class JobException extends \RuntimeException {
}
