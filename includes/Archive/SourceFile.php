<?php
/**
 * A file being read into the archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Opens the source, notices when it changed between resumes, and reads it in
 * full chunks even from streams that return short reads.
 */
final class SourceFile {

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * Source path, for error messages.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * @param resource $handle Open source stream.
	 * @param string   $path   For error messages.
	 */
	private function __construct( $handle, string $path ) {
		$this->handle = $handle;
		$this->path   = $path;
	}

	/**
	 * @param string $path File (any readable stream URL).
	 * @return self
	 * @throws SourceReadException When the file cannot be opened.
	 */
	public static function open( string $path ): self {
		if ( ! is_readable( $path ) ) {
			throw new SourceReadException( sprintf( 'Cannot read file: %s', $path ) );
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new SourceReadException( sprintf( 'Cannot read file: %s', $path ) );
		}

		return new self( $handle, $path );
	}

	/**
	 * Records size and mtime on the first open; on resume, refuses a changed file.
	 *
	 * @param FileCursor $cursor Progress.
	 * @return void
	 * @throws SourceChangedException When the file changed since the first open.
	 */
	public function check( FileCursor $cursor ): void {
		$stat  = fstat( $this->handle );
		$size  = false === $stat ? null : (int) $stat['size'];
		$mtime = false === $stat ? null : (int) $stat['mtime'];
		if ( null === $cursor->headerOffset ) {
			$cursor->sourceSize  = $size;
			$cursor->sourceMtime = $mtime;
			return;
		}
		if ( null !== $cursor->sourceSize && ( $size !== $cursor->sourceSize || $mtime !== $cursor->sourceMtime ) ) {
			throw new SourceChangedException( sprintf( 'The file changed while it was being archived: %s', $this->path ) );
		}
	}

	/**
	 * @param int $offset Bytes from the start.
	 * @return void
	 * @throws SourceReadException When seeking fails.
	 */
	public function seek( int $offset ): void {
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			throw new SourceReadException( sprintf( 'Cannot seek in file: %s', $this->path ) );
		}
	}

	/**
	 * Fills one chunk: stream wrappers (and some filesystems) return short reads,
	 * and an encrypted, uncompressed archive needs full chunks to be readable.
	 *
	 * @return string Up to CHUNK_SIZE bytes; '' at the end.
	 * @throws SourceReadException When reading fails or stalls before the end.
	 */
	public function readChunk(): string {
		$buffer = '';
		$length = 0;
		while ( $length < Format::CHUNK_SIZE && ! feof( $this->handle ) ) {
			$part = fread( $this->handle, Format::CHUNK_SIZE - $length );
			if ( false === $part ) {
				throw new SourceReadException( sprintf( 'Cannot read file: %s', $this->path ) );
			}
			if ( '' === $part ) {
				if ( ! feof( $this->handle ) ) {
					throw new SourceReadException( sprintf( 'Reading stalled before the end of the file: %s', $this->path ) );
				}
				break;
			}
			$buffer .= $part;
			$length += strlen( $part );
		}

		return $buffer;
	}

	public function atEnd(): bool {
		return feof( $this->handle );
	}

	public function close(): void {
		fclose( $this->handle );
	}
}
