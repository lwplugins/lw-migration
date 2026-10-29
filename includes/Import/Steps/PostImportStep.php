<?php
/**
 * Import step: runs the post-import fixes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\PostImport\Fixes;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * One fix per unit of work; the cursor is the index of the next fix. Without
 * an imported database only the cache and rewrite fixes run. A sites or
 * single-site restore into a network did not import the main site, so only
 * the caches are flushed here (ruling R12); its sites are finished by the
 * network_done step.
 */
final class PostImportStep implements StepInterface {

	public const KEY = 'post_import';

	public function __construct( private Fixes $fixes ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$tasks = ImportState::hasDatabase( $job ) ? Fixes::TASKS : Fixes::NO_DATABASE_TASKS;
		if ( ImportState::onNetwork( $job ) && ArchiveKind::NETWORK !== ImportState::kind( $job ) ) {
			$tasks = [ 'flushCaches' ];
		}
		$total = count( $tasks );
		$index = (int) ( $job->cursor['task'] ?? 0 );
		while ( $index < $total ) {
			$this->fixes->run( $tasks[ $index ], $job );
			$job->cursor['task'] = ++$index;
			if ( $index < $total && $deadline->reached() ) {
				return StepResult::more( (int) ( 100 * $index / $total ), __( 'Finishing the import...', 'lw-migration' ) );
			}
		}

		return StepResult::done( __( 'Post-import checks finished.', 'lw-migration' ) );
	}
}
