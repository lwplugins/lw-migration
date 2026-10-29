<?php
/**
 * The destination's folder on an SFTP server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * RemoteFolder over SftpSession: names are joined to the folder ('' = the
 * login folder, "/…" = from the root); a refused delete is told from a
 * missing file by its size, and so is a refused read. Only backups and the
 * test file are listed. Messages name the server, never the folder.
 */
final class SftpFolder implements RemoteFolder {

	/**
	 * @param SftpSession $session Logged-in session.
	 * @param string      $folder  The folder.
	 * @param string      $where   sftp://host:port for messages.
	 */
	public function __construct( private SftpSession $session, private string $folder, private string $where ) {
	}

	public function files(): array {
		$files = $this->session->files( $this->folder );
		if ( null === $files ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent a file list this site cannot read.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
		}

		return array_filter( $files, static fn( $name ): bool => str_ends_with( (string) $name, '.wpress' ) || ProbeFile::is( (string) $name ), ARRAY_FILTER_USE_KEY );
	}

	public function size( string $name ): int {
		return $this->session->size( $this->path( $name ) );
	}

	public function write( string $name, string $bytes, int $offset ): void {
		if ( ! $this->session->write( $this->path( $name ), $bytes, $offset ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s refused to store the file (no permission, or the disk is full).', 'lw-migration' ), $this->where ), PermanentFailure::DENIED );
		}
	}

	public function read( string $name, int $offset, int $length ): string {
		$bytes = $this->session->read( $this->path( $name ), $offset, $length );
		if ( null !== $bytes ) {
			return $bytes;
		}
		if ( -1 === $this->size( $name ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'The file does not exist at %s.', 'lw-migration' ), $this->where ), PermanentFailure::MISSING );
		}
		/* translators: %s: server address */
		throw new TransientFailure( sprintf( __( '%s did not send the file.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
	}

	public function rename( string $from, string $to ): void {
		if ( ! $this->session->rename( $this->path( $from ), $this->path( $to ) ) ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( '%s refused to rename the uploaded file.', 'lw-migration' ), $this->where ), PermanentFailure::DENIED );
		}
	}

	public function delete( string $name ): bool {
		if ( $this->session->delete( $this->path( $name ) ) ) {
			return true;
		}
		if ( -1 === $this->size( $name ) ) {
			return false;
		}
		/* translators: %s: server address */
		throw new PermanentFailure( sprintf( __( '%s refused to delete the file.', 'lw-migration' ), $this->where ), PermanentFailure::DENIED );
	}

	public function close(): void {
		$this->session->close();
	}

	private function path( string $name ): string {
		return '' === $this->folder ? $name : rtrim( $this->folder, '/' ) . '/' . $name;
	}
}
