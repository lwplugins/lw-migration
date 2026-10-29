<?php
/**
 * Appends one file to the archive, resumably.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\FileCursor;
use LightweightPlugins\Migration\Archive\SourceChangedException;
use LightweightPlugins\Migration\Archive\SourceReadException;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * With no entry open, the archive is first cut back to the last complete
 * entry (a request may have died after writing a header). An open entry the
 * archive no longer holds (a request that died after finishing, skipping or
 * replacing it) is started again from that point; this counts as the one
 * restart. A source that changed between ticks is cut back to its header,
 * restarted once, then skipped. Archive write errors are not caught: they
 * fail the job.
 */
final class ArchiveAppender {

	public const DONE = 'done';

	public const MORE = 'more';

	public const SKIPPED = 'skipped';

	public function __construct( private Writer $writer ) {
	}

	/**
	 * @param array<string, mixed> $cursor        Step cursor; 'file' and 'restarted' are managed here.
	 * @param int                  $archiveLength Archive length after the last complete entry.
	 * @param string               $source        File to add.
	 * @param string               $entry         Entry name.
	 * @param Deadline             $deadline      Time budget.
	 * @return array{0: string, 1: int, 2: string|null} Status, archive length, problem code ('changed', 'unreadable').
	 */
	public function add( array &$cursor, int $archiveLength, string $source, string $entry, Deadline $deadline ): array {
		$file = FileCursor::fromArray( (array) ( $cursor['file'] ?? [] ) );
		if ( isset( $cursor['file'] ) && ! $this->writer->holdsOpenEntry( $file, $entry ) ) {
			unset( $cursor['file'] );
			$cursor['restarted'] = true;
			$file                = new FileCursor();
		}
		if ( ! isset( $cursor['file'] ) ) {
			$this->writer->truncateTo( $archiveLength );
		}
		try {
			$done = $this->writer->addFile( $source, $entry, $file, $deadline );
		} catch ( SourceChangedException $e ) {
			$this->writer->truncateTo( (int) $file->headerOffset );
			unset( $cursor['file'] );
			if ( empty( $cursor['restarted'] ) ) {
				$cursor['restarted'] = true;
				return [ self::MORE, $archiveLength, null ];
			}
			unset( $cursor['restarted'] );
			return [ self::SKIPPED, $archiveLength, 'changed' ];
		} catch ( SourceReadException $e ) {
			$this->writer->truncateTo( null === $file->headerOffset ? $archiveLength : $file->headerOffset );
			unset( $cursor['file'], $cursor['restarted'] );
			return [ self::SKIPPED, $archiveLength, 'unreadable' ];
		}
		if ( ! $done ) {
			$cursor['file'] = $file->toArray();
			return [ self::MORE, $archiveLength, null ];
		}
		unset( $cursor['file'], $cursor['restarted'] );

		return [ self::DONE, $this->writer->length(), null ];
	}
}
