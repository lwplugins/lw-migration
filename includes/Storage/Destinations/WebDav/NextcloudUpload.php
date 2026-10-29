<?php
/**
 * Nextcloud's chunked upload (v2).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\WebDav;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Storage\Destinations\ChunkPolicy;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D24/D25 (verified against Nextcloud 35.0.1): MKCOL an upload folder
 * under remote.php/dav/uploads/<user>/, PUT chunks named 00001…, MOVE .file
 * onto the target, never over another file (Overwrite: F, a taken name
 * answers 412); Destination on all three, OC-Total-Length on PUT and MOVE.
 * Chunks have one size per upload (≥ 10 MiB, ≤ 10 000 chunks); a chunk sent
 * again replaces itself. Nextcloud forgets an upload folder after 24
 * hours without activity: past 23 hours, or on a 404, the upload is
 * SESSION_GONE and the upload step starts once more in a new folder. A
 * stored session that is not one of ours (preflight P-I8, H10: type, mode,
 * folder id, chunk size, touch time, a backup or probe name) is SESSION_GONE
 * before any request, and aborting it sends nothing.
 */
final class NextcloudUpload {

	public const MODE = 'nextcloud';

	/** Seconds after the last chunk when the folder is taken for gone (24 h, an hour of margin). */
	public const EXPIRY = 82800;

	public const MIN_CHUNK = 10485760;

	/** The destination type whose sessions these are (plan D39). */
	private const TYPE = 'webdav';

	private const ID = '/^lw-migration-[0-9a-f]{16}\z/';

	/** Slowest upload rate a chunk is given time for (bytes a second), as the plain PUT. */
	private const RATE = 262144;

	/** Bytes a second Nextcloud is expected to assemble at least (the MOVE's timeout). */
	private const ASSEMBLY_RATE = 10485760;

	private const SMALL = 65536;

	public function __construct( private WebDavClient $client, private WebDavAddress $address, private DavFolder $folder ) {
	}

	/**
	 * Whether the server is Nextcloud (status.php). No status page (an HTTP
	 * refusal): a plain WebDAV server. A status page that fails passingly, or
	 * an address the check refuses, is passed on: the flavour must not be
	 * guessed during an outage.
	 *
	 * @throws PermanentFailure When the address is refused or the request was never sent (a passing failure is thrown as it is).
	 */
	public function detect(): bool {
		if ( ! $this->address->isNextcloud() ) {
			return false;
		}
		try {
			$answer = $this->client->request( 'GET', $this->address->statusUrl(), [], '', 0, self::SMALL );
		} catch ( PermanentFailure $e ) {
			if ( 0 === $e->status ) {
				throw $e; // Not an answer of the server: a refused address or a request never sent.
			}

			return false;
		}
		$status = json_decode( $answer['body'], true );

		return is_array( $status ) && true === ( $status['installed'] ?? null ) && 'Nextcloud' === ( $status['productname'] ?? null );
	}

	public static function chunkFor( int $size ): int {
		return max( self::MIN_CHUNK, ChunkPolicy::s3PartSize( $size ) );
	}

	/**
	 * Makes the upload folder; a CloudFailure of the client is passed on.
	 *
	 * @param string $type Destination type key for the session ('webdav').
	 * @param string $name Backup name.
	 * @param int    $size Bytes.
	 * @throws \LogicException For another type, or a name that is neither a backup nor the test file (nothing is sent).
	 */
	public function start( string $type, string $name, int $size ): UploadSession {
		if ( self::TYPE !== $type || ( ! BackupName::isValid( $name ) && ! ProbeFile::is( $name ) ) ) {
			throw new \LogicException( 'Only WebDAV uploads of backups and the test file go to Nextcloud.' );
		}
		$id = 'lw-migration-' . bin2hex( random_bytes( 8 ) );
		$this->client->request( 'MKCOL', $this->address->uploadsUrl( $id ), [ 'Destination' => $this->address->file( $name ) ], '', 0, self::SMALL );

		return new UploadSession( $type, $name, $size, 0, self::chunkFor( $size ), $id, $this->extra() );
	}

	/**
	 * @param UploadSession    $s      Session.
	 * @param string|FileSlice $body   The chunk.
	 * @param int              $length Its bytes (= $s->nextLength()).
	 * @throws PermanentFailure SESSION_GONE when the session is not ours, or the folder expired or is gone.
	 * @throws \LogicException On a chunk off its boundary or of another length.
	 */
	public function part( UploadSession $s, $body, int $length ): UploadSession {
		$this->assertOwn( $s );
		self::assertChunk( $s, $length );
		$this->assertFresh( $s );
		$number = intdiv( $s->offset, $s->chunk ) + 1;
		try {
			$this->client->request( 'PUT', $this->address->uploadsUrl( $s->remote ) . '/' . sprintf( '%05d', $number ), $this->headers( $s ), $body, CloudTimeout::forBytes( $length, self::RATE ), self::SMALL );
		} catch ( PermanentFailure $e ) {
			throw $this->gone( $e );
		}

		return $s->with( $s->offset + $length, $this->extra() );
	}

	/**
	 * @param UploadSession $s A complete session.
	 * @throws PermanentFailure SESSION_GONE when the session is not ours, or the folder expired or is gone (and the file is not in place); INVALID when another file has the name (Overwrite: F).
	 * @throws \LogicException On a session that is not complete.
	 */
	public function finish( UploadSession $s ): RemoteFile {
		$this->assertOwn( $s );
		self::assertChunk( $s, null );
		$this->assertFresh( $s );
		try {
			$this->client->request( 'MOVE', $this->address->uploadsUrl( $s->remote ) . '/.file', $this->headers( $s ) + [ 'Overwrite' => 'F' ], '', CloudTimeout::forBytes( $s->size, self::ASSEMBLY_RATE ), self::SMALL );
		} catch ( PermanentFailure $e ) {
			if ( ! $this->folder->isPlaced( $e, $s->name, $s->size ) ) {
				throw $this->gone( $e ); // Unless a MOVE that arrived before its request died put the file in place.
			}
			if ( 412 === $e->status ) {
				$this->abort( $s ); // This backup is already in place: its chunks are not needed.
			}
		}

		return new RemoteFile( $s->name, $s->size, $this->client->now(), $s->name );
	}

	/**
	 * @param UploadSession $s Session.
	 * @throws PermanentFailure When the folder cannot be removed (a missing one is fine; a passing failure is thrown as it is).
	 */
	public function abort( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			return; // Not an upload of ours: nothing to cancel here.
		}
		try {
			$this->client->request( 'DELETE', $this->address->uploadsUrl( $s->remote ), [], '', 0, self::SMALL );
		} catch ( PermanentFailure $e ) {
			if ( PermanentFailure::MISSING !== $e->reason ) {
				throw $e;
			}
		}
	}

	/**
	 * @return array{mode: string, touched: int}
	 */
	private function extra(): array {
		return [
			'mode'    => self::MODE,
			'touched' => $this->client->now(),
		];
	}

	/**
	 * @param UploadSession $s Session.
	 * @return array<string, string>
	 */
	private function headers( UploadSession $s ): array {
		return [
			'Destination'     => $this->address->file( $s->name ),
			'OC-Total-Length' => (string) $s->size,
		];
	}

	/**
	 * @param UploadSession $s Stored session.
	 */
	private function isOwn( UploadSession $s ): bool {
		return self::TYPE === $s->type
			&& self::MODE === ( $s->extra['mode'] ?? null )
			&& 1 === preg_match( self::ID, $s->remote )
			&& self::chunkFor( $s->size ) === $s->chunk
			&& is_int( $s->extra['touched'] ?? null )
			&& ( BackupName::isValid( $s->name ) || ProbeFile::is( $s->name ) );
	}

	/**
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not an upload of ours.
	 */
	private function assertOwn( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * @param UploadSession $s Session of ours.
	 * @throws PermanentFailure SESSION_GONE when the folder waited past EXPIRY.
	 */
	private function assertFresh( UploadSession $s ): void {
		if ( $this->client->now() - (int) $s->extra['touched'] > self::EXPIRY ) {
			/* translators: %s: storage address */
			throw new PermanentFailure( sprintf( __( 'The unfinished upload at %s waited more than a day, so Nextcloud has removed it; it starts over.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE );
		}
	}

	/**
	 * Chunk rules: a part starts on a chunk boundary and has the chunk size
	 * (only the last may be smaller); a null $length asks whether the upload
	 * is complete (before the MOVE).
	 *
	 * @param UploadSession $s      Session of ours.
	 * @param int|null      $length Bytes of the part, null for the MOVE.
	 * @throws \LogicException When the rule is broken.
	 */
	private static function assertChunk( UploadSession $s, ?int $length ): void {
		$ok = null === $length ? $s->isComplete() : ! $s->isComplete() && $length === $s->nextLength() && 0 === $s->offset % $s->chunk;
		if ( ! $ok ) {
			throw new \LogicException( 'A Nextcloud chunk starts on a chunk boundary and has the chunk size of its upload (only the last may be smaller); only a complete upload is assembled.' );
		}
	}

	private function gone( PermanentFailure $e ): PermanentFailure {
		if ( PermanentFailure::MISSING !== $e->reason ) {
			return $e;
		}

		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( '%s no longer knows this unfinished upload.', 'lw-migration' ), $this->client->where() ), PermanentFailure::SESSION_GONE, $e->status );
	}
}
