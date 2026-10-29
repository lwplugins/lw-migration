<?php
/**
 * Export step: archives the listed files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export\Steps;

use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Export\ArchiveAppender;
use LightweightPlugins\Migration\Export\ArchiveCodec;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\FileEnumerator;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\StepInterface;
use LightweightPlugins\Migration\Job\StepResult;
use LightweightPlugins\Migration\Job\WorkDir;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor: byte offset of the next line in files.list, plus the open entry.
 * The archive length after each complete entry is kept in job state. Both go
 * into the job only after an append returned normally, so a failed append
 * resumes from the last saved position.
 */
final class ContentStep implements StepInterface {

	public function __construct( private JobStore $store ) {
	}

	public function run( Job $job, Deadline $deadline ): StepResult {
		$dir  = WorkDir::path( $this->store, $job );
		$path = $dir . '/' . ExportFiles::LIST;
		if ( ! is_file( $path ) ) {
			if ( ExportOptions::fromArray( $job->options )->noFiles ) {
				return StepResult::done();
			}
			throw new ExportException( __( 'The file list is missing. Start the backup again.', 'lw-migration' ) );
		}
		$list = fopen( $path, 'rb' );
		if ( false === $list ) {
			throw new ExportException( __( 'Cannot read the file list.', 'lw-migration' ) );
		}
		try {
			$writer = new Writer( $dir . '/' . ExportFiles::ARCHIVE, ArchiveCodec::forJob( $job ) );
			try {
				$finished = $this->append( $job, $list, new ArchiveAppender( $writer ), $deadline );
			} finally {
				$writer->close();
			}
		} finally {
			fclose( $list );
		}
		if ( $finished ) {
			return StepResult::done( __( 'Files archived.', 'lw-migration' ) );
		}

		// The archive's size moves on every tick, also within one huge file: a connected site driving this job
		// gives up on one that shows the same state for too long (RemoteJobStep).
		$written = (int) ( $job->state['archiveLength'] ?? 0 ) + (int) ( $job->cursor['file']['written'] ?? 0 );

		return StepResult::more(
			(int) ( 100 * (int) ( $job->cursor['list'] ?? 0 ) / max( 1, (int) filesize( $path ) ) ),
			/* translators: %s: size of the backup so far, e.g. "1.2 GB" */
			sprintf( __( 'Archiving files (%s written)...', 'lw-migration' ), size_format( $written, 1 ) )
		);
	}

	/**
	 * @param Job             $job      Job.
	 * @param resource        $list     files.list.
	 * @param ArchiveAppender $append   Appender.
	 * @param Deadline        $deadline Time budget.
	 * @return bool True when the whole list is archived.
	 */
	private function append( Job $job, $list, ArchiveAppender $append, Deadline $deadline ): bool {
		$cursor = $job->cursor;
		$length = (int) ( $job->state['archiveLength'] ?? 0 );
		while ( true ) {
			$offset = (int) ( $cursor['list'] ?? 0 );
			$line   = self::lineAt( $list, $offset );
			if ( null === $line ) {
				return true;
			}
			$item = FileEnumerator::parseLine( $line );
			if ( null === $item ) {
				$job->addWarning( __( 'Skipped a damaged line in the file list.', 'lw-migration' ) );
				$status = ArchiveAppender::SKIPPED;
			} else {
				[ $status, $length, $problem ] = $append->add( $cursor, $length, $item['path'], $item['entry'], $deadline );
				if ( null !== $problem ) {
					$job->addWarning( self::warning( $problem, $item['entry'] ) );
				}
			}
			if ( ArchiveAppender::MORE !== $status ) {
				$cursor['list'] = $offset + strlen( $line );
			}
			$job->cursor                 = $cursor;
			$job->state['archiveLength'] = $length;
			if ( $deadline->reached() ) {
				return false;
			}
		}
	}

	/**
	 * @param resource $list   files.list.
	 * @param int      $offset Byte offset.
	 * @return string|null The line, or null at the end.
	 * @throws ExportException When the list cannot be read.
	 */
	private static function lineAt( $list, int $offset ): ?string {
		if ( 0 !== fseek( $list, $offset ) ) {
			throw new ExportException( __( 'Cannot read the file list.', 'lw-migration' ) );
		}
		$line = fgets( $list );

		return false === $line || '' === $line ? null : $line;
	}

	private static function warning( string $problem, string $entry ): string {
		if ( 'changed' === $problem ) {
			/* translators: %s: path inside the backup */
			return sprintf( __( 'Skipped a file that kept changing while it was backed up: %s', 'lw-migration' ), $entry );
		}

		/* translators: %s: path inside the backup */
		return sprintf( __( 'Skipped a file that could not be read (it may have been deleted during the backup): %s', 'lw-migration' ), $entry );
	}
}
