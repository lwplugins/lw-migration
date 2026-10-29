<?php
/**
 * An SFTP server in memory.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpSession;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;

/**
 * Paths are kept whole ("backups/a.wpress"); writes at an offset overwrite
 * without truncating (like OpenSSH, plan D34). A key login succeeds when the
 * key's OpenSSH public form (type and key, comment ignored) is in
 * $authorizedKeys. Every call is recorded.
 */
final class FakeSftp implements SftpSession {

	/** OpenSSH 10.3's ed25519 host key from the Docker harness. */
	public string $serverKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEeYqvUuZQShB2y3F23bERBADpvDwXkOx7PbFWLqgZkG';

	/** @var array<string, string> User => password. */
	public array $passwords = [ 'me' => 'pw' ];

	/** @var array<int, string> OpenSSH public keys allowed for any user. */
	public array $authorizedKeys = [];

	/** @var array<string, string> Path => bytes. */
	public array $files = [];

	/** @var array<int, string> */
	public array $calls = [];

	/** @var array<int, string> Methods that answer as failed (ensureFolder, files, write, read, rename, delete). */
	public array $refuses = [];

	public bool $closed = false;

	/** A host key the server shows from the login on (a connection taken over in between). */
	public ?string $keyAfterLogin = null;

	public function hostKey(): string {
		$this->calls[] = 'hostKey';

		return $this->serverKey;
	}

	public function login( string $user, string|PrivateKey $credential ): bool {
		$this->calls[] = 'login:' . $user;
		$this->serverKey = $this->keyAfterLogin ?? $this->serverKey;
		if ( is_string( $credential ) ) {
			return ( $this->passwords[ $user ] ?? null ) === $credential;
		}

		$blob = static fn( string $openssh ): string => implode( ' ', array_slice( explode( ' ', trim( $openssh ) ), 0, 2 ) ); // Type and key, without the comment.

		return in_array( $blob( $credential->getPublicKey()->toString( 'OpenSSH' ) ), array_map( $blob, $this->authorizedKeys ), true );
	}

	public function ensureFolder( string $folder ): bool {
		$this->calls[] = 'folder:' . $folder;

		return ! in_array( 'ensureFolder', $this->refuses, true );
	}

	public function files( string $folder ): ?array {
		if ( in_array( 'files', $this->refuses, true ) ) {
			return null;
		}
		$prefix = '' === $folder ? '' : rtrim( $folder, '/' ) . '/';
		$all    = [];
		foreach ( $this->files as $path => $bytes ) {
			$name = substr( (string) $path, strlen( $prefix ) );
			if ( str_starts_with( (string) $path, $prefix ) && ! str_contains( $name, '/' ) ) {
				$all[ $name ] = [
					'size'  => strlen( $bytes ),
					'mtime' => 1760000000,
				];
			}
		}

		return $all;
	}

	public function size( string $path ): int {
		return isset( $this->files[ $path ] ) ? strlen( $this->files[ $path ] ) : -1;
	}

	public function write( string $path, string $bytes, int $offset ): bool {
		$this->calls[] = 'write:' . $path . '@' . $offset . '+' . strlen( $bytes );
		if ( in_array( 'write', $this->refuses, true ) ) {
			return false;
		}
		$old                  = $this->files[ $path ] ?? '';
		$this->files[ $path ] = substr( str_pad( $old, $offset, "\0" ), 0, $offset ) . $bytes . (string) substr( $old, $offset + strlen( $bytes ) );

		return true;
	}

	public function read( string $path, int $offset, int $length ): ?string {
		return isset( $this->files[ $path ] ) && ! in_array( 'read', $this->refuses, true ) ? substr( $this->files[ $path ], $offset, $length ) : null;
	}

	public function rename( string $from, string $to ): bool {
		$this->calls[] = 'rename:' . $from . '>' . $to;
		if ( ! isset( $this->files[ $from ] ) || in_array( 'rename', $this->refuses, true ) ) {
			return false;
		}
		$this->files[ $to ] = $this->files[ $from ];
		unset( $this->files[ $from ] );

		return true;
	}

	public function delete( string $path ): bool {
		$this->calls[] = 'delete:' . $path;
		if ( ! isset( $this->files[ $path ] ) || in_array( 'delete', $this->refuses, true ) ) {
			return false;
		}
		unset( $this->files[ $path ] );

		return true;
	}

	public function close(): void {
		$this->closed = true;
	}
}
