<?php
/**
 * A folder on a file server as a destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Support\Memory;

defined( 'ABSPATH' ) || exit;

/**
 * FTP and SFTP (spec 21.4) through RemoteFolder. Every call opens its own
 * connection and closes it (a job runs over many requests). An upload goes
 * to "<name>.part" — a stale one is deleted first — in growing parts at
 * explicit offsets, so a part sent again overwrites itself (a part file
 * shorter than the offset starts the upload over instead of leaving a
 * hole); it is renamed
 * to the backup's name once its size is right. A rename never replaces a
 * file: the name is looked up first. Backup names are meant to be unique
 * (site, second and a three-digit suffix), but an imported backup keeps the
 * name its owner gave it, so a file of that name is taken for this backup
 * only when it has its size — from a rename that arrived before its request
 * died, or an earlier upload — and is kept; one of another size is refused. Only backups and the test
 * file are listed, read or deleted. A stored session that is not one of
 * ours (preflight P-I8, H10) starts over before any connection, and
 * aborting it opens none.
 */
final class FolderDestination implements DestinationInterface {

	public const PART = '.part';

	/**
	 * Unix time: fn(): int.
	 *
	 * @var \Closure
	 */
	private \Closure $now;

	/**
	 * Seconds with fractions: fn(): float.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Memory left: fn(): ?int.
	 *
	 * @var \Closure
	 */
	private \Closure $memory;

	/**
	 * @param string        $type   Type key for sessions.
	 * @param string        $where  scheme://host:port for messages.
	 * @param \Closure      $open   fn(): RemoteFolder — connected, logged in, in the folder.
	 * @param \Closure|null $now    fn(): int (default time()).
	 * @param \Closure|null $clock  fn(): float (default microtime( true )).
	 * @param \Closure|null $memory fn(): ?int (default Memory::available()).
	 */
	public function __construct( private string $type, private string $where, private \Closure $open, ?\Closure $now = null, ?\Closure $clock = null, ?\Closure $memory = null ) {
		$this->now    = $now ?? static fn(): int => time();
		$this->clock  = $clock ?? static fn(): float => microtime( true );
		$this->memory = $memory ?? static fn(): ?int => Memory::available();
	}

	public function test(): void {
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		if ( ! self::isFileName( $name ) || $size < 1 ) {
			throw new \LogicException( 'Only backups and the test file are uploaded, with at least one byte.' );
		}
		$this->with( static fn( RemoteFolder $folder ): bool => $folder->delete( $name . self::PART ) );

		return new UploadSession( $this->type, $name, $size, 0, GrowingParts::policy( ( $this->memory )() )->first(), $name . self::PART );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		$this->assertOwn( $s );
		if ( $s->isComplete() || strlen( $bytes ) !== $s->nextLength() ) {
			throw new \LogicException( 'A part has exactly the length its session asks for.' );
		}
		$clock   = $this->clock;
		$seconds = (float) $this->with(
			function ( RemoteFolder $folder ) use ( $s, $bytes, $clock ): float {
				if ( max( 0, $folder->size( $s->remote ) ) < $s->offset ) {
					throw $this->gone(); // The part file lost bytes (reboot, cleanup, quota): a write would leave a hole.
				}
				$started = (float) $clock(); // Only the write counts: the login is the same for every part size.
				$folder->write( $s->remote, $bytes, $s->offset );

				return (float) $clock() - $started;
			}
		);

		return GrowingParts::after( $s, strlen( $bytes ), $seconds, GrowingParts::policy( ( $this->memory )() ) );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Only a complete upload can be finished.' );
		}
		$this->assertOwn( $s );
		$this->with( fn( RemoteFolder $folder ) => $this->place( $folder, $s ) );

		return new RemoteFile( $s->name, $s->size, (int) ( $this->now )(), $s->name );
	}

	public function list(): array {
		return $this->with(
			static function ( RemoteFolder $folder ): array {
				$files = [];
				foreach ( $folder->files() as $name => $stat ) {
					$name = (string) $name;
					if ( self::isFileName( $name ) ) {
						$files[] = new RemoteFile( $name, $stat['size'], $stat['mtime'], $name );
					}
				}
				return $files;
			}
		);
	}

	public function delete( RemoteFile $f ): void {
		if ( ! self::isFileName( $f->name ) || $f->ref !== $f->name ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not deleted.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$this->with( static fn( RemoteFolder $folder ): bool => $folder->delete( $f->name ) );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		if ( $offset < 0 || $length < 1 || $offset + $length > $f->size ) {
			throw new \LogicException( 'A range has a start, at least one byte and lies within the file.' );
		}
		if ( ! self::isFileName( $f->name ) || $f->ref !== $f->name ) {
			throw new PermanentFailure( __( 'This file is not in the destination\'s folder; it was not read.', 'lw-migration' ), PermanentFailure::INVALID );
		}
		$bytes = (string) $this->with( static fn( RemoteFolder $folder ): string => $folder->read( $f->name, $offset, $length ) );
		if ( strlen( $bytes ) > $length ) {
			throw new \LogicException( 'A RemoteFolder read answers at most the bytes asked for.' );
		}
		if ( strlen( $bytes ) < $length ) {
			/* translators: %s: storage address */
			throw new TransientFailure( sprintf( __( '%s sent fewer bytes than asked for.', 'lw-migration' ), $this->where ), TransientFailure::SERVER );
		}

		return $bytes;
	}

	public function abort( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			return; // Not this destination's upload: nothing to remove here.
		}
		$this->with( static fn( RemoteFolder $folder ): bool => $folder->delete( $s->remote ) );
	}

	/**
	 * Puts the complete part file in place under the backup's name.
	 *
	 * @param RemoteFolder  $folder Open folder.
	 * @param UploadSession $s      Complete session of ours.
	 * @throws PermanentFailure INVALID when another file has the name; SESSION_GONE when the part file is lost.
	 */
	private function place( RemoteFolder $folder, UploadSession $s ): void {
		$part  = $folder->size( $s->remote );
		$final = $folder->size( $s->name );
		if ( $part === $s->size && -1 === $final ) {
			$folder->rename( $s->remote, $s->name );
			return;
		}
		if ( $final === $s->size && ( -1 === $part || $part === $s->size ) ) {
			if ( -1 !== $part ) {
				$folder->delete( $s->remote ); // This backup is already in place (its name is unique).
			}
			return; // Or the rename arrived before the request that did it died.
		}
		if ( $part === $s->size ) {
			/* translators: %s: server address */
			throw new PermanentFailure( sprintf( __( 'Another file with this backup\'s name is already on %s; it was not replaced.', 'lw-migration' ), $this->where ), PermanentFailure::INVALID );
		}
		/* translators: %s: server address */
		throw new PermanentFailure( sprintf( __( 'The file uploaded to %s has another size than the backup; the upload starts over.', 'lw-migration' ), $this->where ), PermanentFailure::SESSION_GONE );
	}

	/**
	 * A stored session is data from the database: this type, a backup or the
	 * test file with its part file, no provider extras, a part size this
	 * class hands out and an offset on a part boundary (or the end).
	 *
	 * @param UploadSession $s Stored session.
	 */
	private function isOwn( UploadSession $s ): bool {
		return $this->type === $s->type
			&& self::isFileName( $s->name )
			&& $s->name . self::PART === $s->remote
			&& [] === $s->extra
			&& $s->chunk >= GrowingParts::MULTIPLE
			&& $s->chunk <= GrowingParts::MAX
			&& 0 === $s->chunk % GrowingParts::MULTIPLE
			&& $s->offset >= 0
			&& $s->offset <= $s->size
			&& ( 0 === $s->offset % GrowingParts::MULTIPLE || $s->offset === $s->size );
	}

	/**
	 * @param UploadSession $s Stored session.
	 * @throws PermanentFailure SESSION_GONE when it is not this destination's upload (nothing is sent).
	 */
	private function assertOwn( UploadSession $s ): void {
		if ( ! $this->isOwn( $s ) ) {
			throw $this->gone();
		}
	}

	/** The upload cannot go on from its record; the step starts it over. */
	private function gone(): PermanentFailure {
		/* translators: %s: storage address */
		return new PermanentFailure( sprintf( __( 'The record of this upload to %s does not fit; the upload starts over.', 'lw-migration' ), $this->where ), PermanentFailure::SESSION_GONE );
	}

	private static function isFileName( string $name ): bool {
		return BackupName::isValid( $name ) || ProbeFile::is( $name );
	}

	/**
	 * Opens the folder, runs $work, always closes it.
	 *
	 * @param \Closure $work fn( RemoteFolder $folder ): mixed.
	 * @return mixed What $work returned.
	 */
	private function with( \Closure $work ) {
		$folder = ( $this->open )();
		try {
			return $work( $folder );
		} finally {
			$folder->close();
		}
	}
}
