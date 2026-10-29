<?php
/**
 * A destination that forwards to a FakeDestination; tests override single methods.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

/**
 * Forwards every call to the wrapped fake.
 */
class FakeDestinationProxy implements DestinationInterface {

	public function __construct( protected FakeDestination $d ) {
	}

	public function test(): void {
		$this->d->test();
	}

	public function startUpload( string $name, int $size ): UploadSession {
		return $this->d->startUpload( $name, $size );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		return $this->d->uploadChunk( $s, $bytes );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		return $this->d->finishUpload( $s );
	}

	public function list(): array {
		return $this->d->list();
	}

	public function delete( RemoteFile $f ): void {
		$this->d->delete( $f );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		return $this->d->readRange( $f, $offset, $length );
	}

	public function abort( UploadSession $s ): void {
		$this->d->abort( $s );
	}
}
