<?php
/**
 * Backups in the connected Google Drive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\GoogleDrive;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\ResumesUpload;
use LightweightPlugins\Migration\Storage\Destinations\SiteFolder;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.5/21.10, plan E9–E12, E23 with preflight P-I3–P-I6. Uploads go
 * through Google's resumable session (DriveUpload: 256 KiB-multiple chunks
 * growing while fast, the status query on resume, a gone session starts
 * over once) into this site's folder (DriveFolder, found or created
 * once more when the admin deleted it, its new id remembered). The final
 * answer carries the file id, kept in the session, so finishUpload() needs
 * no request; a final answer that got lost is found by the status query.
 * abort() sends nothing: no cancel call is documented, sessions expire
 * after a week.
 *
 * Never a second file of one name (M7b H13): Drive keeps files of the same
 * name side by side, so before a session starts the folder is searched for
 * the name — a file of the backup's size carrying this site's mark for it
 * (DriveUpload::marker(), written when the upload started) is this backup
 * (an upload whose final answer got lost before the session was started
 * over) and is taken as the upload; any other is refused. Only files the app
 * created are visible (drive.file) and backup names are unique per backup,
 * so two of one name can come only from two uploads of the same backup at
 * the same moment (residual); list() returns each with its own id, so
 * retention deletes both. Only backups and the test file are listed, read
 * or deleted, and only by a Drive id.
 */
final class GoogleDriveDestination implements DestinationInterface, ResumesUpload {

	public const TYPE = 'gdrive';

	public const FILES = 'https://www.googleapis.com/drive/v3/files';

	public const UPLOAD = DriveUpload::UPLOAD;

	public const MULTIPLE = DriveUpload::MULTIPLE;

	public const CHUNK_AUTH = DriveUpload::CHUNK_AUTH;

	private const START = 2097152;

	/** Slowest rate a range read is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	private const PAGES = 50;

	/**
	 * The upload session protocol.
	 *
	 * @var DriveUpload
	 */
	private DriveUpload $upload;

	/**
	 * @param OAuthHttp       $http          Authorized requests.
	 * @param string          $folderId      The site's folder ('' or not a Drive id: find or create it).
	 * @param \Closure        $folderChanged fn( string $id ): void — remembers a new folder id.
	 * @param ChunkSizer|null $sizer         Chunk sizes (default 2 MiB growing to 64 MiB).
	 * @param string          $site          The site's folder name ('' is this site's name now).
	 */
	public function __construct( private OAuthHttp $http, private string $folderId, private \Closure $folderChanged, ?ChunkSizer $sizer = null, private string $site = '' ) {
		$this->site     = '' === $site ? SiteFolder::name() : $site;
		$this->folderId = 1 === preg_match( DriveFolder::ID_PATTERN, $folderId ) ? $folderId : '';
		$this->upload   = new DriveUpload( $http, $sizer ?? new ChunkSizer( self::START, DriveUpload::MAX, self::MULTIPLE ) );
	}

	public function test(): void {
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		if ( ! DriveUpload::isFileName( $name ) || $size < 1 ) {
			throw new \LogicException( 'Only backups and the test file are uploaded, with at least one byte.' );
		}
		if ( '' === $this->folderId ) {
			$this->newFolder();
		}
		$session = $this->begin( $name, $size );
		if ( null === $session ) {
			$this->newFolder(); // The admin deleted the folder: find or create it once more.
			$session = $this->begin( $name, $size );
		}
		if ( null === $session ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'This site\'s folder at %s is gone.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::MISSING, 404 );
		}

		return $session;
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		return $this->upload->chunk( $s, $bytes );
	}

	public function resume( UploadSession $s ): UploadSession {
		return $this->upload->status( $s );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		$this->upload->assertOwn( $s );
		$id = (string) $s->extra['fileId'];
		if ( '' === $id && $s->isComplete() ) {
			$id = (string) $this->upload->status( $s )->extra['fileId'];
		}
		if ( '' === $id ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s did not confirm the uploaded file; the upload starts over.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::SESSION_GONE );
		}

		return new RemoteFile( $s->name, $s->size, $this->http->now(), $id );
	}

	public function list(): array {
		$files = [];
		$token = '';
		if ( '' === $this->folderId ) {
			return [];
		}
		for ( $page = 0; $page < self::PAGES; $page++ ) {
			$json = $this->search( "'" . $this->folderId . "' in parents and trashed = false", 'nextPageToken,files(id,name,size,modifiedTime)', 1000, $token );
			if ( null === $json && 0 === $page ) {
				return []; // The folder is gone: no backups there.
			}
			if ( null === $json ) {
				throw self::cutListing(); // Gone between two pages: never a cut listing (Task 9 m4).
			}
			foreach ( (array) ( $json['files'] ?? [] ) as $item ) {
				$file = is_array( $item ) ? self::file( $item ) : null;
				if ( null !== $file ) {
					$files[] = $file;
				}
			}
			$token = $json['nextPageToken'] ?? null;
			if ( null === $token || '' === $token ) {
				return $files; // No nextPageToken: the last page.
			}
			if ( ! is_string( $token ) || 1 !== preg_match( '/^[\x21-\x7E]{1,2048}\z/', $token ) ) {
				throw self::cutListing();
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
		return new PermanentFailure( sprintf( __( '%s did not list the whole folder.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
	}

	public function delete( RemoteFile $f ): void {
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not deleted.', 'lw-migration' ) );
		$answer = $this->http->request( 'DELETE', self::FILES . '/' . $f->ref );
		if ( ! in_array( $answer['status'], [ 200, 204, 404 ], true ) ) {
			throw $this->failure( $answer );
		}
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 ) {
			throw new \LogicException( 'A range has a start and at least one byte.' );
		}
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not read.', 'lw-migration' ) );
		$answer = $this->http->request(
			'GET',
			self::FILES . '/' . $f->ref . '?alt=media',
			[ 'Range' => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ) ],
			'',
			[
				'timeout' => CloudTimeout::forBytes( $length, self::RATE ),
				'limit'   => $length,
			]
		);
		if ( ! in_array( $answer['status'], [ 200, 206 ], true ) ) {
			throw $this->failure( $answer );
		}
		$range = $answer['headers']['content-range'] ?? null;
		$start = null === $range ? $offset : ( 1 === preg_match( '/^bytes (\d{1,18})-\d{1,18}\/(?:\d{1,18}|\*)\z/', $range, $m ) ? (int) $m[1] : -1 ); // One that does not parse is no answer to the range (Task 8 m3).
		if ( ( 200 === $answer['status'] && $offset > 0 ) || $start !== $offset ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s sent another part of the file than asked for.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID, $answer['status'] );
		}
		if ( strlen( $answer['body'] ) !== $length ) {
			/* translators: %s: provider name */
			throw new TransientFailure( sprintf( __( '%s sent fewer bytes than asked for.', 'lw-migration' ), DriveErrors::LABEL ), TransientFailure::SERVER, $answer['status'] );
		}

		return $answer['body'];
	}

	public function abort( UploadSession $s ): void {
		unset( $s ); // No cancel call is documented; Google forgets the session after a week (E23).
	}

	/**
	 * The upload of a name in the current folder.
	 *
	 * @param string $name Backup or test file name.
	 * @param int    $size Bytes.
	 * @return UploadSession|null Null when the folder is gone.
	 * @throws CloudFailure When Drive refuses, gives a foreign address, or another file has the name.
	 */
	private function begin( string $name, int $size ): ?UploadSession {
		$q    = "name = '" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $name ) . "' and '" . $this->folderId . "' in parents and trashed = false";
		$json = $this->search( $q, 'files(id,size,appProperties)', 10, '' );

		return null === $json ? null : $this->upload->start( $name, $size, $this->folderId, (array) ( $json['files'] ?? [] ) );
	}

	/**
	 * One page of files.list.
	 *
	 * @param string $q      Query.
	 * @param string $fields Fields.
	 * @param int    $size   Page size.
	 * @param string $token  Page token ('' = the first page).
	 * @return array<string, mixed>|null The answer, null when the folder is gone (404).
	 * @throws CloudFailure When Drive refuses.
	 */
	private function search( string $q, string $fields, int $size, string $token ): ?array {
		$query  = [
			'q'        => $q,
			'fields'   => $fields,
			'pageSize' => $size,
			'spaces'   => 'drive',
		] + ( '' === $token ? [] : [ 'pageToken' => $token ] );
		$answer = $this->http->request( 'GET', self::FILES . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		if ( 404 === $answer['status'] ) {
			return null;
		}
		if ( 200 !== $answer['status'] ) {
			throw $this->failure( $answer );
		}

		return $this->http->json( $answer );
	}

	/**
	 * @param RemoteFile $f    File.
	 * @param string     $text Refusal.
	 * @throws PermanentFailure INVALID unless a backup or the test file with a Drive id.
	 */
	private static function assertFile( RemoteFile $f, string $text ): void {
		if ( ! DriveUpload::isFileName( $f->name ) || 1 !== preg_match( DriveFolder::ID_PATTERN, $f->ref ) ) {
			throw new PermanentFailure( $text, PermanentFailure::INVALID );
		}
	}

	/**
	 * @param array<string, mixed> $item A files.list entry.
	 */
	private static function file( array $item ): ?RemoteFile {
		$name = $item['name'] ?? null;
		$id   = $item['id'] ?? null;
		$size = $item['size'] ?? null;
		if ( ! is_string( $name ) || ! DriveUpload::isFileName( $name ) || ! is_string( $id ) || 1 !== preg_match( DriveFolder::ID_PATTERN, $id ) || ! is_string( $size ) || 1 !== preg_match( '/^\d{1,18}\z/', $size ) ) {
			return null; // A folder, a part or another app's file (P-I4).
		}

		return new RemoteFile( $name, (int) $size, max( 0, (int) strtotime( (string) ( $item['modifiedTime'] ?? '' ) ) ), $id );
	}

	private function newFolder(): void {
		$this->folderId = DriveFolder::ensure( $this->http, $this->site );
		( $this->folderChanged )( $this->folderId );
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer to an authorized request.
	 */
	private function failure( array $answer ): CloudFailure {
		return DriveErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now() );
	}
}
