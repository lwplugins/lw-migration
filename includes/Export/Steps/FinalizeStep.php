<?php
/**
 * Export step: writes the end-of-archive block with the archive CRC.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Archive\ArchiveDigest;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * The CRC pass is resumable; before it starts, stray bytes after the last
 * complete entry are cut off. The cursor goes into the job only after the
 * writer returned. The export of a pull (ExportPipeline::LINK_STEPS) then
 * hashes the finished archive (SHA-256, resumable, cursor.sha256) into
 * state.archiveSha256, which the signed job view hands to the pulling site
 * (issue #1).
 */
final class FinalizeStep implements StepInterface {

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$path = WorkDir::path( $this->store, $job ) . '/' . ExportFiles::ARCHIVE;
		if ( ! is_file( $path ) ) {
			throw new ExportException( __( 'The backup file being written is missing. Start the backup again.', 'lw-migration' ) );
		}
		if ( ! isset( $job->cursor['sha256'] ) ) {
			$cursor = CrcCursor::fromArray( $job->cursor );
			$writer = new Writer( $path );
			try {
				if ( null === $cursor->size ) {
					$writer->truncateTo( (int) ( $job->state['archiveLength'] ?? 0 ) );
				}
				$done = $writer->finalize( $cursor, $deadline );
			} finally {
				$writer->close();
			}
			$job->cursor = $cursor->toArray();
			if ( ! $done ) {
				return StepResult::more( (int) ( 100 * $cursor->offset / max( 1, (int) $cursor->size ) ), __( 'Computing the backup checksum...', 'lw-migration' ) );
			}
			if ( ExportPipeline::LINK_STEPS !== $job->steps ) {
				return StepResult::done( __( 'Backup checksum written.', 'lw-migration' ) );
			}
			$job->cursor = [ 'sha256' => [] ]; // The EOF is written: from here only the SHA-256 goes on.
		}

		return $this->digest( $job, $path, $deadline );
	}

	/**
	 * The SHA-256 of the finished archive of a pull (issue #1), which the
	 * other site checks its download against; resumable within the deadline.
	 *
	 * @param Job      $job      Job (cursor.sha256, state.archiveSha256).
	 * @param string   $path     The finished archive.
	 * @param Deadline $deadline Time budget.
	 * @throws ExportException When the archive cannot be read.
	 */
	private function digest( Job $job, string $path, Deadline $deadline ): StepResult {
		try {
			$progress = ArchiveDigest::advance( $path, (array) $job->cursor['sha256'], $deadline );
		} catch ( \RuntimeException $e ) {
			throw new ExportException( __( 'The backup file being written is missing. Start the backup again.', 'lw-migration' ) );
		}
		if ( null === $progress['digest'] ) {
			$job->cursor = [
				'sha256' => [
					'offset'  => $progress['offset'],
					'context' => $progress['context'],
				],
			];

			return StepResult::more( (int) ( 100 * $progress['offset'] / max( 1, (int) filesize( $path ) ) ), __( 'Computing the archive checksum for the other site...', 'lw-migration' ) );
		}
		$job->state['archiveSha256'] = $progress['digest'];

		return StepResult::done( __( 'Backup checksum written.', 'lw-migration' ) );
	}
}
