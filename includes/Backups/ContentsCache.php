<?php
/**
 * What each backup holds, kept so a second look costs nothing.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

use LightweightPlugins\Migration\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option row, never autoloaded: "folder:name" => {fingerprint, …}. An
 * entry answers only for the file it was made from (Fingerprint: size, mtime
 * and inode), so a file replaced under the same name is read again and a
 * deleted one is never asked for (the backup is looked up first). At most
 * MAX_ENTRIES are kept; the one written last is the newest, the oldest go
 * first. A lost write (two requests at once) only costs a read of the
 * headers again.
 */
final class ContentsCache {

	public const MAX_ENTRIES = 50;

	/**
	 * @param string $folder      Backup::OWN or Backup::EXTERNAL.
	 * @param string $name        Backup file name.
	 * @param string $fingerprint Fingerprint of the file now.
	 * @return array<string, mixed>|null The stored entry without its fingerprint, null on a miss.
	 */
	public static function get( string $folder, string $name, string $fingerprint ): ?array {
		$entry = self::all()[ $folder . ':' . $name ] ?? null;
		if ( ! is_array( $entry ) || '' === $fingerprint || ! is_string( $entry['fingerprint'] ?? null ) || ! hash_equals( $entry['fingerprint'], $fingerprint ) ) {
			return null;
		}
		unset( $entry['fingerprint'] );

		return $entry;
	}

	/**
	 * @param string               $folder      Backup::OWN or Backup::EXTERNAL.
	 * @param string               $name        Backup file name.
	 * @param string               $fingerprint Fingerprint of the file the entry describes.
	 * @param array<string, mixed> $entry       What to keep.
	 */
	public static function put( string $folder, string $name, string $fingerprint, array $entry ): void {
		$all = self::all();
		$key = $folder . ':' . $name;
		unset( $all[ $key ] );
		$all[ $key ] = [ 'fingerprint' => $fingerprint ] + $entry;
		update_option( Options::BACKUP_CONTENTS, array_slice( $all, -self::MAX_ENTRIES, null, true ), false );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function all(): array {
		$all = get_option( Options::BACKUP_CONTENTS, [] );

		return is_array( $all ) ? $all : [];
	}
}
