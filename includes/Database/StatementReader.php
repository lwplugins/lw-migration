<?php
/**
 * Splits database.sql into statements, from any byte offset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A statement ends at a line ending in ";" (whitespace ignored) — the dump
 * format guarantees one statement per line for data, so this is exact.
 * Reads bounded chunks; StatementScan classifies each line piece by piece,
 * so memory stays bounded even for a single line of hundreds of megabytes.
 *
 * Each call starts with a small read and doubles it (up to the cap) only
 * while its statement is unfinished, and the last chunk read is kept: when
 * the next call asks for an offset inside it, no bytes are read again. The
 * result depends only on the file and the offset, so a resume from any
 * stored offset (a later web tick, a fresh reader) is unchanged.
 */
final class StatementReader {

	private const MAX_CHUNK = 8388608;

	private const FIRST_CHUNK = 65536;

	/**
	 * File handle for reading the SQL file.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * File size in bytes.
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Slack bytes for oversized detection; configurable for testing.
	 *
	 * @var int
	 */
	private int $slack;

	/**
	 * Size of the first read of each call; configurable for testing.
	 *
	 * @var int
	 */
	private int $firstChunk;

	/**
	 * The last chunk read; the handle stands just after it.
	 *
	 * @var string
	 */
	private string $window = '';

	/**
	 * File offset of the window's first byte.
	 *
	 * @var int
	 */
	private int $windowStart = 0;

	/**
	 * Initialize the reader.
	 *
	 * @param string $path       Path to SQL file.
	 * @param int    $slack      Slack bytes for oversized check (default 65536).
	 * @param int    $firstChunk Bytes of the first read of each call.
	 *
	 * @throws DatabaseException When the file cannot be opened.
	 */
	public function __construct( string $path, int $slack = 65536, int $firstChunk = self::FIRST_CHUNK ) {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new DatabaseException( sprintf( 'Cannot open SQL file: %s', $path ) );
		}
		$this->handle     = $handle;
		$this->size       = (int) ( fstat( $handle )['size'] ?? 0 );
		$this->slack      = $slack;
		$this->firstChunk = max( 1, $firstChunk );
	}

	public function size(): int {
		return $this->size;
	}

	public function next( int $offset, int $maxBytes ): ?Statement {
		$scan     = new StatementScan( $offset, $maxBytes, $maxBytes + $this->slack );
		$cap      = max( 1, min( $maxBytes + $this->slack, self::MAX_CHUNK ) + 1 );
		$size     = min( $this->firstChunk, $cap );
		$position = $offset;
		$from     = $this->seek( $offset );

		while ( true ) {
			if ( $from >= strlen( $this->window ) ) {
				if ( ! $this->read( $size ) ) {
					break;
				}
				$from = 0;
				$size = min( 2 * $size, $cap );
			}
			if ( $this->scanWindow( $scan, $from, $position ) ) {
				return $scan->statement( $position );
			}
		}

		// End of file closes a last line that has no newline.
		if ( $scan->endLine() ) {
			return $scan->statement( $position );
		}

		// A statement the file cut off before its ";" is returned flagged, never dropped silently.
		return $scan->pending() ? $scan->statement( $position, true ) : null;
	}

	public function close(): void {
		fclose( $this->handle );
	}

	/**
	 * Position at $offset: inside the window, or seek and drop the window.
	 *
	 * @param int $offset File offset the statement scan begins at.
	 * @return int Index into the window where reading continues.
	 *
	 * @throws DatabaseException When the file cannot be seeked.
	 */
	private function seek( int $offset ): int {
		$end = $this->windowStart + strlen( $this->window );
		if ( $offset >= $this->windowStart && $offset < $end ) {
			return $offset - $this->windowStart;
		}
		if ( $offset !== $end && 0 !== fseek( $this->handle, $offset ) ) {
			throw new DatabaseException( 'Cannot seek in the SQL file.' );
		}
		$this->window      = '';
		$this->windowStart = $offset;

		return 0;
	}

	/**
	 * Replace the window with the next chunk of the file.
	 *
	 * @param int $size Bytes to read at most.
	 * @return bool False at the end of the file.
	 */
	private function read( int $size ): bool {
		$this->windowStart += strlen( $this->window );
		$chunk              = fread( $this->handle, $size );
		$this->window       = false === $chunk ? '' : $chunk;

		return '' !== $this->window;
	}

	/**
	 * Feed the window from $from up to the end of the statement or the window.
	 *
	 * @param StatementScan $scan     The statement being read.
	 * @param int           $from     Index into the window; moved past the bytes fed.
	 * @param int           $position File offset of $from; moved along with it.
	 * @return bool Whether a line ending completed the statement.
	 */
	private function scanWindow( StatementScan $scan, int &$from, int &$position ): bool {
		$length = strlen( $this->window );
		while ( $from < $length ) {
			$newline = strpos( $this->window, "\n", $from );
			$to      = false === $newline ? $length : $newline + 1;
			$scan->feed( substr( $this->window, $from, $to - $from ) );
			$position += $to - $from;
			$from      = $to;
			if ( false !== $newline && $scan->endLine() ) {
				return true;
			}
		}

		return false;
	}
}
