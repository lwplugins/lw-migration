<?php
/**
 * What the SFTP destination asks of a session.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\Common\PrivateKey;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D35: one SSH connection. hostKey() connects (key exchange) and
 * answers "<type> <base64 blob>" of the verified host key; it is asked
 * before login(). Paths are relative to the login folder unless they start
 * with "/"; ensureFolder( '' ) is the login folder. A write at an offset
 * overwrites without truncating; read() answers at most $length bytes;
 * size() -1 for no file. A refusal answers false/null/-1, a failed
 * connection throws a CloudFailure; close() never throws.
 */
interface SftpSession {

	public function hostKey(): string;

	public function login( string $user, #[\SensitiveParameter] string|PrivateKey $credential ): bool;

	public function ensureFolder( string $folder ): bool;

	/**
	 * @param string $folder Folder ('' = the login folder).
	 * @return array<string, array{size: int, mtime: int}>|null Regular files directly in the folder; null on failure.
	 */
	public function files( string $folder ): ?array;

	public function size( string $path ): int;

	public function write( string $path, string $bytes, int $offset ): bool;

	public function read( string $path, int $offset, int $length ): ?string;

	public function rename( string $from, string $to ): bool;

	public function delete( string $path ): bool;

	public function close(): void;
}
