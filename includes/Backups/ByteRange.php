<?php
/**
 * The Range header of a download.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * One byte range (RFC 9110 14.1.2). Several ranges, another unit or anything
 * malformed is ignored: the whole file is sent, which RFC 9110 allows.
 */
final class ByteRange {

	/**
	 * @param string|null $header Value of the Range header.
	 * @param int         $size   File size in bytes.
	 * @return array{0: int, 1: int}|false|null [first, last] byte; false: unsatisfiable (416); null: the whole file.
	 */
	public static function parse( ?string $header, int $size ): array|false|null {
		$header = null === $header ? '' : trim( $header );
		if ( 1 !== preg_match( '/^bytes\s*=\s*(\d{0,18})\s*-\s*(\d{0,18})$/i', $header, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null;
		}
		if ( '' === $m[1] ) {
			$suffix = (int) $m[2];

			return 0 === $suffix || 0 === $size ? false : [ max( 0, $size - $suffix ), $size - 1 ];
		}
		$first = (int) $m[1];
		$last  = '' === $m[2] ? $size - 1 : (int) $m[2];
		if ( '' !== $m[2] && $last < $first ) {
			return null;
		}
		if ( $first >= $size ) {
			return false;
		}

		return [ $first, min( $last, $size - 1 ) ];
	}
}
