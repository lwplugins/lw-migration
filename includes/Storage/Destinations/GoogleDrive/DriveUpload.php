<?php
/**
 * Google Drive's resumable upload session.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\GoogleDrive;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\ChunkSizer;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\OAuthHttp;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Plan E9–E11, E19 with preflight P-C2, P-I2, P-I3 (manage-uploads,
 * read 2026-10-12): start() opens a session (POST with the metadata, the
 * address comes back in Location) unless the folder already holds this
 * backup; chunk() sends one chunk with "bytes a-b/total"; status() is the
 * empty PUT with "bytes *\/total". A 308 carries what the server holds in
 * Range ("bytes=0-N"; none = nothing) — it may be less than was sent, but
 * never less than it had confirmed nor more than was sent; 200/201 with
 * the file resource ends the upload, and only after its last byte was
 * sent. The session address must be Google's upload address with an
 * upload id and is sent without Authorization (CHUNK_AUTH); a refusal
 * there is never REAUTH (DriveErrors). A stored session of another type,
 * address, chunk rule or extras starts over before any request.
 */
final class DriveUpload {

	public const MULTIPLE = 262144;

	public const CHUNK_AUTH = false;

	/** Largest chunk (E10). */
	public const MAX = 67108864;

	public const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';

	/** The appProperties key of this site's mark of a backup (Task 8 m1). */
	public const MARK = 'lwMigrationBackup';

	private const MARK_CONTEXT = 'lw-migration/drive-backup/v1';

	private const SESSION_PREFIX = 'https://www.googleapis.com/upload/drive/v3/files?';

	/** Slowest rate a chunk is given time for (bytes a second, P-M2). */
	private const RATE = 262144;

	public function __construct( private OAuthHttp $http, private ChunkSizer $sizer ) {
	}

	/**
	 * @param string                   $name     Backup or test file name.
	 * @param int                      $size     Bytes.
	 * @param string                   $folderId This site's folder.
	 * @param array<int|string, mixed> $named    The folder's files of this name (files.list entries).
	 * @return UploadSession|null A new session, the file already there (complete), or null when the folder is gone.
	 * @throws PermanentFailure INVALID when another file has the name or the answer lacks Google's upload address (Drive's own refusal, a CloudFailure, passes through).
	 */
	public function start( string $name, int $size, string $folderId, array $named ): ?UploadSession {
		$same = self::sameName( $named, self::marker( $name, $size ), $size );
		if ( '' !== $same ) {
			return new UploadSession( GoogleDriveDestination::TYPE, $name, $size, $size, $this->sizer->first(), GoogleDriveDestination::FILES . '/' . $same, [ 'fileId' => $same ] );
		}
		$answer = $this->http->request(
			'POST',
			self::UPLOAD,
			[
				'Content-Type'            => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'   => 'application/octet-stream',
				'X-Upload-Content-Length' => (string) $size,
			],
			(string) wp_json_encode(
				[
					'name'          => $name,
					'parents'       => [ $folderId ],
					'mimeType'      => 'application/octet-stream',
					'appProperties' => [ self::MARK => self::marker( $name, $size ) ],
				]
			)
		);
		if ( 404 === $answer['status'] ) {
			return null;
		}
		if ( 200 !== $answer['status'] ) {
			$failure = $this->failure( $answer, true );
			throw $failure;
		}
		$location = $answer['headers']['location'] ?? '';
		if ( ! self::isSessionAddress( $location ) ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( '%s answered without a usable upload address.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
		}

		return new UploadSession( GoogleDriveDestination::TYPE, $name, $size, 0, $this->sizer->first(), $location, [ 'fileId' => '' ] );
	}

	/**
	 * @param UploadSession $s     Session of ours.
	 * @param string        $bytes The next chunk (exactly nextLength()).
	 * @throws PermanentFailure INVALID for another length (nothing sent), SESSION_GONE for a session not ours or gone (Drive's own refusal, a CloudFailure, passes through).
	 */
	public function chunk( UploadSession $s, string $bytes ): UploadSession {
		$this->assertOwn( $s );
		$length = strlen( $bytes );
		if ( $length !== $s->nextLength() || $length < 1 ) {
			throw new PermanentFailure( __( 'A part of the backup had an unexpected length; it was not sent.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		if ( $length > $this->sizer->largest() ) { // Stored by a process with more free memory (Task 8 m4).
			$length = $this->sizer->largest();
			$bytes  = substr( $bytes, 0, $length );
		}
		$started = $this->sizer->clock();
		$answer  = $this->put( $s, sprintf( 'bytes %d-%d/%d', $s->offset, $s->offset + $length - 1, $s->size ), $bytes );

		return $this->after( $s, $answer, $this->sizer->next( $length, $started ), $s->offset, $s->offset + $length );
	}

	/**
	 * @param UploadSession $s Session of ours.
	 * @throws PermanentFailure SESSION_GONE for a session not ours or gone, or an answer past what was sent.
	 * @throws CloudFailure When Drive refuses.
	 */
	public function status( UploadSession $s ): UploadSession {
		$this->assertOwn( $s );
		if ( $s->isComplete() && '' !== $s->extra['fileId'] ) {
			return $s;
		}

		return $this->after( $s, $this->put( $s, 'bytes */' . $s->size, '' ), $s->chunk, 0, min( $s->size, $s->offset + $s->nextLength() ) );
	}

	/**
	 * H6 / P-I3: a stored session of another type or provider, with a
	 * foreign address, a chunk that is not a whole multiple up to MAX, or
	 * other extras starts over before any request. A complete session may
	 * name the file already in the folder instead of a session address.
	 *
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not one of ours.
	 */
	public function assertOwn( UploadSession $s ): void {
		$id    = $s->extra['fileId'] ?? null;
		$extra = [ 'fileId' ] === array_keys( $s->extra ) && is_string( $id ) && ( '' === $id || 1 === preg_match( DriveFolder::ID_PATTERN, $id ) );
		$where = self::isSessionAddress( $s->remote ) || ( $extra && '' !== $id && $s->isComplete() && GoogleDriveDestination::FILES . '/' . $id === $s->remote );
		if ( GoogleDriveDestination::TYPE !== $s->type || ! self::isFileName( $s->name ) || $s->chunk < 1 || 0 !== $s->chunk % self::MULTIPLE || $s->chunk > self::MAX || ! $extra || ! $where ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::SESSION_GONE );
		}
	}

	public static function isFileName( string $name ): bool {
		return BackupName::isValid( $name ) || ProbeFile::is( $name );
	}

	/**
	 * Task 8 m1: this site's mark of a backup of this name and size, written
	 * into the file's appProperties when its upload starts, so a file found
	 * by name is taken as this backup only when this site made it for these
	 * bytes (keyed by the site's auth salt: another site cannot make it).
	 *
	 * @param string $name Backup or test file name.
	 * @param int    $size Bytes.
	 */
	public static function marker( string $name, int $size ): string {
		$key = hash_hmac( 'sha256', self::MARK_CONTEXT, wp_salt( 'auth' ), true );

		return substr( hash_hmac( 'sha256', $name . "\0" . $size, $key ), 0, 32 );
	}

	/**
	 * Never a second file of one name (M7b H13): a file of the name with the
	 * backup's size and this site's mark for it is this backup; any other is
	 * refused.
	 *
	 * @param array<int|string, mixed> $files  Files of the name.
	 * @param string                   $marker marker() of the backup.
	 * @param int                      $size   The backup's bytes.
	 * @return string The id of this backup's file ('' when there is none).
	 * @throws PermanentFailure INVALID when only other files have the name.
	 */
	private static function sameName( array $files, string $marker, int $size ): string {
		foreach ( $files as $item ) {
			$mark = is_array( $item ) && is_array( $item['appProperties'] ?? null ) ? ( $item['appProperties'][ self::MARK ] ?? null ) : null;
			if ( is_string( $mark ) && hash_equals( $marker, $mark ) && is_string( $item['id'] ?? null ) && 1 === preg_match( DriveFolder::ID_PATTERN, $item['id'] ) && ( $item['size'] ?? null ) === (string) $size ) {
				return $item['id'];
			}
		}
		if ( [] !== $files ) {
			/* translators: %s: provider name */
			throw new PermanentFailure( sprintf( __( 'Another file with this backup\'s name is already in this site\'s folder at %s; no second one was made.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
		}

		return '';
	}

	/**
	 * The status query or a chunk, to the session address, without Authorization.
	 *
	 * @param UploadSession $s     Session of ours.
	 * @param string        $range Content-Range.
	 * @param string        $body  Bytes.
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	private function put( UploadSession $s, string $range, string $body ): array {
		return $this->http->request(
			'PUT',
			$s->remote,
			[
				'Content-Range'  => $range,
				'Content-Length' => (string) strlen( $body ),
			],
			$body,
			[
				'auth'    => self::CHUNK_AUTH,
				'timeout' => CloudTimeout::forBytes( strlen( $body ), self::RATE ),
			]
		);
	}

	/**
	 * @param UploadSession                                                    $s      Session before.
	 * @param array{status: int, body: string, headers: array<string, string>} $answer Answer to a chunk or a status query.
	 * @param int                                                              $chunk  Next chunk size.
	 * @param int                                                              $min    Fewest bytes the server may hold now.
	 * @param int                                                              $max    Most bytes the server may hold now (what was sent).
	 * @throws PermanentFailure SESSION_GONE for an answer outside [min, max].
	 * @throws PermanentFailure SESSION_GONE for a finish before the last byte; INVALID for a finish without a file id; any other answer is classified (DriveErrors, a CloudFailure).
	 */
	private function after( UploadSession $s, array $answer, int $chunk, int $min, int $max ): UploadSession {
		if ( 308 === $answer['status'] ) {
			$range  = $answer['headers']['range'] ?? '';
			$offset = '' === $range ? 0 : ( 1 === preg_match( '/^bytes=0-(\d{1,15})\z/', $range, $m ) ? (int) $m[1] + 1 : -1 );
			if ( $offset < $min || $offset > $max ) {
				$failure = self::outOfOrder();
				throw $failure;
			}

			return new UploadSession( $s->type, $s->name, $s->size, $offset, $chunk, $s->remote, $s->extra );
		}
		if ( in_array( $answer['status'], [ 200, 201 ], true ) ) {
			$id = $this->http->json( $answer )['id'] ?? null;
			if ( $s->size !== $max ) {
				$failure = self::outOfOrder();
				throw $failure; // Finished before the last bytes were sent.
			}
			if ( ! is_string( $id ) || 1 !== preg_match( DriveFolder::ID_PATTERN, $id ) ) {
				/* translators: %s: provider name */
				throw new PermanentFailure( sprintf( __( '%s did not confirm the uploaded file.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::INVALID );
			}

			return new UploadSession( $s->type, $s->name, $s->size, $s->size, $chunk, $s->remote, [ 'fileId' => $id ] );
		}

		$failure = $this->failure( $answer, self::CHUNK_AUTH );
		throw $failure;
	}

	private static function outOfOrder(): PermanentFailure {
		/* translators: %s: provider name */
		return new PermanentFailure( sprintf( __( '%s answered this upload with a position it cannot have; the upload starts over.', 'lw-migration' ), DriveErrors::LABEL ), PermanentFailure::SESSION_GONE );
	}

	/**
	 * P-C2: Google's resumable upload address with an upload id.
	 *
	 * @param string $url Address.
	 */
	private static function isSessionAddress( #[\SensitiveParameter] string $url ): bool {
		return str_starts_with( $url, self::SESSION_PREFIX ) && strlen( $url ) <= 2048 && 1 !== preg_match( '/[\x00-\x20\x7F#]/', $url ) && 1 === preg_match( '/[?&]upload_id=[^&]+/', $url );
	}

	/**
	 * @param array{status: int, body: string, headers: array<string, string>} $answer     Answer.
	 * @param bool                                                             $authorized Whether the request carried the Bearer token.
	 */
	private function failure( array $answer, bool $authorized ): CloudFailure {
		return DriveErrors::classify( $answer['status'], $answer['body'], $answer['headers']['retry-after'] ?? '', $this->http->now(), $authorized );
	}
}
