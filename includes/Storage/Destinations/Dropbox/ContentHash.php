<?php
/**
 * Dropbox's content hash of a file.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\Dropbox;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. docs.dropboxapi.com/dropbox-api/docs/technical-reference/content-hash
 * (read 2026-10-14): the file is split into 4 MiB blocks (the last may be
 * shorter, an empty file has none), each block's SHA-256 is taken, the
 * binary digests are joined, and the SHA-256 of that, in hex, is the
 * content_hash of the file's metadata. An upload keeps the joined digests
 * of what it sent (every part but the last starts and ends on a block
 * boundary), so the hash of the whole file needs no second read.
 */
final class ContentHash {

	public const BLOCK = 4194304;

	/**
	 * @param string $bytes Bytes starting on a block boundary.
	 * @return string The joined binary digests of their blocks.
	 */
	public static function blocks( string $bytes ): string {
		$digests = '';
		$length  = strlen( $bytes );
		for ( $at = 0; $at < $length; $at += self::BLOCK ) {
			$digests .= hash( 'sha256', substr( $bytes, $at, self::BLOCK ), true );
		}

		return $digests;
	}

	/**
	 * @param string $blocks The joined binary digests of every block of a file.
	 * @return string Its content_hash (64 hex).
	 */
	public static function of( string $blocks ): string {
		return hash( 'sha256', $blocks );
	}

	/**
	 * @param int $bytes A length from the start of a file.
	 * @return int Blocks in it.
	 */
	public static function count( int $bytes ): int {
		return intdiv( max( 0, $bytes ) + self::BLOCK - 1, self::BLOCK );
	}
}
