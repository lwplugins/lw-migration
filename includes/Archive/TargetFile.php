<?php
/**
 * A file being extracted from the archive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

use LightweightPlugins\Migration\Support\TempName;

defined( 'ABSPATH' ) || exit;

/**
 * Each entry is written to a randomly named temporary file next to the target
 * and renamed over the target once it is complete, so a live file is never
 * half-written and no link at the target is ever written through.
 */
final class TargetFile {

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * Temporary file being written.
	 *
	 * @var string
	 */
	private string $tempPath;

	/**
	 * Final output path.
	 *
	 * @var string
	 */
	private string $targetPath;

	/**
	 * @param resource $handle     Open temporary file.
	 * @param string   $tempPath   Temporary file.
	 * @param string   $targetPath Final output path.
	 */
	private function __construct( $handle, string $tempPath, string $targetPath ) {
		$this->handle     = $handle;
		$this->tempPath   = $tempPath;
		$this->targetPath = $targetPath;
	}

	/**
	 * Starts a new temporary file, or reopens the one in $cursor.
	 *
	 * @param string        $targetPath Output file.
	 * @param ExtractCursor $cursor     Progress; tempPath is set on a fresh start.
	 * @return self
	 * @throws UnsafePathException When the target is a symlink, or the saved temporary file is not ours, has another hard link or changed
	 *                             (an output that cannot be prepared for writing throws its parent ArchiveException).
	 */
	public static function open( string $targetPath, ExtractCursor $cursor ): self {
		if ( is_link( $targetPath ) ) {
			throw new UnsafePathException( sprintf( 'Refusing to write through a symlink: %s', $targetPath ) );
		}
		Folder::ensure( dirname( $targetPath ) );
		if ( null === $cursor->tempPath ) {
			return self::create( $targetPath, $cursor );
		}

		return self::reopen( $targetPath, $cursor );
	}

	/**
	 * Deletes the temporary file of an abandoned extraction (failure or cancel).
	 * Only a regular file that is one of this target's temporary files is deleted.
	 *
	 * @param ExtractCursor $cursor     Progress; tempPath is cleared.
	 * @param string        $targetPath Output file the cursor belongs to.
	 * @return void
	 */
	public static function discard( ExtractCursor $cursor, string $targetPath ): void {
		$temp             = $cursor->tempPath;
		$cursor->tempPath = null;
		if ( null === $temp || ! self::isTempOf( $temp, $targetPath ) ) {
			return;
		}
		clearstatcache( true, $temp );
		if ( is_file( $temp ) && ! is_link( $temp ) ) {
			unlink( $temp );
		}
	}

	/**
	 * @param string $data Bytes.
	 * @return void
	 * @throws ArchiveException When the write fails.
	 */
	public function write( string $data ): void {
		$length  = strlen( $data );
		$written = 0;
		while ( $written < $length ) {
			$n = fwrite( $this->handle, substr( $data, $written ) );
			if ( false === $n || 0 === $n ) {
				throw new ArchiveException( sprintf( 'Could not write %s. The disk may be full.', $this->targetPath ) );
			}
			$written += $n;
		}
	}

	/**
	 * Sets the entry time on the temporary file and moves it over the target.
	 *
	 * @param int           $mtime  Entry time, 0 for none.
	 * @param ExtractCursor $cursor Progress; tempPath is cleared.
	 * @return void
	 * @throws ArchiveException When the file cannot be moved into place, or (UnsafePathException)
	 *                          the temporary path no longer names the file we wrote.
	 */
	public function commit( int $mtime, ExtractCursor $cursor ): void {
		$written = fstat( $this->handle );
		$this->close();
		self::assertSameFile( $written, $this->tempPath );
		if ( $mtime > 0 ) {
			touch( $this->tempPath, $mtime );
		}
		// rename() replaces the directory entry itself: a symlink at the target is replaced, not
		// followed, and a hard link is unlinked, not written. PHP's rename() also replaces an existing file on Windows.
		if ( ! rename( $this->tempPath, $this->targetPath ) ) {
			throw new ArchiveException( sprintf( 'Cannot move the extracted file into place: %s', $this->targetPath ) );
		}
		clearstatcache( true, $this->targetPath );
		$cursor->tempPath = null;
	}

	/**
	 * Closes the output and deletes the temporary file.
	 *
	 * @param ExtractCursor $cursor Progress; tempPath is cleared.
	 * @return void
	 */
	public function abandon( ExtractCursor $cursor ): void {
		$this->close();
		self::discard( $cursor, $this->targetPath );
	}

	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * A fresh start: a new, randomly named file, created exclusively.
	 *
	 * @param string        $targetPath Output file.
	 * @param ExtractCursor $cursor     Progress, reset to the start of the entry.
	 * @return self
	 * @throws ArchiveException When the temporary file cannot be created.
	 */
	private static function create( string $targetPath, ExtractCursor $cursor ): self {
		$temp = TempName::generate( dirname( $targetPath ) );
		$out  = is_writable( dirname( $targetPath ) ) ? fopen( $temp, 'xb' ) : false;
		if ( false === $out ) {
			throw new ArchiveException( sprintf( 'Cannot write file: %s', $temp ) );
		}
		$cursor->consumed = 0;
		$cursor->written  = 0;
		$cursor->crc      = Crc32::EMPTY;
		$cursor->tempPath = $temp;
		$cursor->replaced = self::replacedBytes( $targetPath );

		return new self( $out, $temp, $targetPath );
	}

	/**
	 * Bytes the rename will free: the size of the regular file at the target,
	 * unless another hard link keeps its data (0 for no file, a link or anything else).
	 *
	 * @param string $targetPath Output file.
	 */
	private static function replacedBytes( string $targetPath ): int {
		clearstatcache( true, $targetPath );
		$stat = is_file( $targetPath ) && ! is_link( $targetPath ) ? lstat( $targetPath ) : false;
		clearstatcache( true, $targetPath );

		return false === $stat || 1 !== (int) $stat['nlink'] ? 0 : (int) $stat['size'];
	}

	/**
	 * A resumed extraction appends to the single-linked temporary file it started.
	 *
	 * @param string        $targetPath Output file.
	 * @param ExtractCursor $cursor     Progress with a tempPath.
	 * @return self
	 * @throws UnsafePathException When the saved path is not our temporary file, has another hard link or changed.
	 * @throws ArchiveException When the file is missing, shorter than the progress, or cannot be truncated.
	 */
	private static function reopen( string $targetPath, ExtractCursor $cursor ): self {
		$temp = (string) $cursor->tempPath;
		if ( ! self::isTempOf( $temp, $targetPath ) ) {
			throw new UnsafePathException( sprintf( 'Not a temporary file of this target: %s', $temp ) );
		}
		clearstatcache( true, $temp );
		if ( is_link( $temp ) || ! is_file( $temp ) ) {
			throw new ArchiveException( sprintf( 'The partly extracted file is gone; cannot resume: %s', $temp ) );
		}
		$out = fopen( $temp, 'c+b' );
		if ( false === $out ) {
			throw new ArchiveException( sprintf( 'Cannot write file: %s', $temp ) );
		}
		try {
			self::prepareResume( $out, $temp, $cursor );
		} catch ( ArchiveException $e ) {
			fclose( $out );
			throw $e;
		}

		return new self( $out, $temp, $targetPath );
	}

	/**
	 * @param resource      $out    Reopened temporary file.
	 * @param string        $temp   Its path.
	 * @param ExtractCursor $cursor Progress.
	 * @return void
	 * @throws UnsafePathException When the path changed or the file has another hard link.
	 * @throws ArchiveException When the file is shorter than the progress or cannot be truncated.
	 */
	private static function prepareResume( $out, string $temp, ExtractCursor $cursor ): void {
		$opened = fstat( $out );
		// Checked after opening: a symlink swapped in after the earlier check is caught here, before any write.
		self::assertSameFile( $opened, $temp );
		if ( false === $opened || $opened['nlink'] > 1 ) {
			throw new UnsafePathException( sprintf( 'Refusing to resume into a file with another hard link: %s', $temp ) );
		}
		if ( $opened['size'] < $cursor->written ) {
			throw new ArchiveException( 'The extracted file is shorter than the saved progress; cannot resume.' );
		}
		if ( ! ftruncate( $out, $cursor->written ) || 0 !== fseek( $out, $cursor->written ) ) {
			throw new ArchiveException( sprintf( 'Cannot truncate %s to resume extraction.', $temp ) );
		}
	}

	/**
	 * @param string $temp       Saved temporary path.
	 * @param string $targetPath Output file.
	 * @return bool True when $temp is a temporary file name in the target's folder.
	 */
	private static function isTempOf( string $temp, string $targetPath ): bool {
		return dirname( $temp ) === dirname( $targetPath ) && TempName::is( basename( $temp ) );
	}

	/**
	 * The path must still name the file behind $opened (same inode and device).
	 *
	 * @param array<int|string, int>|false $opened fstat() of our handle.
	 * @param string                       $path   Path to check.
	 * @return void
	 * @throws UnsafePathException When the path names another file now.
	 */
	private static function assertSameFile( $opened, string $path ): void {
		clearstatcache( true, $path );
		$named = file_exists( $path ) || is_link( $path ) ? lstat( $path ) : false;
		// PHP 8.2 serves later stat calls (filemtime after touch) from this lstat; drop it.
		clearstatcache( true, $path );
		if ( false === $opened || false === $named || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev'] ) {
			throw new UnsafePathException( sprintf( 'The target changed while it was being written: %s', $path ) );
		}
	}
}
