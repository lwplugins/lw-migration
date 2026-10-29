<?php
/**
 * The last step of a push.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Link\Steps;

use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The other site restored the archive: its copy here (and the export's work
 * files) go; no backup was stored on this site.
 */
final class PushCleanStep implements StepInterface {

	public const KEY = 'link_clean';

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		ExportFiles::remove( WorkDir::path( $this->store, $job ) );

		/* translators: %s: the other site's name */
		return StepResult::done( sprintf( __( '%s now shows the content of this site.', 'lw-migration' ), RemoteJobStep::peer( $job ) ) );
	}
}
