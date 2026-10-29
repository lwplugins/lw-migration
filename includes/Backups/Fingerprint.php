<?php
/**
 * The fingerprint of a backup file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Size, mtime and inode of a file, hashed: the tuple the download ETag is
 * made of, and what a transfer link is bound to. A different file under the
 * same name (fetched from the cloud, written by another tool) has another
 * inode even when its size and mtime happen to match.
 */
final class Fingerprint {

	/**
	 * @param array<int|string, int> $stat stat() of the file.
	 * @return string 32 hex characters.
	 */
	public static function fromStat( array $stat ): string {
		return md5( $stat['size'] . '-' . (int) $stat['mtime'] . '-' . $stat['ino'] );
	}

	/**
	 * @param string $path Absolute path of a regular file.
	 * @return string Empty when the file cannot be read.
	 */
	public static function of( string $path ): string {
		clearstatcache( true, $path );
		$stat = is_file( $path ) ? stat( $path ) : false;

		return false === $stat ? '' : self::fromStat( $stat );
	}
}
