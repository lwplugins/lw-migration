<?php
/**
 * What every cloud destination type can do.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

// Over the interface line limit on purpose: the docblocks are the contract every destination type implements (about 10 lines of declarations).

/**
 * Spec 21.2. An upload is a session that the caller keeps between requests
 * (UploadSession::toArray() in the job's cursor): start it once, send the
 * next part of exactly UploadSession::nextLength() bytes until it is
 * complete, then finish it. Sending the part at the same offset again after
 * a failure or a dead request must be safe. Every remote problem is a
 * TransientFailure (try again later) or a PermanentFailure (stop this
 * destination); a programming error (a part of the wrong length, finishing
 * an incomplete session) is a \LogicException.
 */
interface DestinationInterface {

	/**
	 * Writes, reads back and deletes a small test file (ProbeFile).
	 *
	 * @throws CloudFailure When the destination does not work.
	 */
	public function test(): void;

	/**
	 * @param string $name File name at the destination (a backup name or ProbeFile::name()).
	 * @param int    $size Bytes that will be sent (> 0).
	 * @throws CloudFailure When the upload cannot start.
	 */
	public function startUpload( string $name, int $size ): UploadSession;

	/**
	 * @param UploadSession $s     The session as last returned.
	 * @param string        $bytes Exactly $s->nextLength() bytes from $s->offset.
	 * @return UploadSession The session after these bytes.
	 * @throws CloudFailure When the part did not arrive.
	 * @throws \LogicException On a part of another length.
	 */
	public function uploadChunk( UploadSession $s, string $bytes ): UploadSession;

	/**
	 * @param UploadSession $s A complete session.
	 * @throws CloudFailure When the file cannot be put together.
	 * @throws \LogicException When the session is not complete.
	 */
	public function finishUpload( UploadSession $s ): RemoteFile;

	/**
	 * The files directly in the destination's folder (any name; callers filter).
	 *
	 * @return array<int, RemoteFile>
	 * @throws CloudFailure When the folder cannot be listed.
	 */
	public function list(): array;

	/**
	 * @param RemoteFile $f A file list() or finishUpload() returned.
	 * @throws CloudFailure When it cannot be deleted (a file already gone is no failure).
	 */
	public function delete( RemoteFile $f ): void;

	/**
	 * @param RemoteFile $f      File.
	 * @param int        $offset First byte.
	 * @param int        $length Bytes wanted (> 0, within the file).
	 * @return string Exactly $length bytes.
	 * @throws CloudFailure When the bytes cannot be read.
	 */
	public function readRange( RemoteFile $f, int $offset, int $length ): string;

	/**
	 * Gives up an unfinished upload (best effort; an upload already gone is no failure).
	 *
	 * @param UploadSession $s Session.
	 * @throws CloudFailure When the destination refuses.
	 */
	public function abort( UploadSession $s ): void;
}
