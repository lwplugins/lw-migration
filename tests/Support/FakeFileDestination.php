<?php
/**
 * An in-memory destination that reads each part from the backup file itself.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\DestinationInterface;
use LightweightPlugins\Migration\Storage\Destinations\FileUpload;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFile;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;

/**
 * Wraps a FakeDestination; uploadFrom() records "<path>@<offset>" and reads
 * exactly nextLength() bytes from the file.
 */
final class FakeFileDestination implements DestinationInterface, FileUpload {

	public FakeDestination $inner;

	/** @var array<int, string> */
	public array $from = [];

	public function __construct( int $chunk = 5 ) {
		$this->inner = new FakeDestination( $chunk );
	}

	public function uploadFrom( UploadSession $s, string $path ): UploadSession {
		$this->from[] = $path . '@' . $s->offset;

		return $this->inner->uploadChunk( $s, (string) file_get_contents( $path, false, null, $s->offset, $s->nextLength() ) );
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
