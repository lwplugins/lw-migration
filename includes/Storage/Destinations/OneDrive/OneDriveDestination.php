<?php
/**
 * Backups in this site's folder inside the app folder of the connected OneDrive.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Backups\BackupName;
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
 * Spec 21.5/21.10, plan E9–E11, E13, E18, E23 with preflight P-C2,
 * P-I2–P-I6. Uploads go through an upload session in /drive/special/approot:/<site>
 * (OneDriveUpload: 320 KiB-multiple fragments from 5 MiB growing to 30 MiB,
 * always under 60 MiB; the status query on resume; never a second or
 * replaced file of one name). Downloads ask /items/{id}/content for its 302
 * Location (an https address of a DNS name, read once per range) and ask
 * that address for the range, without Authorization. abort() cancels the
 * session (DELETE uploadUrl). Only backups and the test file are listed,
 * read or deleted, and only by a drive item id; a listing that cannot be
 * read to its end (a next link off Graph, more pages than the cap) is
 * refused, not cut short.
 */
final class OneDriveDestination implements DestinationInterface, ResumesUpload {

	public const TYPE = 'onedrive';

	public const DRIVE = 'https://graph.microsoft.com/v1.0/me/drive';

	public const MULTIPLE = OneDriveUpload::MULTIPLE;

	/** Drive item ids (OneDrive for home "D4648F06C91D9D3D!54927", for work or school base32-like; lenient, but never only dots — review m1). */
	public const ID_PATTERN = '/^(?!\.+\z)[A-Za-z0-9!._-]{1,200}\z/';

	private const START = 5242880;

	private const PAGES = 50;

	private const NEXT_PREFIX = 'https://graph.microsoft.com/v1.0/';

	/** Slowest rate a range read is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	/**
	 * The upload session protocol.
	 *
	 * @var OneDriveUpload
	 */
	private OneDriveUpload $upload;

	/**
	 * This site's folder name (one path segment under the app folder).
	 *
	 * @var string
	 */
	private string $folder;

	/**
	 * @param OAuthHttp       $http   Authorized requests.
	 * @param string          $folder This site's folder; empty or not a slug means this site's name now, never the app folder's root.
	 * @param ChunkSizer|null $sizer  Fragment sizes (default 5 MiB growing to 30 MiB, 320 KiB multiples).
	 */
	public function __construct( private OAuthHttp $http, string $folder = '', ?ChunkSizer $sizer = null ) {
		$this->folder = SiteFolder::of( [ 'folder' => $folder ] );
		$this->upload = new OneDriveUpload( $http, $sizer ?? new ChunkSizer( self::START, OneDriveUpload::MAX, self::MULTIPLE ), $this->folder );
	}

	/**
	 * The address of a file of the site's folder, by path (Graph path addressing).
	 *
	 * @param string $folder The site's folder.
	 * @param string $name   File name.
	 */
	public static function filePath( string $folder, string $name ): string {
		return self::DRIVE . '/special/approot:/' . rawurlencode( $folder ) . '/' . rawurlencode( $name );
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

	public function resume( UploadSession $s ): UploadSession {
		return $this->upload->status( $s );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		UploadRecord::assertOwn( $s );
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Only a complete upload is finished.' );
		}

		return new RemoteFile( $s->name, $s->size, $this->http->now(), $this->upload->itemId( $s ) );
	}

	public function list(): array {
		$files = [];
		$url   = self::DRIVE . '/special/approot:/' . rawurlencode( $this->folder ) . ':/children?$select=id,name,size,lastModifiedDateTime,file&$top=200';
		for ( $page = 0; $page < self::PAGES; $page++ ) {
			$answer = $this->http->request( 'GET', $url );
			if ( 0 === $page && 404 === $answer['status'] && 'itemNotFound' === ( json_decode( $answer['body'], true )['error']['code'] ?? null ) ) {
				return []; // The site's folder appears with the first upload.
			}
			if ( 200 !== $answer['status'] ) {
				throw $this->failure( $answer ); // A later page too: never a cut listing.
			}
			$json = $this->http->json( $answer );
			foreach ( (array) ( $json['value'] ?? [] ) as $item ) {
				$file = is_array( $item ) ? self::file( $item ) : null;
				if ( null !== $file ) {
					$files[] = $file;
				}
			}
			$next = $json['@odata.nextLink'] ?? null;
			if ( null === $next ) {
				return $files;
			}
			if ( ! is_string( $next ) || ! str_starts_with( $next, self::NEXT_PREFIX ) || 1 === preg_match( '/[\x00-\x20\x7F#]/', $next ) || strlen( $next ) > 4096 ) {
				throw self::cutListing(); // P-C2: a next link is followed only on Graph.
			}
			$url = $next;
		}

		throw self::cutListing();
	}

	public function delete( RemoteFile $f ): void {
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not deleted.', 'lw-migration' ) );
		$answer = $this->http->request( 'DELETE', self::DRIVE . '/items/' . $f->ref );
		if ( ! in_array( $answer['status'], [ 200, 204, 404 ], true ) ) {
			throw $this->failure( $answer );
		}
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 ) {
			throw new \LogicException( 'A range has a start and at least one byte.' );
		}
		self::assertFile( $f, __( 'This file is not a backup in this site\'s folder; it was not read.', 'lw-migration' ) );
		$redirect = $this->http->request( 'GET', self::DRIVE . '/items/' . $f->ref . '/content' );
		if ( 302 !== $redirect['status'] ) {
			throw $this->failure( $redirect );
		}
		$location = $redirect['headers']['location'] ?? '';
		if ( ! UploadRecord::isProviderAddress( $location ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s gave an address this site does not send requests to.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID );
		}
		$answer = $this->http->request(
			'GET',
			$location,
			[ 'Range' => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ) ],
			'',
			[
				'auth'    => false,
				'timeout' => CloudTimeout::forBytes( $length, self::RATE ),
				'limit'   => $length,
			]
		);
		if ( ! in_array( $answer['status'], [ 200, 206 ], true ) ) {
			throw GraphErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now(), GraphErrors::DOWNLOAD );
		}
		$range = $answer['headers']['content-range'] ?? null;
		$start = null === $range ? $offset : ( 1 === preg_match( '/^bytes (\d{1,18})-\d{1,18}\/(?:\d{1,18}|\*)\z/', $range, $m ) ? (int) $m[1] : -1 );
		if ( ( 200 === $answer['status'] && $offset > 0 ) || $start !== $offset ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s sent another part of the file than asked for.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID, $answer['status'] );
		}
		if ( strlen( $answer['body'] ) !== $length ) {
			/* translators: %s: provider name */
			throw new TransientFailure( sprintf( __( '%s sent fewer bytes than asked for.', 'lw-migration' ), GraphErrors::LABEL ), TransientFailure::SERVER, $answer['status'] );
		}

		return $answer['body'];
	}

	public function abort( UploadSession $s ): void {
		try {
			UploadRecord::assertOwn( $s );
		} catch ( PermanentFailure $e ) {
			return; // Not this destination's upload: nothing to cancel here (H6).
		}
		if ( $s->isComplete() ) {
			return; // Nothing left to cancel.
		}
		$answer = $this->http->request( 'DELETE', $s->remote, [], '', [ 'auth' => false ] );
		if ( ! in_array( $answer['status'], [ 200, 204, 404, 410 ], true ) ) {
			throw GraphErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now(), GraphErrors::UPLOAD );
		}
	}

	public static function isFileName( string $name ): bool {
		return BackupName::isValid( $name ) || ProbeFile::is( $name );
	}

	/**
	 * A backup or the test file in the site's folder, by its id (P-I4).
	 *
	 * @param array<string, mixed> $item A children entry.
	 */
	private static function file( array $item ): ?RemoteFile {
		$name = $item['name'] ?? null;
		$id   = $item['id'] ?? null;
		$size = $item['size'] ?? null;
		if ( ! is_array( $item['file'] ?? null ) || ! is_string( $name ) || ! self::isFileName( $name ) || ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || ! is_int( $size ) || $size < 0 ) {
			return null; // A folder, another app's file or a malformed entry.
		}

		return new RemoteFile( $name, $size, max( 0, (int) strtotime( (string) ( $item['lastModifiedDateTime'] ?? '' ) ) ), $id );
	}

	/**
	 * @param RemoteFile $f    File.
	 * @param string     $text Refusal.
	 * @throws PermanentFailure INVALID unless a backup or the test file with a drive item id.
	 */
	private static function assertFile( RemoteFile $f, string $text ): void {
		if ( ! self::isFileName( $f->name ) || 1 !== preg_match( self::ID_PATTERN, $f->ref ) ) {
			throw new PermanentFailure( $text, PermanentFailure::INVALID );
		}
	}

	/**
	 * A listing that cannot be read to its end is refused, not cut short
	 * (retention and restore lookups rely on it being whole; Task 9 m4).
	 */
	private static function cutListing(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s did not list the whole folder.', 'lw-migration' ), GraphErrors::LABEL ), PermanentFailure::INVALID );
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer to an authorized request.
	 */
	private function failure( array $answer ): CloudFailure {
		return GraphErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now() );
	}
}
