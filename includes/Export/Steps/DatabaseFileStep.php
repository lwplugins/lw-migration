<?php
/**
 * Export step: adds database.sql as the last entry.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ArchiveAppender;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Same resumable append as the content files; a missing or unreadable dump is
 * an error. The cursor goes into the job only after the append returned.
 */
final class DatabaseFileStep implements StepInterface {

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		if ( ExportOptions::fromArray( $job->options )->noDatabase ) {
			return StepResult::done();
		}
		$dir = WorkDir::path( $this->store, $job );
		$sql = $dir . '/' . ExportFiles::DATABASE;
		if ( ! is_file( $sql ) ) {
			throw new ExportException( __( 'The database dump is missing.', 'lw-migration' ) );
		}
		$cursor = $job->cursor;
		$writer = new Writer( $dir . '/' . ExportFiles::ARCHIVE, ArchiveCodec::forJob( $job ) );
		try {
			[ $status, $length, $problem ] = ( new ArchiveAppender( $writer ) )->add( $cursor, (int) ( $job->state['archiveLength'] ?? 0 ), $sql, Format::DATABASE, $deadline );
		} finally {
			$writer->close();
		}
		if ( ArchiveAppender::SKIPPED === $status ) {
			throw new ExportException(
				'changed' === $problem
					? __( 'The database dump kept changing while it was added to the backup.', 'lw-migration' )
					: __( 'The database dump cannot be read.', 'lw-migration' )
			);
		}
		$job->cursor                 = $cursor;
		$job->state['archiveLength'] = $length;

		if ( ArchiveAppender::DONE === $status ) {
			return StepResult::done( __( 'Database added to the backup.', 'lw-migration' ) );
		}
		$size = max( 1, (int) filesize( $sql ) );
		$done = min( $size, (int) ( $cursor['file']['sourceOffset'] ?? 0 ) );

		return StepResult::more(
			(int) ( 100 * $done / $size ),
			/* translators: 1: bytes of the dump added so far, 2: size of the dump, e.g. "1.2 GB" */
			sprintf( __( 'Adding the database to the backup: %1$s of %2$s...', 'lw-migration' ), size_format( $done, 1 ), size_format( $size, 1 ) )
		);
	}
}
