<?php
/**
 * Writes uploaded chunks into the job folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use LightweightPlugins\Migration\Http\RangeDownloader;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only at the exact current length of `<target>.part` (owner-only, flocked),
 * so a repeated or reordered chunk can never write twice or leave a gap; the reply
 * to a wrong offset tells the browser where to go on. The first chunk must begin
 * with a complete `package.json` header, otherwise nothing is kept. At the declared
 * size the part becomes the target; a full part whose rename failed is finished by
 * the next chunk or by finish(). Only the fixed upload name is ever written, a link
 * (symbolic or hard) is never followed or taken as the upload, and a foreign file
 * at the target is never accepted. No PHP warning escapes (it would carry the
 * server path and break a JSON reply). Pure (no WordPress); callers also serialize
 * appends per job (JobsApi holds the job lock).
 */
final class UploadReceiver {

	public const PART_SUFFIX = RangeDownloader::PART_SUFFIX;

	/**
	 * Bytes held for $target: the finished file, else its part, else 0.
	 *
	 * @param string $target Final file.
	 */
	public static function received( string $target ): int {
		clearstatcache();
		if ( self::isFile( $target ) ) {
			return (int) filesize( $target );
		}
		$part = $target . self::PART_SUFFIX;

		return self::isFile( $part ) ? (int) filesize( $part ) : 0;
	}

	/**
	 * @param string $target Final file in the job folder (named ImportFiles::UPLOAD).
	 * @param int    $size   Declared size of the whole upload.
	 * @param int    $offset Where this chunk starts.
	 * @param string $bytes  The chunk.
	 * @return int Bytes held after the chunk; $size when the upload is complete.
	 * @throws UploadException When the chunk is refused or cannot be written.
	 */
	public static function append( string $target, int $size, int $offset, string $bytes ): int {
		if ( ImportFiles::UPLOAD !== basename( $target ) ) {
			throw new UploadException( UploadException::WRITE );
		}
		self::refuseFinished( $target, $size );
		if ( '' === $bytes ) {
			throw new UploadException( UploadException::EMPTY_CHUNK, self::received( $target ) );
		}
		$part    = $target . self::PART_SUFFIX;
		$created = ! file_exists( $part ) && ! is_link( $part );
		Files::privateFile( $part );
		$handle = self::open( $part );
		try {
			$have = self::write( $handle, $target, $size, $offset, $bytes );
		} catch ( UploadException $e ) {
			fclose( $handle );
			if ( $created ) {
				self::dropEmpty( $part );
			}
			throw $e;
		}
		fclose( $handle );
		if ( $have === $size ) {
			self::complete( $part, $target, $have );
		}

		return $have;
	}

	/**
	 * Finishes an upload whose part holds all bytes but was not renamed yet.
	 *
	 * @param string $target Final file in the job folder.
	 * @param int    $size   Declared size of the whole upload.
	 * @return bool True when $target is the finished upload.
	 */
	public static function finish( string $target, int $size ): bool {
		clearstatcache();
		if ( self::isFile( $target ) || is_link( $target ) ) {
			return self::isFile( $target ) && filesize( $target ) === $size;
		}
		$part = $target . self::PART_SUFFIX;
		if ( ImportFiles::UPLOAD !== basename( $target ) || ! self::isFile( $part ) ) {
			return false;
		}
		try {
			$handle = self::open( $part );
			try {
				$have = self::locked( $handle, $part, $target, $size );
			} finally {
				fclose( $handle );
			}
			if ( $have !== $size ) {
				return false;
			}
			self::complete( $part, $target, $have );
		} catch ( UploadException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * A plain file, not a link to one.
	 *
	 * @param string $path Path.
	 */
	private static function isFile( string $path ): bool {
		return is_file( $path ) && ! is_link( $path );
	}

	/**
	 * A finished upload takes no more bytes; a foreign file at the target is never accepted.
	 *
	 * @param string $target Final file.
	 * @param int    $size   Declared size.
	 * @throws UploadException OFFSET (with the size) when the upload is finished, WRITE for a file of another size.
	 */
	private static function refuseFinished( string $target, int $size ): void {
		clearstatcache();
		if ( ! self::isFile( $target ) ) {
			return;
		}
		if ( filesize( $target ) !== $size ) {
			throw new UploadException( UploadException::WRITE );
		}
		throw new UploadException( UploadException::OFFSET, $size );
	}

	/**
	 * @param string $part Part file.
	 * @return resource Opened r+b.
	 * @throws UploadException When it is no plain file or cannot be opened.
	 */
	private static function open( string $part ) {
		clearstatcache();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked first (a plain file, no link); a concurrent removal is reported as WRITE without the server path.
		$handle = self::isFile( $part ) ? @fopen( $part, 'r+b' ) : false;
		if ( false === $handle ) {
			throw new UploadException( UploadException::WRITE );
		}

		return $handle;
	}

	/**
	 * Takes the part's lock and checks, under it, that the part is still ours
	 * (one name, still at its path) and the upload was not finished meanwhile.
	 *
	 * @param resource $handle Part file.
	 * @param string   $part   Part path.
	 * @param string   $target Final file.
	 * @param int      $size   Declared size.
	 * @return int Bytes in the part.
	 * @throws UploadException When the part cannot be trusted or the upload is finished.
	 */
	private static function locked( $handle, string $part, string $target, int $size ): int {
		if ( ! flock( $handle, LOCK_EX ) ) {
			throw new UploadException( UploadException::WRITE );
		}
		clearstatcache();
		$stat = fstat( $handle );
		$path = self::isFile( $part ) ? stat( $part ) : false;
		if ( false === $stat || false === $path || 1 !== (int) $stat['nlink'] || $stat['ino'] !== $path['ino'] || $stat['dev'] !== $path['dev'] ) {
			throw new UploadException( UploadException::WRITE );
		}
		self::refuseFinished( $target, $size );

		return (int) $stat['size'];
	}

	/**
	 * @param resource $handle Part file, opened r+b.
	 * @param string   $target Final file.
	 * @param int      $size   Declared size.
	 * @param int      $offset Where the chunk starts.
	 * @param string   $bytes  The chunk.
	 * @return int Bytes held after the chunk ($size, unwritten, when the part is already full).
	 * @throws UploadException When the chunk does not fit or cannot be written.
	 */
	private static function write( $handle, string $target, int $size, int $offset, string $bytes ): int {
		$have = self::locked( $handle, $target . self::PART_SUFFIX, $target, $size );
		if ( $have === $size ) {
			return $have; // An earlier rename failed: complete it now.
		}
		$length = strlen( $bytes );
		if ( $offset !== $have ) {
			throw new UploadException( UploadException::OFFSET, $have );
		}
		if ( $have + $length > $size ) {
			throw new UploadException( UploadException::TOO_LARGE, $have );
		}
		if ( 0 === $have && ! self::startsAnArchive( $bytes, $size ) ) {
			ftruncate( $handle, 0 );
			throw new UploadException( UploadException::NOT_ARCHIVE );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a full disk raises a notice; the short write is detected, undone and reported as WRITE.
		$written = 0 === fseek( $handle, $have ) ? @fwrite( $handle, $bytes ) : false;
		if ( $length !== $written || ! fflush( $handle ) ) {
			ftruncate( $handle, $have );
			throw new UploadException( UploadException::WRITE, $have );
		}

		return $have + $length;
	}

	/**
	 * Turns the finished part into the target.
	 *
	 * @param string $part   Part file.
	 * @param string $target Final file.
	 * @param int    $have   Bytes in the part.
	 * @throws UploadException When something else occupies the target or the rename fails.
	 */
	private static function complete( string $part, string $target, int $have ): void {
		clearstatcache();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the target is checked to be free first; a failure is reported as WRITE without the server path.
		if ( file_exists( $target ) || is_link( $target ) || ! @rename( $part, $target ) ) {
			throw new UploadException( UploadException::WRITE, $have );
		}
	}

	/**
	 * Removes a part this request created when it stayed empty.
	 *
	 * @param string $part Part file.
	 */
	private static function dropEmpty( string $part ): void {
		clearstatcache();
		if ( self::isFile( $part ) && 0 === filesize( $part ) ) {
			Files::removeFile( $part );
		}
	}

	private static function startsAnArchive( string $bytes, int $size ): bool {
		if ( $size < 2 * Format::HEADER_SIZE || strlen( $bytes ) < Format::HEADER_SIZE ) {
			return false;
		}
		try {
			return Format::PACKAGE === Header::unpack( substr( $bytes, 0, Format::HEADER_SIZE ), 0 )->name;
		} catch ( InvalidArchiveException $e ) {
			return false;
		}
	}
}
