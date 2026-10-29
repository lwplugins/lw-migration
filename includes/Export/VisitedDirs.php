<?php
/**
 * Real paths of the folders an enumeration already walked.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Kept in a side file (one base64 real path per line) next to the file list,
 * cut back to the cursor at every start like the list itself, so a folder
 * reached twice (symlinks, loops, a root inside another root) is walked once
 * across ticks without growing job.json.
 */
final class VisitedDirs {

	/** @var array<string, true> */
	private array $seen = [];

	/**
	 * @param resource $handle Side file, positioned at its end.
	 */
	private function __construct( private $handle ) {
	}

	/**
	 * @param string $path   Side file.
	 * @param int    $length Valid length (from the cursor).
	 * @return self
	 * @throws ExportException When the file cannot be opened or is shorter than $length.
	 */
	public static function open( string $path, int $length ): self {
		$handle = ListFile::open( $path, $length );
		$self   = new self( $handle );
		rewind( $handle );
		$valid = (string) stream_get_contents( $handle, $length );
		foreach ( explode( "\n", $valid ) as $line ) {
			if ( '' !== $line ) {
				$self->seen[ (string) base64_decode( $line, true ) ] = true;
			}
		}
		fseek( $handle, $length );

		return $self;
	}

	/**
	 * Records a folder; false when it was already walked.
	 *
	 * @param string $realPath Resolved folder path.
	 * @return bool
	 * @throws ExportException When the file cannot be written.
	 */
	public function add( string $realPath ): bool {
		if ( isset( $this->seen[ $realPath ] ) ) {
			return false;
		}
		ListFile::write( $this->handle, base64_encode( $realPath ) . "\n" );
		$this->seen[ $realPath ] = true;

		return true;
	}

	/**
	 * @return int Length after flushing.
	 * @throws ExportException When the file cannot be written.
	 */
	public function flush(): int {
		return ListFile::flush( $this->handle );
	}

	public function close(): void {
		fclose( $this->handle );
	}
}
