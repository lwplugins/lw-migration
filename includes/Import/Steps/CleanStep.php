<?php
/**
 * Import step: removes work files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportCleanup;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * A local archive or an existing backup is never deleted; a download is.
 * Returns done() without a message, so the Done step's message stays the
 * job's last one.
 */
final class CleanStep implements StepInterface {

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ! ImportCleanup::workFiles( WorkDir::path( $this->store, $job ), ImportCleanup::ownsDownload( $job ) ) ) {
			$job->addWarning( ImportCleanup::workFilesKeptNotice() );
		}
		ImportState::forgetAddress( $job );

		return StepResult::done();
	}
}
