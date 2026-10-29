<?php
/**
 * A destination that can say how much of an upload it has.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * M7c plan E9. CloudUploadStep asks this before the next part when the last
 * call died or failed in passing (Storage\Cloud\ResumeCheck), so a part the
 * provider already stored is not sent from a stale offset. The answer may be
 * a complete session; the step refuses one that is not the saved upload or
 * lies past what was sent.
 */
interface ResumesUpload {

	/**
	 * @param UploadSession $s The saved session.
	 * @return UploadSession The session at the provider's offset.
	 * @throws CloudFailure SESSION_GONE when the provider forgot the upload.
	 */
	public function resume( UploadSession $s ): UploadSession;
}
