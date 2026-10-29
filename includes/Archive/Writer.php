<?php
/**
 * Appends entries to a .wpress archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Every write goes to the end of the file, except header patches. A resumed
 * addFile() first truncates anything written after the saved cursor.
 */
final class Writer {

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * Codec for regular entries.
	 *
	 * @var ChunkCodec
	 */
	private ChunkCodec $codec;

	/**
	 * Codec for config entries, always uncompressed.
	 *
	 * @var ChunkCodec
	 */
	private ChunkCodec $plain;

	public function __construct( string $archivePath, ?ChunkCodec $codec = null ) {
		$handle = fopen( $archivePath, 'c+b' );
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open archive for writing: %s', $archivePath ) );
		}
		$this->handle = $handle;
		$this->codec  = $codec ?? ChunkCodec::plain();
		$this->plain  = ChunkCodec::plain();
	}

	public function addString( string $entryName, string $contents, int $mtime ): void {
		$codec  = Format::isConfigEntry( $entryName ) ? $this->plain : $this->codec;
		$stored = '';
		$length = strlen( $contents );
		for ( $offset = 0; $offset < $length; $offset += Format::CHUNK_SIZE ) {
			$stored .= $codec->encode( substr( $contents, $offset, Format::CHUNK_SIZE ) );
		}

		$this->seekEnd();
		$this->write( Header::pack( $entryName, strlen( $stored ), $mtime, Crc32::ofString( $contents ) ) . $stored );
	}

	/**
	 * Appends one file, resumable across requests through $cursor.
	 *
	 * @param string     $sourcePath File to archive (any readable stream URL).
	 * @param string     $entryName  Name inside the archive.
	 * @param FileCursor $cursor     Progress; a fresh cursor starts a new entry.
	 * @param Deadline   $deadline   Stops between chunks when reached.
	 * @return bool True when the entry is complete, false when the deadline stopped it.
	 * @throws SourceReadException When the source cannot be read or changed (SourceChangedException);
	 *                             archive write errors surface as ArchiveException.
	 */
	public function addFile( string $sourcePath, string $entryName, FileCursor $cursor, Deadline $deadline ): bool {
		$source = SourceFile::open( $sourcePath );
		try {
			$source->check( $cursor );
			$this->openEntry( $sourcePath, $entryName, $cursor );
			$source->seek( $cursor->sourceOffset );
			$codec = Format::isConfigEntry( $entryName ) ? $this->plain : $this->codec;
			$crc   = new IncrementalCrc( $cursor->crc );
			while ( true ) {
				$raw = $source->readChunk();
				if ( '' === $raw ) {
					break;
				}
				$crc->update( $raw );
				$stored = $codec->encode( $raw );
				$this->write( $stored );
				$cursor->sourceOffset += strlen( $raw );
				$cursor->written      += strlen( $stored );
				if ( ! $source->atEnd() && $deadline->reached() ) {
					$cursor->crc = $crc->value();
					return false;
				}
			}
			$cursor->crc = $crc->value();
			$this->patchHeader( (int) $cursor->headerOffset, $cursor->written, $cursor->crc );
			return true;
		} finally {
			$source->close();
		}
	}

	public function finalize( CrcCursor $cursor, Deadline $deadline ): bool {
		if ( null === $cursor->size ) {
			if ( $this->adoptExistingEof( $cursor ) ) {
				return true;
			}
			$cursor->size = $this->length();
		}
		if ( ! ArchiveChecksum::advance( $this->handle, $cursor, $deadline ) ) {
			return false;
		}

		// A previous request may have died after (partly) writing the EOF: drop anything past the hashed bytes.
		if ( ! ftruncate( $this->handle, $cursor->size ) ) {
			throw new ArchiveException( 'Cannot truncate the archive to resume writing.' );
		}
		$this->seek( $cursor->size );
		$this->write( Eof::v2( $cursor->size, $cursor->crc ) );
		fflush( $this->handle );

		return true;
	}

	/**
	 * Current archive length in bytes.
	 *
	 * @return int
	 * @throws ArchiveException When the size cannot be read.
	 */
	public function length(): int {
		fflush( $this->handle );
		$stat = fstat( $this->handle );
		if ( false === $stat ) {
			throw new ArchiveException( 'Cannot read the archive size.' );
		}

		return (int) $stat['size'];
	}

	/**
	 * Drops everything after $length bytes, e.g. a half-written entry left by a
	 * request that died before saving its cursor.
	 *
	 * @param int $length A length previously returned by length().
	 * @return void
	 * @throws ArchiveException When the length is out of range or the truncation fails.
	 */
	public function truncateTo( int $length ): void {
		$current = $this->length();
		if ( $length < 0 || $length > $current ) {
			throw new ArchiveException( sprintf( 'Cannot truncate the archive to %d bytes: it is %d bytes long.', $length, $current ) );
		}
		if ( ! ftruncate( $this->handle, $length ) ) {
			throw new ArchiveException( 'Cannot truncate the archive.' );
		}
		$this->seekEnd();
	}

	/**
	 * Whether the entry a saved cursor points at is still the open entry: a
	 * request that died after the cursor was saved may have finished, skipped
	 * or replaced it. Holds when the header at the saved offset names the same
	 * entry, its size is still the open-entry placeholder (0) and the archive
	 * is at least as long as the saved progress.
	 *
	 * @param FileCursor $cursor    Saved progress.
	 * @param string     $entryName Name inside the archive.
	 * @return bool
	 * @throws ArchiveException When the archive cannot be read.
	 */
	public function holdsOpenEntry( FileCursor $cursor, string $entryName ): bool {
		if ( null === $cursor->headerOffset || $this->length() < $cursor->headerOffset + Format::HEADER_SIZE + $cursor->written ) {
			return false;
		}
		$this->seek( $cursor->headerOffset );
		$block = fread( $this->handle, Format::HEADER_SIZE );
		$this->seekEnd();
		try {
			$entry = Header::unpack( false === $block ? '' : $block, $cursor->headerOffset );
		} catch ( InvalidArchiveException $e ) {
			return false;
		}

		return str_replace( '\\', '/', $entryName ) === $entry->name && 0 === $entry->size;
	}

	public function close(): void {
		fclose( $this->handle );
	}

	/**
	 * A retried finalize() whose cursor was never saved: if the archive already
	 * ends with a v2 end-of-archive block that matches its length, adopt it.
	 *
	 * @param CrcCursor $cursor Progress, filled in when an EOF is adopted.
	 * @return bool True when the archive is already finalized.
	 */
	private function adoptExistingEof( CrcCursor $cursor ): bool {
		$length = $this->length();
		if ( $length < Format::HEADER_SIZE ) {
			return false;
		}
		$this->seek( $length - Format::HEADER_SIZE );
		$block = fread( $this->handle, Format::HEADER_SIZE );
		$info  = false === $block ? null : Eof::parse( $block );
		if ( null === $info || 2 !== $info->version || $info->size !== $length - Format::HEADER_SIZE ) {
			return false;
		}
		$cursor->size   = $info->size;
		$cursor->offset = $info->size;
		$cursor->crc    = (string) $info->crc;

		return true;
	}

	private function openEntry( string $sourcePath, string $entryName, FileCursor $cursor ): void {
		if ( null === $cursor->headerOffset ) {
			$this->seekEnd();
			$cursor->headerOffset = (int) ftell( $this->handle );
			$cursor->sourceOffset = 0;
			$cursor->written      = 0;
			$cursor->crc          = Crc32::EMPTY;
			$mtime                = $cursor->sourceMtime ?? (int) filemtime( $sourcePath );
			$this->write( Header::pack( $entryName, 0, $mtime ) );
			return;
		}

		$data_end = $cursor->headerOffset + Format::HEADER_SIZE + $cursor->written;
		fflush( $this->handle );
		$stat = fstat( $this->handle );
		if ( false === $stat || $stat['size'] < $data_end ) {
			throw new ArchiveException( 'The archive is shorter than the saved progress; cannot resume.' );
		}
		if ( ! ftruncate( $this->handle, $data_end ) ) {
			throw new ArchiveException( 'Cannot truncate the archive to resume writing.' );
		}
		$this->seek( $data_end );
	}

	private function patchHeader( int $headerOffset, int $size, string $crc ): void {
		$this->seek( $headerOffset + Header::SIZE_FIELD_OFFSET );
		$this->write( pack( 'a14', (string) $size ) );
		$this->seek( $headerOffset + Header::CRC_FIELD_OFFSET );
		$this->write( pack( 'a8', $crc ) );
		$this->seekEnd();
	}

	private function seekEnd(): void {
		$this->seek( 0, SEEK_END );
	}

	private function seek( int $offset, int $whence = SEEK_SET ): void {
		if ( 0 !== fseek( $this->handle, $offset, $whence ) ) {
			throw new ArchiveException( 'Cannot seek in the archive.' );
		}
	}

	private function write( string $bytes ): void {
		$length  = strlen( $bytes );
		$written = 0;
		while ( $written < $length ) {
			$n = fwrite( $this->handle, substr( $bytes, $written ) );
			if ( false === $n || 0 === $n ) {
				throw new ArchiveException( 'Could not write to the archive. The disk may be full.' );
			}
			$written += $n;
		}
	}
}
