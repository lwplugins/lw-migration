<?php
/**
 * Import step: waits until the browser uploaded the archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Import\UploadReceiver;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The chunks arrive through the REST upload route (UploadReceiver), not through
 * this step: until upload.wpress has the size declared at job start, the job
 * waits for input of kind "upload" with what arrived so far. The upload belongs
 * to the job (state.archiveOwned), so cleanup deletes it. A full part whose
 * rename failed is finished here; when that fails again the job fails instead
 * of waiting for bytes that already arrived.
 */
final class UploadStep implements StepInterface {

	public const KEY = 'upload';

	public function __construct( private JobStore $store ) {
	}

	/**
	 * The upload in the job folder: where the REST upload route appends and
	 * where this step takes the file from.
	 *
	 * @param JobStore $store Job store.
	 * @param string   $jobId Job id.
	 * @throws \LightweightPlugins\Migration\Job\JobException On an invalid job id.
	 */
	public static function target( JobStore $store, string $jobId ): string {
		return $store->dir( $jobId ) . '/' . ImportFiles::UPLOAD;
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$size = (int) ( $job->state['upload']['size'] ?? 0 );
		if ( $size <= 0 ) {
			throw new ImportException( __( 'The upload was started without its size.', 'lw-migration' ) );
		}
		$target = self::target( $this->store, $job->id );
		if ( UploadReceiver::finish( $target, $size ) ) {
			$job->state['archive']      = $target;
			$job->state['archiveOwned'] = true;

			return StepResult::done( __( 'Archive uploaded.', 'lw-migration' ) );
		}
		$received = UploadReceiver::received( $target );
		if ( $received >= $size ) {
			throw new ImportException( __( 'The uploaded file arrived but could not be saved in the job folder. Start the restore again.', 'lw-migration' ) );
		}

		return StepResult::needsInput(
			self::KEY,
			[
				'received' => $received,
				'size'     => $size,
			]
		);
	}
}
