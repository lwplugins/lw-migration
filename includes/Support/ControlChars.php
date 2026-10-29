<?php
/**
 * Control characters shown as visible escapes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Names from an archive or another site may hold any bytes. A terminal acts
 * on control characters (it moves the cursor, erases lines, sets the title
 * or the clipboard), so text meant to be read shows them as `\x1b`, `\x07`
 * or `\u009b` instead. Line breaks and tabs stay; valid UTF-8 (like "ő",
 * whose second byte is 0x91) stays; a byte that is not valid UTF-8 is shown
 * as `\xNN`, so it cannot start a control sequence either.
 */
final class ControlChars {

	/**
	 * A valid UTF-8 sequence of two or more bytes, or any other single byte
	 * that is a control character or not part of valid UTF-8.
	 */
	private const PATTERN = '/[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|[\x00-\x08\x0B-\x1F\x7F-\xFF]/';

	public static function escape( string $text ): string {
		return (string) preg_replace_callback( self::PATTERN, static fn( array $m ): string => self::replace( $m ), $text );
	}

	/**
	 * @param array<int, string> $match The sequence or byte.
	 * @return string
	 */
	private static function replace( array $match ): string {
		$found = $match[0];
		if ( 1 === strlen( $found ) ) {
			return sprintf( '\\x%02x', ord( $found ) );
		}
		if ( "\xC2" === $found[0] && ord( $found[1] ) < 0xA0 ) {
			return sprintf( '\\u%04x', ord( $found[1] ) ); // C1: U+0080 to U+009F.
		}

		return $found;
	}
}
