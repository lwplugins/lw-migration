<?php
/**
 * An in-memory cloud destination.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ProbeFile;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

/**
 * Keeps files and unfinished uploads in arrays, records every call
 * ("method:detail") and throws a queued failure before the next call of a
 * method. Parts must have exactly nextLength() bytes, like S3.
 */
final class FakeDestination implements DestinationInterface {

	/** @var array<string, string> Name => bytes. */
	public array $files = [];

	/** @var array<string, int> Name => mtime. */
	public array $mtimes = [];

	/** @var array<string, array{name: string, data: string}> Remote id => upload. */
	public array $sessions = [];

	/** @var array<int, string> */
	public array $calls = [];

	/** @var array<string, array<int, CloudFailure>> */
	private array $failures = [];

	private int $next = 0;

	public function __construct( public int $chunk = 5, public int $now = 1760000000 ) {
	}

	public function failNext( string $method, CloudFailure $e ): void {
		$this->failures[ $method ][] = $e;
	}

	public function test(): void {
		$this->call( 'test', '' );
		ProbeFile::check( $this );
	}

	public function startUpload( string $name, int $size ): UploadSession {
		$this->call( 'start', $name );
		$id                    = 'u' . ( ++$this->next );
		$this->sessions[ $id ] = [
			'name' => $name,
			'data' => '',
		];

		return new UploadSession( 'fake', $name, $size, 0, $this->chunk, $id, [ 'parts' => 0 ] );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		if ( strlen( $bytes ) !== $s->nextLength() ) {
			throw new \LogicException( 'Part of the wrong length.' );
		}
		$this->call( 'part', $s->name . '@' . $s->offset . '+' . strlen( $bytes ) );
		if ( ! isset( $this->sessions[ $s->remote ] ) ) {
			throw new PermanentFailure( 'Upload gone.', PermanentFailure::SESSION_GONE, 404 );
		}
		$data                                    = $this->sessions[ $s->remote ]['data'];
		$this->sessions[ $s->remote ]['data'] = substr( $data, 0, $s->offset ) . $bytes; // The same offset again replaces, like a part number.

		return $s->with( $s->offset + strlen( $bytes ), [ 'parts' => (int) ( $s->extra['parts'] ?? 0 ) + 1 ] );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		if ( ! $s->isComplete() ) {
			throw new \LogicException( 'Not complete.' );
		}
		$this->call( 'finish', $s->name );
		if ( ! isset( $this->sessions[ $s->remote ] ) ) {
			// A finish replayed after a request died past it: the same file, like S3's HEAD-size recovery.
			if ( isset( $this->files[ $s->name ] ) && strlen( $this->files[ $s->name ] ) === $s->size ) {
				return new RemoteFile( $s->name, $s->size, $this->mtimes[ $s->name ] ?? $this->now, 'k/' . $s->name );
			}
			throw new PermanentFailure( 'Upload gone.', PermanentFailure::SESSION_GONE, 404 );
		}
		$upload                       = $this->sessions[ $s->remote ];
		$this->files[ $s->name ]  = $upload['data'];
		$this->mtimes[ $s->name ] = $this->now;
		unset( $this->sessions[ $s->remote ] );

		return new RemoteFile( $s->name, strlen( $upload['data'] ), $this->now, 'k/' . $s->name );
	}

	public function list(): array {
		$this->call( 'list', '' );
		$all = [];
		foreach ( $this->files as $name => $bytes ) {
			$all[] = new RemoteFile( (string) $name, strlen( $bytes ), $this->mtimes[ $name ] ?? $this->now, 'k/' . $name );
		}

		return $all;
	}

	public function delete( RemoteFile $f ): void {
		$this->call( 'delete', $f->name );
		unset( $this->files[ $f->name ], $this->mtimes[ $f->name ] );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		$this->call( 'read', $f->name . '@' . $offset . '+' . $length );
		if ( ! isset( $this->files[ $f->name ] ) ) {
			throw new PermanentFailure( 'Gone.', PermanentFailure::MISSING, 404 );
		}

		return substr( $this->files[ $f->name ], $offset, $length );
	}

	public function abort( UploadSession $s ): void {
		$this->call( 'abort', $s->name );
		unset( $this->sessions[ $s->remote ] );
	}

	private function call( string $method, string $detail ): void {
		$this->calls[] = $method . ':' . $detail;
		if ( [] !== ( $this->failures[ $method ] ?? [] ) ) {
			throw array_shift( $this->failures[ $method ] );
		}
	}
}
