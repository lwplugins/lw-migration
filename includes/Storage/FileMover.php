<?php
/**
 * Moves one file into a folder over as many requests as it takes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Archive\Crc32;
use LightweightPlugins\Migration\Archive\IncrementalCrc;
use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\DiskSpace;
use LightweightPlugins\Migration\Support\Files;
use LightweightPlugins\Migration\Support\HardLink;
use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a target (always one of our hidden temp names) the bytes and mtime
 * of the source, which is only read, and the owner-only mode of a backup
 * (BackupFileMode): a hard link when link() works
 * (never rename(), which copies silently across file systems), otherwise an
 * 'x'-mode copy in chunks over as many calls as it takes (resume cut back to
 * the saved offset, running CRC32, then a read-back pass). A changed source,
 * or a temp that vanished, changed or became a link, starts over; a failure
 * drops the temp. The finished cursor names the copy ('id') and the source
 * ('source') by the whole FileIdentity tuple (FAT reuses inode numbers), so
 * the original goes only through FileIdentity::removeOriginal(). Failures
 * carry a code, never a path (FolderMessages words them). WordPress-free.
 */
final class FileMover {

	public const CHUNK = 8388608;

	public const MARGIN = 67108864;

	public const MISSING = 1;

	public const FULL = 2;

	public const MISMATCH = 3;

	public const IO = 4;

	/** The target file system holds no file this large (FAT: 4 GiB - 1 byte). */
	public const TOO_BIG = 5;

	/** The largest file FAT can hold. */
	public const FAT_LIMIT = 4294967295;

	/**
	 * Free bytes in a folder: fn( string $dir ): ?int (null = unknown).
	 *
	 * @var \Closure
	 */
	private \Closure $free;

	/**
	 * @param bool          $links Whether a hard link may stand in for a copy.
	 * @param int           $chunk Bytes per read.
	 * @param \Closure|null $free  fn( string $dir ): ?int; default DiskSpace::free().
	 */
	public function __construct( private bool $links = true, private int $chunk = self::CHUNK, ?\Closure $free = null ) {
		$this->free = $free ?? static fn( string $dir ): ?int => DiskSpace::free( $dir );
	}

	/** Whether a hard link may stand in for the copy (the publish always tries one). */
	public function links(): bool {
		return $this->links;
	}

	/**
	 * @param string               $source   Regular file (not a link).
	 * @param string               $target   Hidden temp path (TempName) in the target folder; replaced when stale.
	 * @param array<string, mixed> $cursor   State between calls; start with [].
	 * @param Deadline             $deadline Time budget.
	 * @return bool True when $target is a complete, verified copy (or a hard link).
	 * @throws StorageException With MISSING, FULL, MISMATCH or IO; the temp is dropped and the cursor reset.
	 */
	public function copy( string $source, string $target, array &$cursor, Deadline $deadline ): bool {
		if ( ! TempName::is( basename( $target ) ) ) {
			throw new StorageException( 'The copy must go into a temp name.', self::IO );
		}
		clearstatcache( true ); // The realpath cache too: another process may have swapped a file.
		$source_id = FileIdentity::of( $source );
		if ( null === $source_id ) {
			throw new StorageException( 'The file to move is missing.', self::MISSING );
		}
		try {
			if ( ( $cursor['source'] ?? null ) !== $source_id || ! $this->tempIntact( $target, $cursor ) ) {
				self::drop( $target );
				$cursor = [
					'source' => $source_id,
					'phase'  => 'start',
					'offset' => 0,
					'crc'    => Crc32::EMPTY,
				];
			}
			if ( 'start' === $cursor['phase'] ) {
				$this->start( $source, $target, $cursor );
			}
			if ( 'copy' === $cursor['phase'] ) {
				$this->copyChunks( $source, $target, $cursor, $deadline );
			}
			if ( 'verify' === $cursor['phase'] && ! $deadline->reached() ) {
				$this->verifyChunks( $target, $cursor, $deadline );
			}
		} catch ( StorageException $e ) {
			self::drop( $target );
			$cursor = [];
			throw $e;
		}

		return 'done' === $cursor['phase'];
	}

	/**
	 * After 'start', the temp must still be a regular file (never written
	 * through a link), at least as long as the saved offset, and once done the
	 * very file that was verified.
	 *
	 * @param string               $target Temp.
	 * @param array<string, mixed> $cursor Cursor.
	 */
	private function tempIntact( string $target, array $cursor ): bool {
		$phase = $cursor['phase'] ?? '';
		if ( 'start' === $phase ) {
			return true;
		}
		$temp = FileIdentity::of( $target );
		if ( null === $temp ) {
			return false;
		}

		return 'done' === $phase ? ( $cursor['id'] ?? null ) === $temp : (int) ( $cursor['offset'] ?? 0 ) <= $temp['size'];
	}

	/**
	 * @param string               $source Source.
	 * @param string               $target Temp.
	 * @param array<string, mixed> $cursor Cursor.
	 * @throws StorageException FULL or IO.
	 */
	private function start( string $source, string $target, array &$cursor ): void {
		if ( $this->links && HardLink::make( $source, $target ) ) { // The copy is the fallback.
			BackupFileMode::apply( $target ); // Same inode as the source: both become owner-only.
			$cursor['phase'] = 'done';
			$cursor['id']    = $cursor['source'];
			return;
		}
		$this->roomFor( $target, (int) $cursor['source']['size'] );
		fclose( self::open( $target, 'xb' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		BackupFileMode::apply( $target ); // Before any byte is written: a handle opened mid-copy must not see a wider mode.
		$cursor['phase'] = 'copy';
	}

	/**
	 * @param string               $source   Source.
	 * @param string               $target   Temp.
	 * @param array<string, mixed> $cursor   Cursor.
	 * @param Deadline             $deadline Time budget.
	 * @throws StorageException MISSING, FULL, TOO_BIG or IO.
	 */
	private function copyChunks( string $source, string $target, array &$cursor, Deadline $deadline ): void {
		$in  = self::open( $source, 'rb' );
		$out = self::open( $target, 'r+b' );
		try {
			$offset = (int) $cursor['offset'];
			$size   = (int) $cursor['source']['size'];
			if ( ! ftruncate( $out, $offset ) || 0 !== fseek( $out, $offset ) ) {
				throw new StorageException( 'Cannot resume the copy.', self::IO );
			}
			$crc              = new IncrementalCrc( (string) $cursor['crc'] );
			$end              = $offset;
			$offset           = $this->slices(
				$in,
				$offset,
				$size,
				$deadline,
				function ( string $data ) use ( $out, $target, $crc, &$end ): void {
					$end += strlen( $data );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a full disk warns; writeFailed() says why.
					if ( @fwrite( $out, $data ) !== strlen( $data ) ) {
						$this->writeFailed( $target, $end, strlen( $data ) );
					}
					$crc->update( $data );
				},
				self::MISSING
			);
			$cursor['offset'] = $offset;
			$cursor['crc']    = $crc->value();
			if ( $offset >= $size ) {
				$this->flush( $out, $target, $size );
				$cursor['phase']    = 'verify';
				$cursor['checked']  = 0;
				$cursor['checkCrc'] = Crc32::EMPTY;
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @param resource $out    Open temp.
	 * @param string   $target Temp path.
	 * @param int      $size   Bytes written in all.
	 * @throws StorageException FULL, TOO_BIG or IO.
	 */
	private function flush( $out, string $target, int $size ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a full disk warns; the code below says why.
		if ( ! @fflush( $out ) ) {
			$this->writeFailed( $target, $size, 0 );
		}
		if ( \function_exists( 'fsync' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort: a stream that cannot sync (a wrapper) warns; the verify pass reads the copy back anyway.
			@fsync( $out );
		}
	}

	/**
	 * @param string $target Temp path.
	 * @param int    $end    Size the copy would have had.
	 * @param int    $bytes  Bytes that did not fit.
	 * @return never
	 * @throws StorageException Always; the code from writeFailure().
	 */
	private function writeFailed( string $target, int $end, int $bytes ): void {
		throw new StorageException( 'Cannot write the copy.', self::writeFailure( $end, ( $this->free )( dirname( $target ) ), $bytes ) );
	}

	/**
	 * Why a write failed: past FAT's largest file it is the size limit (the
	 * folder's file system cannot be asked portably), with too little room
	 * left the folder is full, otherwise input/output.
	 *
	 * @param int      $end   Size the file would have had.
	 * @param int|null $free  Free bytes in the folder (null = unknown).
	 * @param int      $bytes Bytes that did not fit.
	 */
	public static function writeFailure( int $end, ?int $free, int $bytes ): int {
		if ( $end > self::FAT_LIMIT && ( null === $free || $free >= $bytes + self::MARGIN ) ) {
			return self::TOO_BIG;
		}

		return null !== $free && $free < $bytes + self::MARGIN ? self::FULL : self::IO;
	}

	/**
	 * @param string               $target   Temp.
	 * @param array<string, mixed> $cursor   Cursor.
	 * @param Deadline             $deadline Time budget.
	 * @throws StorageException MISMATCH or IO.
	 */
	private function verifyChunks( string $target, array &$cursor, Deadline $deadline ): void {
		$size = (int) $cursor['source']['size'];
		if ( ( FileIdentity::of( $target )['size'] ?? -1 ) !== $size ) {
			throw new StorageException( 'The copy does not match the original.', self::MISMATCH );
		}
		$in = self::open( $target, 'rb' );
		try {
			$crc                = new IncrementalCrc( (string) $cursor['checkCrc'] );
			$cursor['checked']  = $this->slices( $in, (int) $cursor['checked'], $size, $deadline, static fn( string $data ) => $crc->update( $data ) );
			$cursor['checkCrc'] = $crc->value();
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $cursor['checked'] < $size ) {
			return;
		}
		if ( ! hash_equals( (string) $cursor['crc'], (string) $cursor['checkCrc'] ) ) {
			throw new StorageException( 'The copy does not match the original.', self::MISMATCH );
		}
		$this->finish( $target, $cursor );
	}

	/**
	 * The mtime of the source, then the copy's identity (after closing:
	 * some file systems number a file only then).
	 *
	 * @param string               $target Temp.
	 * @param array<string, mixed> $cursor Cursor.
	 * @throws StorageException IO.
	 */
	private function finish( string $target, array &$cursor ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked here without printing the path.
		$id = @touch( $target, (int) $cursor['source']['mtime'] ) ? FileIdentity::of( $target ) : null;
		if ( null === $id ) {
			throw new StorageException( 'Cannot keep the modification time.', self::IO );
		}
		$cursor['phase'] = 'done';
		$cursor['id']    = $id;
	}

	/**
	 * @param string $target Temp.
	 * @param int    $bytes  Bytes to write.
	 * @throws StorageException FULL.
	 */
	private function roomFor( string $target, int $bytes ): void {
		$free = ( $this->free )( dirname( $target ) );
		if ( null !== $free && $free < $bytes + self::MARGIN ) {
			throw new StorageException( 'Not enough free space in the target folder.', self::FULL );
		}
	}

	/**
	 * Hands $in's bytes from $at to $end to $each in chunks, until the end or the deadline.
	 *
	 * @param resource $in       Open file.
	 * @param int      $at       Offset to start at.
	 * @param int      $end      Offset to stop at.
	 * @param Deadline $deadline Time budget.
	 * @param \Closure $each     fn( string $chunk ): void.
	 * @param int      $short    Code when the file ends before $end.
	 * @return int The offset reached.
	 * @throws StorageException IO, $short, or what $each throws.
	 */
	private function slices( $in, int $at, int $end, Deadline $deadline, \Closure $each, int $short = self::MISMATCH ): int {
		if ( 0 !== fseek( $in, $at ) ) {
			throw new StorageException( 'Cannot read a file of the move.', self::IO );
		}
		while ( $at < $end ) {
			$data = self::read( $in, min( $this->chunk, $end - $at ) );
			if ( '' === $data ) {
				throw new StorageException( 'A file of the move got shorter.', $short );
			}
			$each( $data );
			$at += strlen( $data );
			if ( $deadline->reached() ) {
				break;
			}
		}

		return $at;
	}

	/**
	 * @param resource $handle Open file.
	 * @param int      $length Bytes wanted.
	 * @return string Fewer only at the end of the file or on a read error.
	 */
	public static function read( $handle, int $length ): string {
		$data = '';
		$have = 0;
		while ( $have < $length && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a read error ends the loop; the caller decides.
			$part = @fread( $handle, $length - $have );
			if ( false === $part || '' === $part ) {
				break;
			}
			$data .= $part;
			$have += strlen( $part );
		}

		return $data;
	}

	/**
	 * @param string $path File.
	 * @param string $mode fopen() mode.
	 * @return resource
	 * @throws StorageException IO (the message names no path).
	 */
	public static function open( string $path, string $mode ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a failure becomes a code without the path.
		$handle = @fopen( $path, $mode );
		if ( false === $handle ) {
			throw new StorageException( 'Cannot open a file of the move.', self::IO );
		}

		return $handle;
	}

	/**
	 * Removes our temp by its unguessable name (a link there is removed, never followed).
	 *
	 * @param string $target Temp path.
	 */
	private static function drop( string $target ): void {
		if ( TempName::is( basename( $target ) ) ) {
			Files::removeFile( $target );
		}
	}
}
