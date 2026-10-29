<?php
/**
 * A folder on a WebDAV server (Nextcloud or any other) as a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\FileUpload;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 21.4 WebDAV, plan D23–D26. The flavour is decided when an upload
 * starts and kept in the session (extra.mode): Nextcloud uploads go in
 * chunks (NextcloudUpload); any other server gets the whole backup in one
 * PUT of "<name>.lwm-part" (Nextcloud refuses the ".part" file type),
 * streamed from the file, then a MOVE onto the name that never replaces
 * another file.
 * Outside WP-CLI a one-request upload over lw_migration_webdav_single_put_bytes
 * is refused before anything is sent. Listing, ranged reads and deletes are
 * plain WebDAV for both flavours. A stored session that is not one of ours
 * (preflight P-I8, H10) starts over before any request and aborting it sends
 * nothing; only backups and the test file are read or deleted. A finished
 * test file must show in the folder's listing and size lookup (Task 4 m2),
 * so an address that lists elsewhere fails the connection test.
 */
final class WebDavDestination implements DestinationInterface, FileUpload {

	public const TYPE = 'webdav';

	public const AUTO = 'auto';

	public const NEXTCLOUD = 'nextcloud';

	public const PLAIN = 'webdav';

	public const SINGLE_PUT = 209715200;

	/** Slowest upload rate a whole-file PUT is given time for (bytes a second). */
	private const RATE = 262144;

	/** Not ".part": Nextcloud answers 400 to a file of that type (checked against 35.0.1). */
	private const PART = '.lwm-part';

	private const SMALL = 65536;

	/**
	 * Whether this runs under WP-CLI: fn(): bool.
	 *
	 * @var \Closure
	 */
	private \Closure $cli;

	/**
	 * @param WebDavClient  $client  Client with the credentials (its server is $address->display()).
	 * @param WebDavAddress $address Where the files go.
	 * @param string        $mode    AUTO, NEXTCLOUD or PLAIN.
	 * @param \Closure|null $cli     fn(): bool (default: WP_CLI is defined and true).
	 */
	public function __construct( private WebDavClient $client, private WebDavAddress $address, private string $mode, ?\Closure $cli = null ) {
		$this->cli = $cli ?? static fn(): bool => defined( 'WP_CLI' ) && WP_CLI;
	}

	/** Largest backup sent in one request outside WP-CLI (plan D26). */
	public static function singlePut(): int {
		/**
		 * Largest backup a plain WebDAV server gets in one web request.
		 *
		 * @param int $bytes Default 200 MiB.
		 */
		return max( 0, (int) apply_filters( 'lw_migration_webdav_single_put_bytes', self::SINGLE_PUT ) );
	}

	public function test(): void {
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		if ( ! self::isFileName( $name ) || $size < 1 ) {
			throw new \LogicException( 'Only backups and the test file are uploaded, with at least one byte.' );
		}
		$chunked = $this->chunked();
		if ( ! $chunked && $size > self::singlePut() && ! ( $this->cli )() ) {
			/* translators: %s: backup size */
			throw new PermanentFailure( sprintf( __( 'This WebDAV server takes a backup only in one request, and %s is too large for one web request here. Upload it from the command line (wp lw-migration upload), or use a Nextcloud address.', 'lw-migration' ), size_format( $size ) ), PermanentFailure::TOO_LARGE );
		}
		$this->folder()->ensure();
		if ( $chunked ) {
			return $this->nextcloud()->start( self::TYPE, $name, $size );
		}

		return new UploadSession( self::TYPE, $name, $size, 0, $size, $name . self::PART, [ 'mode' => self::PLAIN ] );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		$nextcloud = $this->flavour( $s );
		if ( $s->isComplete() || strlen( $bytes ) !== $s->nextLength() ) {
			throw new \LogicException( 'A part has exactly the length its session asks for.' );
		}

		return $this->send( $s, $nextcloud, $bytes, strlen( $bytes ) );
	}

	public function uploadFrom( UploadSession $s, string $path ): UploadSession {
		$nextcloud = $this->flavour( $s );
		if ( $s->isComplete() ) {
			throw new \LogicException( 'The upload is complete.' );
		}

		return $this->send( $s, $nextcloud, new FileSlice( $path, $s->offset, $s->nextLength() ), $s->nextLength() );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Only a complete upload can be finished.' );
		}
		$file = $this->flavour( $s ) ? $this->nextcloud()->finish( $s ) : $this->move( $s );
		if ( ProbeFile::is( $s->name ) ) {
			$this->assertListed( $file );
		}

		return $file;
	}

	public function list(): array {
		return $this->folder()->files();
	}

	public function delete( RemoteFile $f ): void {
		if ( ! self::isFileName( $f->name ) || $f->ref !== $f->name ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not deleted.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$this->remove( $this->address->file( $f->name ) );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 ) {
			throw new \LogicException( 'A range has a start and at least one byte.' );
		}
		if ( ! self::isFileName( $f->name ) || $f->ref !== $f->name ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not read.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$answer = $this->client->request( 'GET', $this->address->file( $f->name ), [ 'Range' => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ) ], '', CloudTimeout::seconds(), $length );
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
		if ( $this->isNextcloud( $s ) ) {
			$this->nextcloud()->abort( $s ); // It sends nothing for a session that is not its own.
		} elseif ( $this->isPlain( $s ) ) {
			$this->remove( $this->address->file( $s->remote ) );
		}
	}

	/**
	 * @param UploadSession    $s         Session of ours.
	 * @param bool             $nextcloud Whether it is a Nextcloud upload.
	 * @param string|FileSlice $body      The part.
	 * @param int              $length    Its bytes.
	 */
	private function send( UploadSession $s, bool $nextcloud, $body, int $length ): UploadSession {
		if ( $nextcloud ) {
			return $this->nextcloud()->part( $s, $body, $length );
		}
		$this->client->request( 'PUT', $this->address->file( $s->remote ), [ 'Content-Type' => 'application/octet-stream' ], $body, CloudTimeout::forBytes( $length, self::RATE ), self::SMALL );

		return $s->with( $s->offset + $length, $s->extra );
	}

	/**
	 * MOVE of the part file onto the name, never over another file
	 * (Overwrite: F); a MOVE that arrived before its request died (404), or a
	 * name already holding this backup (412), is recognised by the size of the
	 * file in place (DavFolder::isPlaced()), and a part file left over goes.
	 *
	 * @param UploadSession $s Complete plain session.
	 * @throws PermanentFailure When the server refuses and the file is not in place, INVALID when another file has the name (a passing failure is thrown as it is).
	 */
	private function move( UploadSession $s ): RemoteFile {
		try {
			$this->client->request(
				'MOVE',
				$this->address->file( $s->remote ),
				[
					'Destination' => $this->address->file( $s->name ),
					'Overwrite'   => 'F',
				],
				'',
				0,
				self::SMALL
			);
		} catch ( PermanentFailure $e ) {
			if ( ! $this->folder()->isPlaced( $e, $s->name, $s->size ) ) {
				throw $e;
			}
			if ( 412 === $e->status ) {
				$this->remove( $this->address->file( $s->remote ) ); // This backup is already in place.
			}
		}

		return new RemoteFile( $s->name, $s->size, $this->client->now(), $s->name );
	}

	/**
	 * Task 4 m2: the finished test file must be where list() and sizeOf()
	 * look; otherwise it is removed and the test fails with our text.
	 *
	 * @param RemoteFile $file The finished test file.
	 * @throws PermanentFailure INVALID when the listing or the size lookup misses it.
	 */
	private function assertListed( RemoteFile $file ): void {
		$listed = array_filter( $this->folder()->files(), static fn( RemoteFile $f ): bool => $f->name === $file->name && $f->size === $file->size );
		if ( [] !== $listed && $this->folder()->sizeOf( $file->name ) === $file->size ) {
			return;
		}
		try {
			$this->remove( $this->address->file( $file->name ) );
		} catch ( CloudFailure $ignored ) {
			unset( $ignored ); // The missing listing is the failure to report.
		}
		/* translators: %s: storage address */
		throw new PermanentFailure( sprintf( __( 'The test file was written to %s, but the folder\'s file list does not show it, so backups there could not be listed or pruned. Check the WebDAV address.', 'lw-migration' ), $this->client->where() ), PermanentFailure::INVALID );
	}

	/**
	 * Whether a stored session of ours is a Nextcloud upload (true) or a
	 * plain one (false).
	 *
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is neither (nothing is sent).
	 */
	private function flavour( UploadSession $s ): bool {
		if ( $this->isNextcloud( $s ) ) {
			return true; // NextcloudUpload checks the rest of its session before any request.
		}
		if ( $this->isPlain( $s ) ) {
			return false;
		}
		/* translators: %s: storage address */
		throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE );
	}

	/**
	 * @param UploadSession $s Stored session.
	 */
	private function isNextcloud( UploadSession $s ): bool {
		return self::NEXTCLOUD === ( $s->extra['mode'] ?? null ) && $this->address->isNextcloud();
	}

	/**
	 * Plain sessions are one whole-file part to "<name>.lwm-part", either not
	 * started or sent (a half-sent offset would PUT only the tail).
	 *
	 * @param UploadSession $s Stored session.
	 */
	private function isPlain( UploadSession $s ): bool {
		return self::TYPE === $s->type
			&& self::PLAIN === ( $s->extra['mode'] ?? null )
			&& $s->name . self::PART === $s->remote
			&& $s->size === $s->chunk
			&& ( 0 === $s->offset || $s->size === $s->offset )
			&& self::isFileName( $s->name );
	}

	private static function isFileName( string $name ): bool {
		return BackupName::isValid( $name ) || ProbeFile::is( $name );
	}

	private function chunked(): bool {
		if ( self::PLAIN === $this->mode ) {
			return false;
		}
		if ( self::NEXTCLOUD === $this->mode ) {
			return true; // WebDavType accepts the Nextcloud mode only with a Nextcloud-shaped address.
		}

		return $this->nextcloud()->detect();
	}

	/**
	 * @param string $url File or part file.
	 * @throws PermanentFailure When the server refuses (a missing file is fine; a passing failure is thrown as it is).
	 */
	private function remove( string $url ): void {
		try {
			$this->client->request( 'DELETE', $url, [], '', 0, self::SMALL );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::MISSING !== $e->reason ) {
				throw $e;
			}
		}
	}

	private function folder(): DavFolder {
		return new DavFolder( $this->client, $this->address );
	}

	private function nextcloud(): NextcloudUpload {
		return new NextcloudUpload( $this->client, $this->address, $this->folder() );
	}
}
