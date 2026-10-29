<?php
/**
 * Backups in this site's folder inside the app folder of the connected Dropbox.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5/21.10, plan E9–E11, E22, E23 with preflight P-I3, P-I4, P-I6.
 * The app has App folder access, so "/" is its own folder; each site keeps
 * its backups in "/<site>" under it (created by Dropbox with the first
 * upload; the app folder's root is never listed). Uploads go through an upload session
 * (DropboxUpload: 4 MiB-multiple parts growing to 128 MiB, a lost answer
 * adopted from Dropbox's offset, mode add so no file is ever replaced).
 * Only backups and the test file are listed, read or deleted, and only by
 * a Dropbox file id. abort() sends nothing: no cancel call is documented,
 * and Dropbox forgets a session after 7 days.
 */
final class DropboxDestination implements DestinationInterface {

	public const TYPE = 'dropbox';

	public const MULTIPLE = ContentHash::BLOCK;

	public const ID_PATTERN = '/^id:[A-Za-z0-9_-]{1,200}\z/';

	/** Slowest rate a range read is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	private const PAGES = 50;

	/**
	 * Request shapes.
	 *
	 * @var DropboxApi
	 */
	private DropboxApi $api;

	/**
	 * The upload session protocol.
	 *
	 * @var DropboxUpload
	 */
	private DropboxUpload $upload;

	/**
	 * This site's folder name (one path segment under the app folder).
	 *
	 * @var string
	 */
	private string $folder;

	/**
	 * @param OAuthHttp       $http   Authorized requests.
	 * @param string          $folder This site's folder; empty or not a slug means this site's name now, never the app folder's root.
	 * @param ChunkSizer|null $sizer  Part sizes (default 4 MiB growing to 128 MiB).
	 */
	public function __construct( OAuthHttp $http, string $folder = '', ?ChunkSizer $sizer = null ) {
		$this->folder = SiteFolder::of( [ 'folder' => $folder ] );
		$this->api    = new DropboxApi( $http );
		$this->upload = new DropboxUpload( $this->api, $sizer ?? new ChunkSizer( self::MULTIPLE, DropboxUpload::MAX, self::MULTIPLE ), $this->folder );
	}

	public function test(): void {
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		if ( ! self::isFileName( $name ) || $size < 1 ) {
			throw new \LogicException( 'Only backups and the test file are uploaded, with at least one byte.' );
		}

		return $this->upload->start( $name, $size );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		return $this->upload->chunk( $s, $bytes );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		return $this->upload->finish( $s );
	}

	public function list(): array {
		$files  = [];
		$answer = $this->api->rpc(
			'files/list_folder',
			[
				'path'            => '/' . $this->folder,
				'recursive'       => false,
				'include_deleted' => false,
				'limit'           => 1000,
			]
		);
		for ( $page = 0; $page < self::PAGES; $page++ ) {
			if ( 0 === $page && 409 === $answer['status'] && in_array( 'not_found', DropboxErrors::tags( $answer['body'] ), true ) ) {
				return []; // The site's folder appears with the first upload.
			}
			if ( 200 !== $answer['status'] ) {
				throw $this->failure( $answer ); // A later page's not_found too: never a cut listing (Task 9 m4).
			}
			$json = $this->api->http()->json( $answer );
			foreach ( (array) ( $json['entries'] ?? [] ) as $entry ) {
				$file = is_array( $entry ) && 'file' === ( $entry['.tag'] ?? null ) ? self::file( $entry, $this->folder ) : null;
				if ( null !== $file ) {
					$files[] = $file;
				}
			}
			if ( true !== ( $json['has_more'] ?? false ) ) {
				return $files;
			}
			$cursor = $json['cursor'] ?? null;
			if ( ! is_string( $cursor ) || 1 !== preg_match( '/^[\x21-\x7E]{1,4096}\z/', $cursor ) ) {
				throw self::cutListing();
			}
			if ( $page + 1 < self::PAGES ) {
				$answer = $this->api->rpc( 'files/list_folder/continue', [ 'cursor' => $cursor ] );
			}
		}

		throw self::cutListing();
	}

	/**
	 * Task 9 m4: a listing that cannot be read to its end is refused, not cut
	 * short (retention and restore lookups rely on it being whole).
	 */
	private static function cutListing(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s did not list the whole folder.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::INVALID );
	}

	public function delete( RemoteFile $f ): void {
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not deleted.', 'lw-migration' ) );
		$answer = $this->api->rpc( 'files/delete_v2', [ 'path' => $f->ref ] );
		if ( 200 === $answer['status'] || ( 409 === $answer['status'] && in_array( 'not_found', DropboxErrors::tags( $answer['body'] ), true ) ) ) {
			return;
		}

		throw $this->failure( $answer );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 ) {
			throw new \LogicException( 'A range has a start and at least one byte.' );
		}
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not read.', 'lw-migration' ) );
		$answer = $this->api->content(
			'files/download',
			[ 'path' => $f->ref ],
			'',
			[ 'Range' => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ) ],
			[
				'timeout' => CloudTimeout::forBytes( $length, self::RATE ),
				'limit'   => $length,
			],
			false
		);
		if ( ! in_array( $answer['status'], [ 200, 206 ], true ) ) {
			throw $this->failure( $answer );
		}
		$range = $answer['headers']['content-range'] ?? null;
		$start = null === $range ? $offset : ( 1 === preg_match( '/^bytes (\d{1,18})-\d{1,18}\/(?:\d{1,18}|\*)\z/', $range, $m ) ? (int) $m[1] : -1 );
		if ( ( 200 === $answer['status'] && $offset > 0 ) || $start !== $offset ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s sent another part of the file than asked for.', 'lw-migration' ), DropboxErrors::LABEL ), PermanentFailure::INVALID, $answer['status'] );
		}
		if ( strlen( $answer['body'] ) !== $length ) {
			/* translators: %s: provider name */
			throw new TransientFailure( sprintf( __( '%s sent fewer bytes than asked for.', 'lw-migration' ), DropboxErrors::LABEL ), TransientFailure::SERVER, $answer['status'] );
		}

		return $answer['body'];
	}

	public function abort( UploadSession $s ): void {
		unset( $s ); // No cancel call is documented; Dropbox forgets a session after 7 days (E23).
	}

	public static function isFileName( string $name ): bool {
		return BackupName::isValid( $name ) || ProbeFile::is( $name );
	}

	/**
	 * A backup or the test file in the site's folder, by its id (P-I4). An
	 * entry that says where it is (path_lower) must be in that folder.
	 *
	 * @param array<string, mixed> $meta   FileMetadata.
	 * @param string               $folder The site's folder.
	 */
	public static function file( array $meta, string $folder ): ?RemoteFile {
		$name = $meta['name'] ?? null;
		$id   = $meta['id'] ?? null;
		$size = $meta['size'] ?? null;
		if ( ! is_string( $name ) || ! self::isFileName( $name ) || ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || ! is_int( $size ) || $size < 0 ) {
			return null; // A folder, another app's file or a malformed entry.
		}
		if ( array_key_exists( 'path_lower', $meta ) && ( ! is_string( $meta['path_lower'] ) || '/' . strtolower( $folder ) . '/' . strtolower( $name ) !== $meta['path_lower'] ) ) {
			return null; // Another folder of the app.
		}

		return new RemoteFile( $name, $size, max( 0, (int) strtotime( (string) ( $meta['server_modified'] ?? '' ) ) ), $id );
	}

	/**
	 * @param RemoteFile $f    File.
	 * @param string     $text Refusal.
	 * @throws PermanentFailure INVALID unless a backup or the test file with a Dropbox id.
	 */
	private static function assertFile( RemoteFile $f, string $text ): void {
		if ( ! self::isFileName( $f->name ) || 1 !== preg_match( self::ID_PATTERN, $f->ref ) ) {
			throw new PermanentFailure( $text, PermanentFailure::INVALID );
		}
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer.
	 */
	private function failure( array $answer ): CloudFailure {
		return DropboxErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->api->http()->now() );
	}
}
