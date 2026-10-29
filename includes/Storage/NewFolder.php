<?php
/**
 * Prepares a backups folder together with the typed folder that holds it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * A new typed folder outside a web root is only the parent of the random-named
 * backups folder, so it is made first (one level, the same checks and mode as
 * the child), and removed again when preparing the child fails. Nothing else
 * is ever created above the child. WordPress-free.
 */
final class NewFolder {

	/**
	 * @see MarkedFolder::prepare()
	 * @param string          $path   Canonical folder from check().
	 * @param string          $marker This site's marker.
	 * @param array<int, int> $owners Allowed owners (StorageDir::owners()).
	 * @param int|null        $euid   The process's user (tests); null = posix_geteuid().
	 * @return array<int, string> Deny files left alone.
	 * @throws Refused When the folder cannot be used after all.
	 */
	public static function prepare( string $path, string $marker, array $owners = [], ?int $euid = null ): array {
		$parent = dirname( $path );
		$made   = null;
		clearstatcache( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$missing = 1 === preg_match( BackupDirRules::SUFFIX_PATTERN, basename( $path ) ) && ! @file_exists( $parent ) && ! @is_link( $parent );
		// Root never creates (MarkedFolder::prepare() reports RUN_AS_OWNER).
		if ( $missing && ! MarkedFolder::asRoot( $owners, $euid ) && MarkedFolder::makeFolder( $parent ) ) {
			$made = FolderIdentity::of( $parent );
		}
		try {
			return MarkedFolder::prepare( $path, $marker, $owners, $euid );
		} catch ( Refused $e ) {
			clearstatcache( true );
			if ( null !== $made && realpath( $parent ) === $parent && FolderIdentity::of( $parent ) === $made ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only our new folder, and only while it is empty.
				@rmdir( $parent );
			}
			throw $e;
		}
	}
}
