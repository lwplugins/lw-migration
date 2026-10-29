<?php
/**
 * The typed folder that only holds the backups folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * A typed backups folder is only the parent of the folder that gets the random
 * name, unless it is a folder this site marked before (chosen earlier, it holds
 * backups and stays as it is). Another site's backups folder, or anything
 * written under the marker's name, is never a parent. WordPress-free.
 */
final class ParentFolder {

	/**
	 * An existing folder that carries this site's marker.
	 *
	 * @param string $path   Canonical path.
	 * @param string $marker This site's marker ('' = none yet: nothing is ours).
	 */
	public static function isOurs( string $path, string $marker ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		return '' !== $marker && @is_dir( $path ) && MarkedFolder::markerOf( $path ) === $marker;
	}

	/**
	 * An existing folder this site did not mark (also an empty or foreign one).
	 *
	 * @param string $path   Canonical path.
	 * @param string $marker This site's marker.
	 */
	public static function isOther( string $path, string $marker ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		return @is_dir( $path ) && ! self::isOurs( $path, $marker );
	}

	/**
	 * @param string $path   Canonical path.
	 * @param string $marker This site's marker.
	 * @throws Refused FOREIGN.
	 */
	public static function refuseForeign( string $path, string $marker ): void {
		$file = $path . '/' . MarkedFolder::MARKER;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( ( @file_exists( $file ) || @is_link( $file ) ) && ! self::isOurs( $path, $marker ) ) {
			throw new Refused( Refused::FOREIGN );
		}
	}
}
