<?php
/**
 * The open_basedir test of a chosen backups folder.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Storage\FolderRefusedException as Refused;

defined( 'ABSPATH' ) || exit;

/**
 * A string test only (the path is never probed): a folder outside every
 * open_basedir entry, by its name or by the real path of an entry, is refused.
 * WordPress-free.
 */
final class OpenBasedir {

	/**
	 * @param string $path    Typed or canonical path.
	 * @param string $setting The open_basedir setting ('' = none).
	 * @throws Refused OPEN_BASEDIR.
	 */
	public static function refuse( string $path, string $setting ): void {
		if ( '' === trim( $setting ) ) {
			return;
		}
		foreach ( explode( PATH_SEPARATOR, $setting ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry || '.' === $entry ) {
				continue;
			}
			$entry = BackupDirRules::trimmed( $entry );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an entry that cannot be resolved is compared by name only.
			$real = @realpath( $entry );
			if ( BackupDirRules::within( $path, $entry ) || ( false !== $real && BackupDirRules::within( $path, $real ) ) ) {
				return;
			}
		}
		throw new Refused( Refused::OPEN_BASEDIR );
	}
}
