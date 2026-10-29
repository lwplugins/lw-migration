<?php
/**
 * What the FTP destination asks of a connection.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Ftp;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D35: a connected, logged-in connection standing in the destination's
 * folder: ExtFtpConnection (plain FTP) or CurlFtpsConnection (FTPS, issue
 * #5). They answer true/false; the meaning of a false is decided by
 * FtpPartWriter and FtpFolder. A connection that breaks (a timeout, a reset,
 * a failed TLS read) throws TransientFailure NETWORK instead of answering.
 * size(): SIZE, -1 when missing or not told; mtime(): MDTM, -1 when unknown;
 * store(): STOR at offset 0, REST <offset> + STOR otherwise (FTP_AUTOSEEK
 * off); append(): APPE; read(): at most $length bytes from $offset, null on
 * failure; a server refusing to start inside an existing file (REST) throws
 * PermanentFailure INVALID: backups there could never be restored.
 */
interface FtpConnection {

	/** @return array<int, string> The FEAT lines (empty when FEAT is not supported). */
	public function features(): array;

	public function size( string $name ): int;

	public function mtime( string $name ): int;

	/** @return array<int, string>|null Plain names in the folder (NLST), null on failure. */
	public function names(): ?array;

	public function store( string $name, string $bytes, int $offset ): bool;

	public function append( string $name, string $bytes ): bool;

	public function read( string $name, int $offset, int $length ): ?string;

	public function rename( string $from, string $to ): bool;

	public function delete( string $name ): bool;

	public function close(): void;
}
