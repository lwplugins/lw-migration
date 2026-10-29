<?php
/**
 * An FTP server in memory, with the quirks real ones have.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\ExtFtpConnection;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpConnection;

/**
 * Defaults behave like vsftpd (verified, plan D28): FEAT lists REST STREAM,
 * REST+STOR overwrites at the offset without truncating, APPE appends, SIZE
 * answers. Flags switch quirks on: no REST in FEAT, REST+STOR refused, APPE
 * refused, no SIZE, or REST silently ignored (STOR truncates).
 */
final class FakeFtp implements FtpConnection {

	/** @var array<string, string> */
	public array $files = [];

	/** @var array<string, int> */
	public array $mtimes = [];

	/** @var array<int, string> */
	public array $calls = [];

	public bool $rest = true;

	public bool $restStore = true;

	public bool $appe = true;

	public bool $sizes = true;

	public bool $ignoresOffset = false;

	/** False: a download from inside a file (REST before RETR) is refused, as ExtFtpConnection reports it. */
	public bool $restRead = true;

	public bool $closed = false;

	/** @var array<int, string> Methods that answer as failed (store, append, names, read, rename, delete). */
	public array $refuses = [];

	public function features(): array {
		$this->calls[] = 'FEAT';

		return array_merge( [ '211-Features:', ' PASV', ' SIZE', ' MDTM' ], $this->rest ? [ ' REST STREAM' ] : [], [ '211 End' ] );
	}

	public function size( string $name ): int {
		$this->calls[] = 'SIZE ' . $name;

		return $this->sizes && isset( $this->files[ $name ] ) ? strlen( $this->files[ $name ] ) : -1;
	}

	public function mtime( string $name ): int {
		return $this->mtimes[ $name ] ?? -1;
	}

	public function names(): ?array {
		return in_array( 'names', $this->refuses, true ) ? null : array_map( static fn( $n ): string => (string) $n, array_keys( $this->files ) );
	}

	public function store( string $name, string $bytes, int $offset ): bool {
		$this->calls[] = ( $offset > 0 ? 'REST ' . $offset . ' + ' : '' ) . 'STOR ' . $name . ' ' . strlen( $bytes );
		if ( in_array( 'store', $this->refuses, true ) || ( $offset > 0 && ! $this->restStore ) ) {
			return false;
		}
		if ( 0 === $offset || $this->ignoresOffset ) {
			$this->files[ $name ] = $bytes;
			return true;
		}
		$old                  = $this->files[ $name ] ?? '';
		$this->files[ $name ] = substr( str_pad( $old, $offset, "\0" ), 0, $offset ) . $bytes . (string) substr( $old, $offset + strlen( $bytes ) );

		return true;
	}

	public function append( string $name, string $bytes ): bool {
		$this->calls[] = 'APPE ' . $name . ' ' . strlen( $bytes );
		if ( ! $this->appe || in_array( 'append', $this->refuses, true ) ) {
			return false;
		}
		$this->files[ $name ] = ( $this->files[ $name ] ?? '' ) . $bytes;

		return true;
	}

	public function read( string $name, int $offset, int $length ): ?string {
		$this->calls[] = 'RETR ' . $name . ' @' . $offset;
		if ( $offset > 0 && ! $this->restRead && isset( $this->files[ $name ] ) ) {
			throw ExtFtpConnection::partsRefused( 'ftp://fake:21' );
		}

		return isset( $this->files[ $name ] ) && ! in_array( 'read', $this->refuses, true ) ? substr( $this->files[ $name ], $offset, $length ) : null;
	}

	public function rename( string $from, string $to ): bool {
		$this->calls[] = 'RNFR ' . $from . ' RNTO ' . $to;
		if ( ! isset( $this->files[ $from ] ) || in_array( 'rename', $this->refuses, true ) ) {
			return false;
		}
		$this->files[ $to ] = $this->files[ $from ];
		unset( $this->files[ $from ] );

		return true;
	}

	public function delete( string $name ): bool {
		$this->calls[] = 'DELE ' . $name;
		if ( in_array( 'delete', $this->refuses, true ) ) {
			return false;
		}
		$had = isset( $this->files[ $name ] );
		unset( $this->files[ $name ] );

		return $had;
	}

	public function close(): void {
		$this->closed = true;
	}
}
