<?php
/**
 * An S3 (or compatible) bucket folder as a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\S3;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\ChunkPolicy;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use LightweightPlugins\Migration\Support\Memory;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4: multipart upload (Create / UploadPart / Complete / Abort) with
 * one fixed part size per upload (ChunkPolicy, R2), ListObjectsV2 of the
 * folder (delimiter "/": only files directly in it), DeleteObject, ranged
 * GetObject. Every object lives under "<prefix>/<name>"; delete() refuses a
 * reference outside it. A completion repeated after a request died once it
 * had succeeded meets NoSuchUpload: the object is then looked up (HEAD) and
 * accepted when its size matches. A part is read into memory (the WordPress
 * HTTP API takes a string), so the upload refuses to start when PHP's memory
 * left is below three parts (plan D16).
 */
final class S3Destination implements DestinationInterface {

	public const TYPE = 's3';

	private const LIST_PAGES = 50;

	/**
	 * Memory left: fn(): ?int.
	 *
	 * @var \Closure
	 */
	private \Closure $memory;

	/**
	 * @param S3Client      $client Signed client of the bucket.
	 * @param string        $prefix Folder in the bucket ('' = its root; no slashes at the ends).
	 * @param \Closure|null $memory fn(): ?int (default Memory::available()).
	 */
	public function __construct( private S3Client $client, private string $prefix, ?\Closure $memory = null ) {
		$this->memory = $memory ?? static fn(): ?int => Memory::available();
	}

	public function test(): void {
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		self::assertName( $name );
		if ( $size < 1 ) {
			throw new \LogicException( 'An upload has at least one byte.' );
		}
		if ( $size > ChunkPolicy::S3_MAX_PART * ChunkPolicy::S3_MAX_PARTS ) {
			/* translators: %s: backup size */
			throw new PermanentFailure( sprintf( __( 'A backup of %s is too large for S3 storage (at most 10 000 parts of 5 GiB).', 'lw-migration' ), size_format( $size ) ), PermanentFailure::TOO_LARGE );
		}
		$part = ChunkPolicy::s3PartSize( $size );
		$free = ( $this->memory )();
		if ( null !== $free && $free < 3 * min( $part, $size ) ) {
			/* translators: 1: part size, 2: memory left */
			throw new PermanentFailure( sprintf( __( 'This backup goes up in parts of %1$s, but PHP has only %2$s of memory left. Raise memory_limit, or upload it with wp lw-migration upload.', 'lw-migration' ), size_format( $part ), size_format( max( 0, $free ) ) ), PermanentFailure::TOO_LARGE );
		}
		$key    = $this->key( $name );
		$answer = $this->client->request( 'POST', $key, [ 'uploads' => '' ] );
		$id     = S3Xml::uploadId( $answer['body'] );
		if ( null === $id ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s did not start the upload (its answer has no upload id).', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
		}

		return new UploadSession(
			self::TYPE,
			$name,
			$size,
			0,
			$part,
			$id,
			[
				'key'   => $key,
				'parts' => [],
			]
		);
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		$this->assertOwn( $s );
		if ( $s->isComplete() || strlen( $bytes ) !== $s->nextLength() || 0 !== $s->offset % $s->chunk ) {
			throw new \LogicException( 'An S3 part starts on a part boundary and has exactly the part size of its upload (only the last may be smaller).' );
		}
		$key    = (string) ( $s->extra['key'] ?? '' );
		$number = intdiv( $s->offset, $s->chunk ) + 1;
		$answer = $this->client->request(
			'PUT',
			$key,
			[
				'partNumber' => (string) $number,
				'uploadId'   => $s->remote,
			],
			$bytes,
			[],
			self::timeout()
		);
		if ( '' === trim( $answer['etag'] ) ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s took a part without confirming it (no ETag).', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
		}
		$parts            = is_array( $s->extra['parts'] ?? null ) ? $s->extra['parts'] : [];
		$parts[ $number ] = $answer['etag'];

		return $s->with(
			$s->offset + strlen( $bytes ),
			[
				'key'   => $key,
				'parts' => $parts,
			]
		);
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Only a complete upload can be finished.' );
		}
		$this->assertOwn( $s );
		$key   = (string) ( $s->extra['key'] ?? '' );
		$parts = is_array( $s->extra['parts'] ?? null ) ? $s->extra['parts'] : [];
		if ( array_keys( $parts ) !== range( 1, intdiv( $s->size + $s->chunk - 1, $s->chunk ) ) ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( 'The record of the parts sent to %s is incomplete; the upload starts over.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE );
		}
		try {
			$answer = $this->client->request( 'POST', $key, [ 'uploadId' => $s->remote ], S3Xml::completeBody( $parts ), [ 'Content-Type' => 'application/xml' ], self::timeout() );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::SESSION_GONE !== $e->reason || $this->sizeOf( $key ) !== $s->size ) {
				throw $e;
			}

			return new RemoteFile( $s->name, $s->size, $this->client->now(), $key );
		}
		if ( ! S3Xml::isCompleted( $answer['body'] ) ) {
			// S3 may answer 200 with an error document (its code decides); a garbled answer is a passing failure.
			if ( null !== S3Xml::error( $answer['body'] ) ) {
				throw S3Errors::classify( 400, $answer['body'], '', $this->client->where(), $this->client->now() );
			}
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent an answer this site cannot read.', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
		}

		return new RemoteFile( $s->name, $s->size, $this->client->now(), $key );
	}

	public function list(): array {
		$prefix = '' === $this->prefix ? '' : $this->prefix . '/';
		$files  = [];
		$token  = null;
		for ( $page = 0; $page < self::LIST_PAGES; $page++ ) {
			$query = [
				'list-type' => '2',
				'delimiter' => '/',
				'prefix'    => $prefix,
			];
			if ( null !== $token ) {
				$query['continuation-token'] = $token;
			}
			$answer = $this->client->request( 'GET', '', $query );
			$read   = S3Xml::listPage( $answer['body'] );
			if ( null === $read ) {
				/* translators: %s: storage address */
				throw new TransientFailure( sprintf( __( '%s sent a file list this site cannot read.', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
			}
			foreach ( $read['files'] as $item ) {
				$name = (string) substr( $item['key'], strlen( $prefix ) );
				if ( str_starts_with( $item['key'], $prefix ) && '' !== $name && ! str_contains( $name, '/' ) && ! self::dotName( $name ) ) {
					$files[] = new RemoteFile( $name, $item['size'], $item['mtime'], $item['key'] );
				}
			}
			if ( ! $read['truncated'] ) {
				return $files;
			}
			if ( null === $read['next'] ) {
				throw $this->cutListing(); // More to read and no way to ask for it.
			}
			$token = $read['next'];
		}

		throw $this->cutListing(); // Never a cut listing: callers take it as the whole folder.
	}

	private function cutListing(): PermanentFailure {
		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( '%s did not list the whole folder.', 'lw-migration' ), $this->client->where() ), PermanentFailure::INVALID );
	}

	public function delete( RemoteFile $f ): void {
		if ( $f->ref !== $this->key( $f->name ) ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not deleted.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		try {
			$this->client->request( 'DELETE', $f->ref );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::MISSING !== $e->reason ) {
				throw $e;
			}
		}
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 ) {
			throw new \LogicException( 'A range has a start and at least one byte.' );
		}
		if ( $f->ref !== $this->key( $f->name ) ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not read.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$answer = $this->client->request( 'GET', $f->ref, [], '', [ 'Range' => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ) ], self::timeout(), $length );
		if ( 200 === $answer['status'] && $offset > 0 ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( '%s does not send parts of a file, so the download cannot continue.', 'lw-migration' ), $this->client->where() ), PermanentFailure::INVALID, 200 );
		}
		if ( 206 === $answer['status'] && 1 === preg_match( '/^bytes (\d+)-/', $answer['range'], $m ) && (int) $m[1] !== $offset ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( '%s sent another part of the file than asked for.', 'lw-migration' ), $this->client->where() ), PermanentFailure::INVALID, 206 );
		}
		if ( strlen( $answer['body'] ) !== $length ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent fewer bytes than asked for.', 'lw-migration' ), $this->client->where() ), TransientFailure::SERVER, $answer['status'] );
		}

		return $answer['body'];
	}

	public function abort( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			return; // Not this destination's upload: nothing to cancel here.
		}
		try {
			$this->client->request( 'DELETE', (string) ( $s->extra['key'] ?? $this->key( $s->name ) ), [ 'uploadId' => $s->remote ] );
		} catch ( PermanentFailure $e ) {
			if ( ! in_array( $e->reason, [ PermanentFailure::MISSING, PermanentFailure::SESSION_GONE ], true ) ) {
				throw $e;
			}
		}
	}

	/**
	 * @param string $name Remote file name.
	 * @throws PermanentFailure On "." or "..", which are folder references, not files.
	 */
	private function key( string $name ): string {
		if ( self::dotName( $name ) || str_contains( $name, '/' ) ) {
			throw new PermanentFailure( __( 'This is not a file name; nothing was done.', 'lw-migration' ), PermanentFailure::INVALID );
		}

		return '' === $this->prefix ? $name : $this->prefix . '/' . $name;
	}

	/**
	 * A stored session is data from the database: it must be this destination's
	 * own upload (type, key of the file, the part size its size dictates).
	 *
	 * @param UploadSession $s Stored session.
	 */
	private function isOwn( UploadSession $s ): bool {
		try {
			return self::TYPE === $s->type && ( $s->extra['key'] ?? null ) === $this->key( $s->name ) && $s->size <= ChunkPolicy::S3_MAX_PART * ChunkPolicy::S3_MAX_PARTS && ChunkPolicy::s3PartSize( $s->size ) === $s->chunk;
		} catch ( PermanentFailure $e ) {
			return false;
		}
	}

	/**
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not this destination's upload.
	 */
	private function assertOwn( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * @param string $key Object key.
	 * @return int Size, -1 when unknown.
	 */
	private function sizeOf( string $key ): int {
		try {
			return $this->client->request( 'HEAD', $key, [], '', [], S3Client::TIMEOUT, null )['length'];
		} catch ( PermanentFailure $e ) {
			return -1;
		}
	}

	/**
	 * @param string $name File name.
	 * @throws \LogicException Unless a backup name or the probe file.
	 */
	private static function assertName( string $name ): void {
		if ( ! BackupName::isValid( $name ) && ! ProbeFile::is( $name ) ) {
			throw new \LogicException( 'Only backups and the test file are uploaded.' );
		}
	}

	private static function dotName( string $name ): bool {
		return '.' === $name || '..' === $name;
	}

	private static function timeout(): int {
		return CloudTimeout::seconds();
	}
}
