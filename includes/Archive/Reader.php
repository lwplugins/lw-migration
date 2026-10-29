<?php
/**
 * Reads a .wpress archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\Deadline;

defined( 'ABSPATH' ) || exit;

/**
 * Iterates entries, decodes their data and verifies checksums. Config entries
 * (package.json, multisite.json) are always read plain.
 */
final class Reader {

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

	/**
	 * Archive size in bytes.
	 *
	 * @var int
	 */
	private int $fileSize;

	/**
	 * Parsed end-of-archive block, once read.
	 *
	 * @var EofInfo|null
	 */
	private ?EofInfo $eof = null;

	public function __construct( string $archivePath, ?ChunkCodec $codec = null ) {
		// Checked first so a missing file gives no PHP warning (it would carry the server path).
		$handle = is_file( $archivePath ) && is_readable( $archivePath ) ? fopen( $archivePath, 'rb' ) : false;
		if ( false === $handle ) {
			throw new ArchiveException( sprintf( 'Cannot open archive: %s', basename( $archivePath ) ) );
		}
		$stat           = fstat( $handle );
		$this->handle   = $handle;
		$this->fileSize = (int) ( $stat['size'] ?? 0 );
		$this->codec    = $codec ?? ChunkCodec::plain();
		$this->plain    = ChunkCodec::plain();
	}

	public static function hasArchiveExtension( string $fileName ): bool {
		return Format::EXTENSION === strtolower( pathinfo( $fileName, PATHINFO_EXTENSION ) );
	}

	public function firstEntry(): ?Entry {
		if ( $this->fileSize < Format::HEADER_SIZE ) {
			return null;
		}
		$block = $this->readAt( 0, Format::HEADER_SIZE );
		if ( null !== Eof::parse( $block ) ) {
			return null;
		}
		try {
			return Header::unpack( $block, 0 );
		} catch ( InvalidArchiveException $e ) {
			return null;
		}
	}

	public function eof(): EofInfo {
		if ( null !== $this->eof ) {
			return $this->eof;
		}
		if ( $this->fileSize < Format::HEADER_SIZE ) {
			throw new InvalidArchiveException( 'The file is too small to be a .wpress archive.' );
		}
		$info = Eof::parse( $this->readAt( $this->fileSize - Format::HEADER_SIZE, Format::HEADER_SIZE ) );
		if ( null === $info ) {
			throw new InvalidArchiveException( 'The archive has no end-of-archive block: it is incomplete or not a .wpress file.' );
		}
		if ( 2 === $info->version && $info->size !== $this->fileSize - Format::HEADER_SIZE ) {
			throw new InvalidArchiveException( 'The archive size does not match its end-of-archive record: the file is incomplete.' );
		}
		$this->eof = $info;

		return $info;
	}

	public function validate(): void {
		$this->eof();
		$first = $this->firstEntry();
		if ( null === $first || Format::PACKAGE !== $first->name ) {
			throw new InvalidArchiveException( 'The archive does not start with package.json.' );
		}
	}

	public function dataEnd(): int {
		$this->eof();

		return $this->fileSize - Format::HEADER_SIZE;
	}

	/**
	 * Iterates entries starting at a header boundary (0 = the first entry).
	 *
	 * @param int $fromOffset Header offset of the first entry to yield; dataEnd() yields nothing.
	 * @return \Generator<int, Entry>
	 * @throws InvalidArchiveException When the offset or an entry header is invalid or runs past the end.
	 */
	public function entries( int $fromOffset = 0 ): \Generator {
		$end = $this->dataEnd();
		if ( $fromOffset < 0 || $fromOffset > $end ) {
			throw new InvalidArchiveException( sprintf( 'Offset %d is outside the archive data.', $fromOffset ) );
		}
		$offset = $fromOffset;
		while ( $offset < $end ) {
			$entry = $this->entryAt( $offset );
			yield $entry;
			$offset = $entry->dataOffset() + $entry->size;
		}
	}

	/**
	 * Reads and validates the entry header at a byte offset.
	 *
	 * @param int $offset Header offset.
	 * @return Entry
	 * @throws InvalidArchiveException When there is no valid entry header at the offset.
	 */
	public function entryAt( int $offset ): Entry {
		$end = $this->dataEnd();
		if ( $offset < 0 || $offset >= $end ) {
			throw new InvalidArchiveException( sprintf( 'No entry header at byte %d.', $offset ) );
		}
		if ( $offset + Format::HEADER_SIZE > $end ) {
			throw new InvalidArchiveException( sprintf( 'Truncated entry header at byte %d.', $offset ) );
		}
		$entry = Header::unpack( $this->readAt( $offset, Format::HEADER_SIZE ), $offset );
		if ( $entry->dataOffset() + $entry->size > $end ) {
			throw new InvalidArchiveException( sprintf( 'Entry %s extends past the end of the archive.', $entry->name ) );
		}

		return $entry;
	}

	public function find( string $name ): ?Entry {
		foreach ( $this->entries() as $entry ) {
			if ( $entry->name === $name ) {
				return $entry;
			}
		}

		return null;
	}

	public function readContents( Entry $entry, int $maxBytes = 16777216 ): string {
		$codec = $entry->isConfig() ? $this->plain : $this->codec;
		$this->seek( $entry->dataOffset() );
		$out      = '';
		$consumed = 0;
		while ( $consumed < $entry->size ) {
			[ $data, $used ] = $codec->decodeNext( $this->handle, $entry->size - $consumed );
			$out            .= $data;
			$consumed       += $used;
			if ( strlen( $out ) > $maxBytes ) {
				throw new ArchiveException( sprintf( 'Entry %s is larger than %d bytes.', $entry->name, $maxBytes ) );
			}
		}
		$this->assertCrc( $entry, Crc32::ofString( $out ) );

		return $out;
	}

	/**
	 * Extracts one entry through a temporary file, resumably.
	 *
	 * @param Entry         $entry      Entry.
	 * @param string        $targetPath Output file.
	 * @param ExtractCursor $cursor     Progress of this entry.
	 * @param Deadline      $deadline   Time budget.
	 * @param WriteBudget   $budget     Running total of the whole extraction: every decoded chunk is counted in full before
	 *                                  it is written (the file it replaces still exists then); once the entry is renamed into
	 *                                  place, the size of the replaced file ($cursor->replaced) is refunded.
	 * @return bool True when the entry is complete.
	 * @throws ArchiveException On unreadable, damaged or unwritable data, or (WriteLimitException) when the next chunk
	 *                          would take the budget's total past its limit; the temporary file is removed.
	 * @throws \Throwable Anything else that stopped the entry, after its temporary file was removed.
	 */
	public function extractTo( Entry $entry, string $targetPath, ExtractCursor $cursor, Deadline $deadline, WriteBudget $budget ): bool {
		try {
			$out = TargetFile::open( $targetPath, $cursor );
		} catch ( ArchiveException $e ) {
			// A resume that cannot continue its temporary file starts over next time.
			TargetFile::discard( $cursor, $targetPath );
			throw $e;
		}
		try {
			$this->seek( $entry->dataOffset() + $cursor->consumed );
			$codec = $entry->isConfig() ? $this->plain : $this->codec;
			$crc   = new IncrementalCrc( $cursor->crc );

			while ( $cursor->consumed < $entry->size ) {
				[ $data, $used ] = $codec->decodeNext( $this->handle, $entry->size - $cursor->consumed );
				$budget->spend( strlen( $data ) );
				$out->write( $data );
				$crc->update( $data );
				$cursor->consumed += $used;
				$cursor->written  += strlen( $data );

				if ( $cursor->consumed < $entry->size && $deadline->reached() ) {
					$cursor->crc = $crc->value();
					$out->close();
					return false;
				}
			}
			$cursor->crc = $crc->value();
			$this->assertCrc( $entry, $cursor->crc );
			$out->commit( $entry->mtime, $cursor );
			// The old file is gone now. A request killed between the rename and the caller's save
			// replays the entry against the saved total and refunds its own file: a bounded slip (review M3).
			$budget->refund( min( $cursor->replaced, $cursor->written ) );
		} catch ( \Throwable $e ) {
			$out->abandon( $cursor );
			throw $e;
		}

		return true;
	}

	public function verifyChecksum( CrcCursor $cursor, Deadline $deadline ): bool {
		$eof = $this->eof();
		if ( 2 !== $eof->version ) {
			return true;
		}
		$cursor->size = (int) $eof->size;
		if ( ! ArchiveChecksum::advance( $this->handle, $cursor, $deadline ) ) {
			return false;
		}
		if ( $cursor->crc !== $eof->crc ) {
			throw new CorruptedEntryException( 'The archive checksum does not match: the file is damaged.' );
		}

		return true;
	}

	public function close(): void {
		fclose( $this->handle );
	}

	private function assertCrc( Entry $entry, string $actual ): void {
		if ( '' !== $entry->crc && $entry->crc !== $actual ) {
			throw new CorruptedEntryException( sprintf( 'Checksum mismatch for %s: the archive is damaged.', $entry->name ) );
		}
	}

	private function seek( int $offset ): void {
		if ( 0 !== fseek( $this->handle, $offset ) ) {
			throw new ArchiveException( 'Cannot seek in the archive.' );
		}
	}

	private function readAt( int $offset, int $length ): string {
		$this->seek( $offset );
		$data = fread( $this->handle, $length );

		return false === $data ? '' : $data;
	}
}
