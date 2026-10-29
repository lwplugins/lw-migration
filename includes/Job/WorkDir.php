<?php
/**
 * Work folder of a job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Job;

use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * The job folder, or a sub-folder when a step runs a nested job (the safety
 * backup inside an import uses $job->state['workSub'] = 'safety').
 */
final class WorkDir {

	/**
	 * @param JobStore $store Job store.
	 * @param Job      $job   Job.
	 * @return string Existing folder.
	 * @throws JobException On an invalid sub-folder or when it cannot be created.
	 */
	public static function path( JobStore $store, Job $job ): string {
		$dir = $store->dir( $job->id );
		$sub = (string) ( $job->state['workSub'] ?? '' );
		if ( '' !== $sub ) {
			if ( 1 !== preg_match( '/^[a-z0-9-]+\z/', $sub ) ) {
				throw new JobException( 'Invalid work folder name.' );
			}
			$dir .= '/' . $sub;
		}
		if ( ! Files::privateDir( $dir ) ) {
			throw new JobException( sprintf( 'Cannot create the work folder: %s', $dir ) );
		}

		return $dir;
	}
}
