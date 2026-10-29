<?php
/**
 * A FakeDestination that also answers a status query.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\ResumesUpload;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

/**
 * Delegates everything to $inner; resume() counts and answers $resumeTo
 * (fn( UploadSession ): UploadSession, default: unchanged).
 */
final class FakeResumableDestination implements DestinationInterface, ResumesUpload {

	public int $resumes = 0;

	public ?\Closure $resumeTo = null;

	public function __construct( public FakeDestination $inner ) {
	}

	public function resume( UploadSession $s ): UploadSession {
		++$this->resumes;
		return null === $this->resumeTo ? $s : ( $this->resumeTo )( $s );
	}

	public function test(): void {
		$this->inner->test();
	}

	public function startUpload( string $name, int $size ): UploadSession {
		return $this->inner->startUpload( $name, $size );
	}

	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession {
		return $this->inner->uploadChunk( $s, $bytes );
	}

	public function finishUpload( UploadSession $s ): RemoteFile {
		return $this->inner->finishUpload( $s );
	}

	public function list(): array {
		return $this->inner->list();
	}

	public function delete( RemoteFile $f ): void {
		$this->inner->delete( $f );
	}

	public function readRange( RemoteFile $f, int $offset, int $length ): string {
		return $this->inner->readRange( $f, $offset, $length );
	}

	public function abort( UploadSession $s ): void {
		$this->inner->abort( $s );
	}
}
