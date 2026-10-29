<?php
/**
 * Export step: removes the job's work files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the file list (and its side file), the dump and any archive left in
 * the work folder; an emptied sub-folder (nested job) goes too. job.json and
 * job.log stay for the status screen until the daily sweep.
 */
final class CleanStep implements StepInterface {

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$dir = WorkDir::path( $this->store, $job );
		ExportFiles::remove( $dir );
		if ( '' !== (string) ( $job->state['workSub'] ?? '' ) ) {
			$items = scandir( $dir );
			if ( is_array( $items ) && [ '.', '..' ] === $items ) {
				rmdir( $dir );
			}
		}

		return StepResult::done( __( 'Backup complete.', 'lw-migration' ) );
	}
}
