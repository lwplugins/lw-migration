<?php
/**
 * Work file names of an import job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Import;

use LightweightPlugins\Migration\Archive\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Files and folders inside the job's work folder.
 */
final class ImportFiles {

	public const DOWNLOAD = 'download.wpress';

	/** An archive uploaded by the browser (always owned by the job). */
	public const UPLOAD = 'upload.wpress';

	public const DATABASE = Format::DATABASE;

	public const DEFERRED = 'deferred.sql';

	/** Written right before the RENAME, so a replay knows the swap may already have happened (SwapStep). */
	public const SWAP_STARTED = 'swap-started';

	public const STAGING = 'mu-plugins-staging';

	/** Journal of the site folders entries were extracted into (ImportTemps). */
	public const EXTRACT_DIRS = 'extract-dirs.list';

	/** Work sub-folder of the nested safety export. */
	public const SAFETY = 'safety';
}
