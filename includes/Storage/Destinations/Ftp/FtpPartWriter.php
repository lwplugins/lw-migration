<?php
/**
 * Writes one part of an upload on an FTP server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D28. The first part is a STOR. Later parts: REST <offset> + STOR when
 * the server announces REST STREAM (it overwrites at the offset, so a part
 * sent again is harmless), else — or when that STOR is refused — APPE after
 * a SIZE check: only what the server lacks of this part is appended, so a
 * part is never appended twice; a size outside the part means the file is
 * not ours to continue (SESSION_GONE, the upload starts over). Every write
 * is checked with SIZE: the file must end where the part ends (a server
 * that ignored the offset and truncated starts over), and a server that
 * does not tell sizes is refused at the first part, since no upload could
 * be checked or resumed there. A refused APPE (after a refused REST+STOR or
 * without REST) is a passing failure: ext-ftp's false hides the reply, which
 * may be a passing 4xx (426/450/451) or a full disk (452), so the retry
 * budget ends a lasting refusal, never this one answer.
 */
final class FtpPartWriter {

	public const REST = 'rest';

	public const APPEND = 'append';

	public static function mode( FtpConnection $ftp ): string {
		foreach ( $ftp->features() as $line ) {
			if ( 1 === preg_match( '/^\s*REST\s+STREAM\b/i', $line ) ) {
				return self::REST;
			}
		}

		return self::APPEND;
	}

	/**
	 * @param FtpConnection $ftp    Connection in the folder.
	 * @param string        $mode   REST or APPEND.
	 * @param string        $name   The part file.
	 * @param int           $offset Where the part starts (never past the file's end).
	 * @param string        $bytes  The part.
	 * @param string        $where  ftp(s)://host:port for messages.
	 * @throws PermanentFailure DENIED, INVALID or SESSION_GONE (and append()'s TransientFailure SERVER).
	 */
	public static function write( FtpConnection $ftp, string $mode, string $name, int $offset, string $bytes, string $where ): void {
		if ( 0 === $offset ) {
			if ( ! $ftp->store( $name, $bytes, 0 ) ) {
				/* translators: %s: server address */
				throw new PermanentFailure( sprintf( __( '%s refused to store the file (no permission, or the disk is full).', 'lw-migration' ), $where ), PermanentFailure::DENIED );
			}
		} elseif ( self::APPEND === $mode || ! $ftp->store( $name, $bytes, $offset ) ) {
			self::append( $ftp, $name, $offset, $bytes, $where );
		}
		if ( self::size( $ftp, $name, $where ) !== $offset + strlen( $bytes ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s did not keep the earlier parts of the file; the upload starts over.', 'lw-migration' ), $where ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * Appends what the file lacks of this part.
	 *
	 * @param FtpConnection $ftp    Connection in the folder.
	 * @param string        $name   The part file.
	 * @param int           $offset Where the part starts.
	 * @param string        $bytes  The part.
	 * @param string        $where  For messages.
	 * @throws PermanentFailure INVALID or SESSION_GONE.
	 * @throws TransientFailure SERVER when APPE is refused.
	 */
	private static function append( FtpConnection $ftp, string $name, int $offset, string $bytes, string $where ): void {
		$have = self::size( $ftp, $name, $where ) - $offset;
		if ( $have < 0 || $have > strlen( $bytes ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'The partly uploaded file at %s has another size than expected; the upload starts over.', 'lw-migration' ), $where ), PermanentFailure::SESSION_GONE );
		}
		if ( strlen( $bytes ) === $have ) {
			return; // This part arrived before its request died.
		}
		if ( ! $ftp->append( $name, substr( $bytes, $have ) ) ) {
			/* translators: %s: server address */
			throw new TransientFailure( sprintf( __( '%s did not accept the part (it may be full).', 'lw-migration' ), $where ), TransientFailure::SERVER );
		}
	}

	/**
	 * The file's size; a server that does not tell it cannot hold an upload.
	 *
	 * @param FtpConnection $ftp   Connection in the folder.
	 * @param string        $name  The part file.
	 * @param string        $where For messages.
	 * @throws PermanentFailure INVALID when SIZE answers nothing.
	 */
	private static function size( FtpConnection $ftp, string $name, string $where ): int {
		$size = $ftp->size( $name );
		if ( -1 === $size ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s does not tell file sizes (SIZE), so uploads cannot be checked or continued there.', 'lw-migration' ), $where ), PermanentFailure::INVALID );
		}

		return $size;
	}
}
