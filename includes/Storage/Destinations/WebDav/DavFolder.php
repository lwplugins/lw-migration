<?php
/**
 * The destination's folder on a WebDAV server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * The folder's three operations. ensure(): MKCOL for each level of the folder (405 = it exists). files():
 * PROPFIND Depth 1, only .wpress names and the probe file directly in it —
 * an entry whose parent is not the folder adds nothing (preflight P-M13), a
 * part file (".lwm-part", or any other name) never counts (plan GC
 * "Partial files"); a folder that does not
 * exist yet has no files. sizeOf(): PROPFIND Depth 0 of one file, read only
 * from the entry for that file. isPlaced(): whether a refused MOVE's target
 * is the upload.
 */
final class DavFolder {

	private const SMALL = 65536;

	public function __construct( private WebDavClient $client, private WebDavAddress $address ) {
	}

	/**
	 * @throws PermanentFailure When a level cannot be created (a passing failure is thrown as it is).
	 */
	public function ensure(): void {
		foreach ( $this->address->folderChain() as $url ) {
			try {
				$this->client->request( 'MKCOL', $url, [], '', 0, self::SMALL );
			} catch ( PermanentFailure $e ) {
				if ( 405 !== $e->status ) {
					throw $e;
				}
			}
		}
	}

	/**
	 * @return array<int, RemoteFile>
	 * @throws PermanentFailure When the folder cannot be listed (a passing failure is thrown as it is).
	 */
	public function files(): array {
		$folder = self::path( $this->address->folderUrl() );
		try {
			$answer = $this->propfind( $this->address->folderUrl(), '1' );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::MISSING === $e->reason ) {
				return [];
			}
			throw $e;
		}
		$files = [];
		foreach ( $this->entries( $answer ) as $entry ) {
			$parent = substr( $entry['path'], 0, (int) strrpos( $entry['path'], '/' ) );
			if ( ! $entry['folder'] && $parent === $folder && ( BackupName::isValid( $entry['name'] ) || ProbeFile::is( $entry['name'] ) ) ) {
				$files[] = new RemoteFile( $entry['name'], $entry['size'], $entry['mtime'], $entry['name'] );
			}
		}

		return $files;
	}

	/**
	 * @param string $name File name in the folder.
	 * @return int Bytes, -1 when there is no such file.
	 * @throws PermanentFailure When the server refuses otherwise (a passing failure is thrown as it is).
	 */
	public function sizeOf( string $name ): int {
		$url = $this->address->file( $name );
		try {
			$entries = $this->entries( $this->propfind( $url, '0' ) );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::MISSING === $e->reason ) {
				return -1;
			}
			throw $e;
		}
		foreach ( $entries as $entry ) {
			if ( ! $entry['folder'] && self::path( $url ) === $entry['path'] ) {
				return $entry['size'];
			}
		}

		return -1;
	}

	/**
	 * Final review I2: a MOVE never replaces a file (Overwrite: F). When it
	 * was refused because the name is taken (412), or found its source gone
	 * (404: a MOVE that arrived before its request died), the file under the
	 * name is this upload only when it has the upload's size — backup names
	 * are unique per backup (the rule of FolderDestination::place()).
	 *
	 * @param PermanentFailure $e    The MOVE's refusal.
	 * @param string           $name File name in the folder.
	 * @param int              $size The upload's bytes.
	 * @return bool Whether this upload is in place.
	 * @throws PermanentFailure INVALID when another file has the name (412).
	 */
	public function isPlaced( PermanentFailure $e, string $name, int $size ): bool {
		if ( 412 !== $e->status && PermanentFailure::MISSING !== $e->reason ) {
			return false;
		}
		if ( $this->sizeOf( $name ) === $size ) {
			return true;
		}
		if ( 412 === $e->status ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'Another file with this backup\'s name is already on %s; it was not replaced.', 'lw-migration' ), $this->client->where() ), PermanentFailure::INVALID, 412 );
		}

		return false;
	}

	/**
	 * The decoded path of a URL built here, without a trailing "/" (as DavXml gives entries).
	 *
	 * @param string $url Address.
	 */
	private static function path( string $url ): string {
		return rtrim( rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing.
	}

	/**
	 * @param string $url   Address.
	 * @param string $depth Depth header.
	 * @return array{status: int, body: string, length: int, range: string}
	 * @throws \LightweightPlugins\Migration\Storage\Destinations\CloudFailure When the server fails.
	 */
	private function propfind( string $url, string $depth ): array {
		return $this->client->request(
			'PROPFIND',
			$url,
			[
				'Depth'        => $depth,
				'Content-Type' => 'application/xml; charset=utf-8',
			],
			DavXml::PROPFIND
		);
	}

	/**
	 * @param array{status: int, body: string, length: int, range: string} $answer PROPFIND answer.
	 * @return array<int, array{path: string, name: string, size: int, mtime: int, folder: bool}>
	 * @throws TransientFailure When it cannot be read.
	 */
	private function entries( array $answer ): array {
		$entries = DavXml::entries( $answer['body'] );
		if ( null === $entries ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent a file list this site cannot read.', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
		}

		return $entries;
	}
}
