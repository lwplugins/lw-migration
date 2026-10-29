<?php
/**
 * The warning when restored files keep the wrong owner.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Job\Job;

defined( 'ABSPATH' ) || exit;

/**
 * One warning per import (state.ownershipWarned), whichever step restores
 * the file (content, must-use plugins). Ownership itself stays WordPress-free.
 */
final class OwnershipNotice {

	/**
	 * @param Job $job Import job.
	 * @return void
	 */
	public static function once( Job $job ): void {
		if ( ! empty( $job->state['ownershipWarned'] ) ) {
			return;
		}
		$job->state['ownershipWarned'] = true;
		$job->addWarning( __( 'Some restored files could not be given to the owner of the site folder (the import runs as root). Check the file owners.', 'lw-migration' ) );
	}
}
