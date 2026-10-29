<?php
/**
 * One folder on a file server (FTP, SFTP).
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D35: what FolderDestination needs of a connected, logged-in folder.
 * Names are plain file names in that folder. A write at an offset
 * overwrites from there without truncating (REST+STOR on vsftpd, SFTP
 * writes with an offset), so sending a part again is harmless; write() is
 * never called past the current end. A rename may
 * replace an existing target on some servers; FolderDestination never asks
 * for one onto a name that exists. size() answers -1 for a missing file,
 * read() at most $length bytes, delete() false when the file was not there.
 * Every method but close() may throw a CloudFailure; the caller always
 * closes.
 */
interface RemoteFolder {

	/**
	 * @return array<string, array{size: int, mtime: int}> Regular files directly in the folder (an implementation may leave out names that cannot be backups).
	 */
	public function files(): array;

	public function size( string $name ): int;

	public function write( string $name, string $bytes, int $offset ): void;

	public function read( string $name, int $offset, int $length ): string;

	public function rename( string $from, string $to ): void;

	public function delete( string $name ): bool;

	public function close(): void;
}
