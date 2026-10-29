<?php
/**
 * Work file names of an export job.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Export;

use LightweightPlugins\Migration\Archive\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Files inside the job's work folder.
 */
final class ExportFiles {

	public const ARCHIVE = 'archive.wpress';

	public const LIST = 'files.list';

	public const DATABASE = Format::DATABASE;

	/** The sites of a network backup, one multisite.json Sites[] entry (JSON) per line. */
	public const NETWORK_SITES = 'network-sites.jsonl';

	/**
	 * Deletes the work files of an export (after it finished, failed or was
	 * cancelled), including the visited-folders side file of the list and the
	 * site list of a network backup. A file
	 * in a folder that has become read-only stays, without a PHP warning.
	 *
	 * @param string $dir Work folder.
	 * @return void
	 */
	public static function remove( string $dir ): void {
		foreach ( [ self::LIST, self::LIST . FileEnumerator::VISITED_SUFFIX, self::DATABASE, self::ARCHIVE, self::NETWORK_SITES ] as $file ) {
			if ( is_file( $dir . '/' . $file ) && is_writable( $dir ) ) {
				unlink( $dir . '/' . $file );
			}
		}
	}
}
