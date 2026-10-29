<?php
/**
 * One scan pass over the folder of a plugin or a theme being deleted.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Reset\Steps;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Reset\ResetTexts;
use LightweightPlugins\Migration\Reset\TreeEraser;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The plugins and themes steps erase each folder in scan passes, as the media
 * step does with uploads: a pass resumes after the position the previous one
 * reached and ends at the deadline after any entry, so a folder full of files
 * that cannot be removed still moves the cursor on (the attempt guard sees
 * progress) instead of stalling the job. What one pass counts (files that
 * could not be removed, folders it left) adds up in cursor.pass until the
 * folder is done; the step drops cursor.pass when it moves to the next one.
 */
final class ScanPass {

	/** Where the passes over the current folder keep their position and counts. */
	public const CURSOR = 'pass';

	/**
	 * @param Job        $job      Reset job.
	 * @param string     $name     Plugin basename or theme folder name (for the warnings).
	 * @param string     $path     The folder (or file) to erase.
	 * @param TreeEraser $eraser   Eraser of the site.
	 * @param Deadline   $deadline Time budget.
	 * @return int|null Files that could not be removed once the folder is done; null when the deadline cut the pass.
	 */
	public static function run( Job $job, string $name, string $path, TreeEraser $eraser, Deadline $deadline ): ?int {
		$pass   = (array) ( $job->cursor[ self::CURSOR ] ?? [] );
		$erased = $eraser->erase( $path, true, $deadline, (string) ( $pass['position'] ?? '' ) );
		$failed = $erased['failed'] + (int) ( $pass['failed'] ?? 0 );
		$left   = $eraser->skippedFor();
		foreach ( $left as $why => $count ) {
			$left[ $why ] = $count + (int) ( $pass['left'][ $why ] ?? 0 );
		}
		if ( ! $erased['done'] ) {
			$job->cursor[ self::CURSOR ] = [
				'position' => $eraser->position(), // Progress the attempt guard sees, also for a pass that removed nothing.
				'removed'  => (int) ( $pass['removed'] ?? 0 ) + $erased['removed'],
				'failed'   => $failed,
				'left'     => $left,
			];

			return null;
		}
		foreach ( ResetTexts::leftFolders( $name, $left ) as $warning ) {
			$job->addWarning( $warning );
		}

		return $failed;
	}
}
