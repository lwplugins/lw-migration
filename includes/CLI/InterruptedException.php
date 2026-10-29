<?php
/**
 * The user stopped a command.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\CLI;

use LightweightPlugins\Migration\Job\JobException;

defined( 'ABSPATH' ) || exit;

/**
 * Ctrl-C (or SIGTERM/SIGHUP) between ticks: the job is cancelled, not failed.
 */
class InterruptedException extends JobException {
}
