<?php
/**
 * Which file a name holds, compared the way the file system compares.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage;

use LightweightPlugins\Migration\Support\Deadline;
use LightweightPlugins\Migration\Support\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Size is not identity, and neither is device:inode alone: FAT numbers a file
 * by its start cluster, which the next file written gets right after a delete
 * (and every empty file shares). A regular file (links not followed) is
 * therefore named by the whole tuple {device:inode, size, mtime}, compared as
 * a whole wherever identity decides something. A move removes its
 * original only through removeOriginal(), and a name found in two folders is
 * one backup only when same() says so. Every stat is silenced (a name outside
 * open_basedir is simply unknown, never a warning with the path). Failures
 * carry FileMover codes. WordPress-free.
 */
final class FileIdentity {

	/** Seconds two copies' mtimes may differ (FAT's 2-second granularity). */
	public const MTIME_SLACK = 2;

	/**
	 * @param string $path File.
	 * @return array{id: string, size: int, mtime: int}|null Null when it is no regular file.
	 */
	public static function of( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- outside open_basedir is unknown, never a warning with the path.
		$stat = @lstat( $path );
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return null;
		}

		return [
			'id'    => $stat['dev'] . ':' . $stat['ino'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
		];
	}

	/**
	 * Removes the original of a finished move, only while the published name
	 * still holds the copy (link and rename keep its inode) and the original is
	 * unchanged. An original already gone is no failure (a request died after
	 * removing it).
	 *
	 * @param string                                   $original  The source of the copy.
	 * @param array{id: string, size: int, mtime: int} $was       FileMover's cursor 'source'.
	 * @param string                                   $published The name the copy got.
	 * @param array{id: string, size: int, mtime: int} $copy      FileMover's cursor 'id'.
	 * @throws StorageException MISMATCH (nothing removed) or IO.
	 */
	public static function removeOriginal( string $original, array $was, string $published, array $copy ): void {
		clearstatcache( true ); // The realpath cache too: another process may have swapped a folder.
		$now = self::of( $original );
		if ( self::of( $published ) !== $copy || ( null !== $now && $now !== $was ) ) {
			throw new StorageException( 'The copy does not match the original.', FileMover::MISMATCH );
		}
		if ( null !== $now && ! Files::removeFile( $original ) ) {
			throw new StorageException( 'Cannot remove the original.', FileMover::IO );
		}
	}

	/**
	 * Whether two names hold the same backup: the same identity tuple, or
	 * equal size and bytes (compared in slices; a call that runs out of time
	 * saves its offset in $cursor, and a file that changed meanwhile starts it
	 * over). The mtime is only a hint: FAT keeps it to 2 seconds, so a copy
	 * may differ by that much; more than that is another file.
	 *
	 * @param string               $a        Regular file.
	 * @param string               $b        Regular file.
	 * @param array<string, mixed> $cursor   State between calls; start with [].
	 * @param Deadline             $deadline Time budget.
	 * @param int                  $chunk    Bytes per read.
	 * @return bool|null Null when the compare needs another call.
	 * @throws StorageException MISSING or IO.
	 */
	public static function same( string $a, string $b, array &$cursor, Deadline $deadline, int $chunk = FileMover::CHUNK ): ?bool {
		clearstatcache( true );
		$x = self::of( $a );
		$y = self::of( $b );
		if ( null === $x || null === $y ) {
			throw new StorageException( 'A file to compare is missing.', FileMover::MISSING );
		}
		if ( $x === $y ) {
			return true;
		}
		if ( $x['size'] !== $y['size'] || abs( $x['mtime'] - $y['mtime'] ) > self::MTIME_SLACK ) {
			return false;
		}
		if ( ( $cursor['a'] ?? null ) !== $x || ( $cursor['b'] ?? null ) !== $y ) {
			$cursor = [
				'a'  => $x,
				'b'  => $y,
				'at' => 0,
			];
		}
		$at = self::compare( $a, $b, (int) $cursor['at'], $x['size'], $deadline, $chunk );
		if ( null === $at ) {
			return false;
		}
		$cursor['at'] = $at;

		return $at < $x['size'] ? null : true;
	}

	/**
	 * @param string   $a        File.
	 * @param string   $b        File.
	 * @param int      $at       Offset to start at.
	 * @param int      $size     Size of both.
	 * @param Deadline $deadline Time budget.
	 * @param int      $chunk    Bytes per read.
	 * @return int|null The offset reached, or null when the bytes differ.
	 * @throws StorageException IO.
	 */
	private static function compare( string $a, string $b, int $at, int $size, Deadline $deadline, int $chunk ): ?int {
		$one = FileMover::open( $a, 'rb' );
		$two = FileMover::open( $b, 'rb' );
		try {
			if ( 0 !== fseek( $one, $at ) || 0 !== fseek( $two, $at ) ) {
				throw new StorageException( 'Cannot read a file to compare.', FileMover::IO );
			}
			while ( $at < $size ) {
				$length = min( $chunk, $size - $at );
				$data   = FileMover::read( $one, $length );
				if ( strlen( $data ) !== $length || FileMover::read( $two, $length ) !== $data ) {
					return null;
				}
				$at += $length;
				if ( $deadline->reached() ) {
					break;
				}
			}
		} finally {
			fclose( $one ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $two ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return $at;
	}
}
