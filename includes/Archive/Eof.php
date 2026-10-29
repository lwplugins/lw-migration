<?php
/**
 * End-of-archive block.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * V1: 4377 NUL bytes. V2: pack('a255a14a4100a8', '', size, '', crc).
 */
final class Eof {

	private const V2_FORMAT = 'a255a14a4100a8';

	public static function v1(): string {
		return str_repeat( "\0", Format::HEADER_SIZE );
	}

	public static function v2( int $archiveSize, string $crc ): string {
		return pack( self::V2_FORMAT, '', (string) $archiveSize, '', strtolower( $crc ) );
	}

	public static function parse( string $block ): ?EofInfo {
		if ( Format::HEADER_SIZE !== strlen( $block ) ) {
			return null;
		}
		if ( self::v1() === $block ) {
			return new EofInfo( 1, null, null );
		}

		$data = (array) unpack( 'a255lead/a14size/a4100pad/a8crc', $block );
		$size = rtrim( (string) ( $data['size'] ?? '' ), "\0" );
		$crc  = rtrim( (string) ( $data['crc'] ?? '' ), "\0" );

		if ( ! ctype_digit( $size ) || 1 !== preg_match( '/^[0-9a-f]{8}$/i', $crc ) ) {
			return null;
		}
		if ( pack( self::V2_FORMAT, '', $size, '', $crc ) !== $block ) {
			return null;
		}

		return new EofInfo( 2, (int) $size, strtolower( $crc ) );
	}
}
