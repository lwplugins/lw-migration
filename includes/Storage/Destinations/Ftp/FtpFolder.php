<?php
/**
 * The destination's folder on an FTP server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * RemoteFolder over FtpConnection. files() asks SIZE/MDTM only for .wpress
 * names and the test file (MLSD is not common: vsftpd lacks it). ext-ftp's
 * failure of DELE is generic, so a refused delete is told from a missing
 * file (550: false) by SIZE; size() answers -1 for a missing file.
 */
final class FtpFolder implements RemoteFolder {

	/**
	 * @param FtpConnection $ftp   Connection in the folder.
	 * @param string        $mode  FtpPartWriter::REST or ::APPEND.
	 * @param string        $where ftp(s)://host:port for messages.
	 */
	public function __construct( private FtpConnection $ftp, private string $mode, private string $where ) {
	}

	/**
	 * Asks the server once (FEAT) how parts are written on this connection.
	 *
	 * @param FtpConnection $ftp   Connection in the folder.
	 * @param string        $where ftp(s)://host:port for messages.
	 */
	public static function open( FtpConnection $ftp, string $where ): self {
		return new self( $ftp, FtpPartWriter::mode( $ftp ), $where );
	}

	public function files(): array {
		$names = $this->ftp->names();
		if ( null === $names ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent a file list this site cannot read.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
		}
		$files = [];
		foreach ( $names as $name ) {
			if ( ! str_ends_with( $name, '.wpress' ) && ! ProbeFile::is( $name ) ) {
				continue;
			}
			$size = $this->ftp->size( $name );
			if ( $size >= 0 ) {
				$files[ $name ] = [
					'size'  => $size,
					'mtime' => max( 0, $this->ftp->mtime( $name ) ),
				];
			}
		}

		return $files;
	}

	public function size( string $name ): int {
		return $this->ftp->size( $name );
	}

	public function write( string $name, string $bytes, int $offset ): void {
		FtpPartWriter::write( $this->ftp, $this->mode, $name, $offset, $bytes, $this->where );
	}

	public function read( string $name, int $offset, int $length ): string {
		$bytes = $this->ftp->read( $name, $offset, $length );
		if ( null !== $bytes ) {
			return $bytes;
		}
		if ( -1 === $this->ftp->size( $name ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'The file does not exist at %s.', 'lw-migration' ), $this->where ), PermanentFailure::MISSING );
		}
		/* translators: %s: server address */
		throw new TransientFailure( sprintf( __( '%s did not send the file.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
	}

	public function rename( string $from, string $to ): void {
		if ( ! $this->ftp->rename( $from, $to ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s refused to rename the uploaded file.', 'lw-migration' ), $this->where ), PermanentFailure::DENIED );
		}
	}

	public function delete( string $name ): bool {
		if ( $this->ftp->delete( $name ) ) {
			return true;
		}
		if ( -1 === $this->ftp->size( $name ) ) {
			return false; // 550: it was not there.
		}
		/* translators: %s: server address */
		throw new PermanentFailure( sprintf( __( '%s refused to delete the file.', 'lw-migration' ), $this->where ), PermanentFailure::DENIED );
	}

	public function close(): void {
		$this->ftp->close();
	}
}
