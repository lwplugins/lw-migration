<?php
/**
 * Import step: verifies the archive checksum.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import\Steps;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Archives with the old (v1) end block carry no checksum; they pass here and
 * every entry's own CRC is still checked during extraction.
 */
final class CrcStep implements StepInterface {

	public function run( Job $job, Deadline $deadline ): StepResult {
		$cursor = CrcCursor::fromArray( $job->cursor );
		$reader = new Reader( ImportState::archive( $job ) );
		$done   = false;
		try {
			$done = $reader->verifyChecksum( $cursor, $deadline );
		} finally {
			$reader->close();
		}
		$job->cursor = $cursor->toArray();
		if ( $done ) {
			return StepResult::done( __( 'Archive checksum verified.', 'lw-migration' ) );
		}

		return StepResult::more( (int) ( 100 * $cursor->offset / max( 1, (int) $cursor->size ) ), __( 'Verifying the archive checksum...', 'lw-migration' ) );
	}
}
