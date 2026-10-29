<?php
/**
 * Names of our temporary files.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One definition for every temporary file we write next to its final name
 * (extraction targets, staged backups): `.lwm-<16 hex>.part`. Such files are
 * never exported, listed or copied, and only they are swept as leftovers.
 */
final class TempName {

	public const PATTERN = '/^\.lwm-[0-9a-f]{16}\.part\z/';

	/**
	 * A new random temp name, without a folder.
	 */
	public static function name(): string {
		return '.lwm-' . bin2hex( random_bytes( 8 ) ) . '.part';
	}

	/**
	 * @param string $dir Folder.
	 * @return string A new random temp path in $dir.
	 */
	public static function generate( string $dir ): string {
		return rtrim( $dir, '/' ) . '/' . self::name();
	}

	/**
	 * @param string $basename File name without folder.
	 * @return bool True for one of our temporary files.
	 */
	public static function is( string $basename ): bool {
		return 1 === preg_match( self::PATTERN, $basename );
	}
}
