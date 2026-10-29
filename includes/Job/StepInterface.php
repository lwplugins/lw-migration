<?php
/**
 * One resumable unit of a job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Must keep its progress in $job->cursor and return before the deadline.
 */
interface StepInterface {

	public function run( Job $job, Deadline $deadline ): StepResult;
}
