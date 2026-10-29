<?php
/**
 * An in-memory folder on a file server.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\CloudFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\RemoteFolder;

/**
 * Writes at an offset overwrite without truncating (like REST+STOR on
 * vsftpd and SFTP writes with an offset); a rename replaces an existing
 * target (like RNTO on vsftpd), so the destination has to prevent that;
 * every call is recorded as "method:detail"; open() hands out this folder
 * and counts it.
 */
final class FakeRemoteFolder implements RemoteFolder {

	/** @var array<string, string> */
	public array $files = [];

	/** @var array<string, int> */
	public array $mtimes = [];

	/** @var array<int, string> */
	public array $calls = [];

	public int $opened = 0;

	public int $closed = 0;

	/** Extra bytes every read() answers with (a broken seam). */
	public int $overRead = 0;

	/** @var array<string, array<int, CloudFailure>> */
	private array $failures = [];

	public function failNext( string $method, CloudFailure $e ): void {
		$this->failures[ $method ][] = $e;
	}

	/**
	 * What a server reboot, a cleanup or a quota does to a file between two requests.
	 */
	public function truncate( string $name, int $length ): void {
		$this->files[ $name ] = substr( $this->files[ $name ] ?? '', 0, $length );
	}

	public function open(): \Closure {
		return function (): RemoteFolder {
			++$this->opened;
			return $this;
		};
	}

	public function files(): array {
		$this->call( 'files', '' );
		$all = [];
		foreach ( $this->files as $name => $bytes ) {
			$all[ (string) $name ] = [
				'size'  => strlen( $bytes ),
				'mtime' => $this->mtimes[ $name ] ?? 1760000000,
			];
		}

		return $all;
	}

	public function size( string $name ): int {
		$this->call( 'size', $name );

		return isset( $this->files[ $name ] ) ? strlen( $this->files[ $name ] ) : -1;
	}

	public function write( string $name, string $bytes, int $offset ): void {
		$this->call( 'write', $name . '@' . $offset . '+' . strlen( $bytes ) );
		$old = $this->files[ $name ] ?? '';
		if ( $offset > strlen( $old ) ) {
			throw new \LogicException( 'A gap in the file.' );
		}
		$this->files[ $name ] = substr( $old, 0, $offset ) . $bytes . (string) substr( $old, $offset + strlen( $bytes ) );
	}

	public function read( string $name, int $offset, int $length ): string {
		$this->call( 'read', $name . '@' . $offset . '+' . $length );
		if ( ! isset( $this->files[ $name ] ) ) {
			throw new PermanentFailure( 'Gone.', PermanentFailure::MISSING );
		}

		return substr( $this->files[ $name ], $offset, $length + $this->overRead );
	}

	public function rename( string $from, string $to ): void {
		$this->call( 'rename', $from . '>' . $to );
		if ( ! isset( $this->files[ $from ] ) ) {
			throw new PermanentFailure( 'Gone.', PermanentFailure::MISSING );
		}
		$this->files[ $to ] = $this->files[ $from ];
		unset( $this->files[ $from ] );
	}

	public function delete( string $name ): bool {
		$this->call( 'delete', $name );
		$had = isset( $this->files[ $name ] );
		unset( $this->files[ $name ] );

		return $had;
	}

	public function close(): void {
		++$this->closed;
	}

	private function call( string $method, string $detail ): void {
		$this->calls[] = $method . ':' . $detail;
		if ( [] !== ( $this->failures[ $method ] ?? [] ) ) {
			throw array_shift( $this->failures[ $method ] );
		}
	}
}
