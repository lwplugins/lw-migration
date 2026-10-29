<?php
/**
 * A destination that reads each part from the backup file itself.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Plan D21. The upload step calls uploadFrom() instead of reading the part
 * into memory and calling uploadChunk(): the destination streams exactly
 * $s->nextLength() bytes from $s->offset of the file at $path (WebDAV hands
 * the open file to cURL). uploadChunk() stays for small string bodies (the
 * connection test). The same rules hold as for uploadChunk(): sending the
 * part at the same offset again must be safe. A file with fewer than
 * nextLength() bytes from the offset throws CloudJobException before anything
 * is sent (like CloudUploadStep::read()): a server never gets a short body.
 * A file that shrinks after it was opened makes that request fail as a
 * transient NETWORK failure (cURL runs out of bytes); the next attempt then
 * finds it short and throws CloudJobException.
 */
interface FileUpload {

	/**
	 * @param UploadSession $s    The session as last returned (not complete).
	 * @param string        $path The local backup file.
	 * @return UploadSession The session after this part.
	 * @throws CloudFailure When the part did not arrive.
	 * @throws \LightweightPlugins\Migration\Storage\Cloud\CloudJobException When the local file cannot be read.
	 */
	public function uploadFrom( UploadSession $s, string $path ): UploadSession;
}
