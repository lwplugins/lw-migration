<?php
/**
 * Another request holds the job right now.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Runner::update (input, fail, cancel) when the job's lock is taken:
 * a temporary state, so a REST caller may answer "busy, try again".
 */
final class JobBusyException extends JobException {
}
