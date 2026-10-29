<?php
/**
 * Export step: checks that the backup can be written.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Fails early on a missing PHP extension or password.
 */
final class InitStep implements StepInterface {

	public function run( Job $job, Deadline $deadline ): StepResult {
		$options = ExportOptions::fromArray( $job->options );
		Codec::assertSupported( $options->compression );
		if ( $options->encrypt ) {
			Cipher::assertSupported();
			ArchiveCodec::password( $job );
		}
		if ( ! isset( $job->state['site'] ) || ! is_array( $job->state['site'] ) ) {
			throw new ExportException( __( 'The job has no site snapshot.', 'lw-migration' ) );
		}

		return StepResult::done( __( 'Preparing the backup...', 'lw-migration' ) );
	}
}
