<?php
/**
 * A schedule that cannot be saved or cannot run.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Schedules;

defined( 'ABSPATH' ) || exit;

/**
 * Its message is shown to people (log entry, mail, REST, CLI): the thrower
 * translates it and never puts a path or a secret into it.
 */
final class ScheduleException extends \RuntimeException {
}
