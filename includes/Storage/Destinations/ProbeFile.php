<?php
/**
 * The connection test of every destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.6 ("kis tesztfájl írása, visszaolvasás, törlés"): a small text
 * file named lw-migration-test-<16 hex>.txt — never a backup name, so no
 * listing or retention takes it for one — is uploaded through the normal
 * session (one part), read back, compared and deleted. It is read back from
 * its second byte on, as backups are read in ranges: a server that can send
 * a file only from its start (FTP without REST, WebDAV ignoring Range) fails
 * here, not at the first restore. A failed upload is aborted; the file is
 * deleted also when the read fails.
 */
final class ProbeFile {

	public const PREFIX = 'lw-migration-test-';

	private const PATTERN = '/^lw-migration-test-[a-f0-9]{16}\.txt\z/';

	public static function name(): string {
		return self::PREFIX . bin2hex( random_bytes( 8 ) ) . '.txt';
	}

	public static function is( string $name ): bool {
		return 1 === preg_match( self::PATTERN, $name );
	}

	/**
	 * @param DestinationInterface $destination Destination.
	 * @param string|null          $name        Test file name (default: a new one).
	 * @throws CloudFailure The first failure; INVALID (PermanentFailure) when the bytes read back differ.
	 */
	public static function check( DestinationInterface $destination, ?string $name = null ): void {
		$name    = $name ?? self::name();
		$body    = 'LW Migration connection test ' . $name . "\n";
		$session = $destination->startUpload( $name, strlen( $body ) );
		$file    = self::upload( $destination, $session, $body );
		$first   = null;
		try {
			$back = $destination->readRange( $file, 1, strlen( $body ) - 1 );
			if ( substr( $body, 1 ) !== $back ) {
				$first = new PermanentFailure( __( 'The test file read back from the destination differs from what was written.', 'lw-migration' ), PermanentFailure::INVALID );
			}
		} catch ( CloudFailure $e ) {
			$first = $e;
		}
		try {
			$destination->delete( $file );
		} catch ( CloudFailure $e ) {
			$first = $first ?? $e; // An earlier failure is the one to report.
		}
		if ( null !== $first ) {
			throw $first;
		}
	}

	/**
	 * Uploads the body in one part. A failure aborts the session; a failed
	 * finish may still have left the object, so it is looked up and deleted.
	 *
	 * @param DestinationInterface $destination Destination.
	 * @param UploadSession        $session     Started session.
	 * @param string               $body        Test bytes.
	 * @throws \Throwable The first failure of the upload.
	 */
	private static function upload( DestinationInterface $destination, UploadSession $session, string $body ): RemoteFile {
		$finishing = false;
		try {
			$done      = $destination->uploadChunk( $session, $body );
			$finishing = true;

			return $destination->finishUpload( $done );
		} catch ( \Throwable $e ) {
			self::quietly( static fn() => $destination->abort( $session ) );
			if ( $finishing ) {
				self::quietly(
					static function () use ( $destination, $session ): void {
						foreach ( $destination->list() as $remote ) {
							if ( $remote->name === $session->name ) {
								$destination->delete( $remote );
							}
						}
					}
				);
			}
			throw $e;
		}
	}

	/**
	 * Runs a cleanup step whose failure must not hide the first failure.
	 *
	 * @param callable $step Cleanup.
	 */
	private static function quietly( callable $step ): void {
		try {
			$step();
		} catch ( \Throwable $ignored ) {
			unset( $ignored ); // The first failure is the one to report.
		}
	}
}
