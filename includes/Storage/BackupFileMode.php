<?php
/**
 * The permissions of a published backup file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Owner read and write only, by default. Backups are downloaded through PHP, so
 * the web server needs no read access of its own; a host that does can allow
 * group read with the lw_migration_backup_file_mode filter. Whatever the filter
 * returns is clamped: the owner always keeps read and write, nobody else gets
 * more than read (0640 at most), and no other bit is ever set.
 */
final class BackupFileMode {

	/** The default mode of a backup file. */
	public const DEFAULT = 0600;

	/** What the filter may add: group read. */
	private const MAX = 0640;

	/** What the owner must keep. */
	private const OWNER = 0600;

	/**
	 * @return int The mode to give a backup file.
	 */
	public static function get(): int {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::DEFAULT; // WordPress-free callers (unit tests).
		}

		return self::clamp( (int) apply_filters( 'lw_migration_backup_file_mode', self::DEFAULT ) );
	}

	/**
	 * @param int $mode A requested mode.
	 * @return int The mode with only the allowed bits, owner read and write always on.
	 */
	public static function clamp( int $mode ): int {
		return ( $mode & self::MAX ) | self::OWNER;
	}

	/**
	 * Sets the mode of a file the plugin just made.
	 *
	 * @param string $path File.
	 * @return void
	 */
	public static function apply( string $path ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; a host that refuses chmod keeps the umask default.
		@chmod( $path, self::get() );
	}
}
