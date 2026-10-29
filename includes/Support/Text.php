<?php
/**
 * Short, JSON-safe text for warnings and errors.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Messages quote SQL and server text, which may hold any bytes; job files
 * are JSON, which needs valid UTF-8.
 */
final class Text {

	/**
	 * At most $max bytes of valid UTF-8.
	 *
	 * @param string $text Any bytes.
	 * @param int    $max  Byte limit.
	 * @return string
	 */
	public static function clip( string $text, int $max ): string {
		$cut = substr( $text, 0, $max );
		if ( 1 === preg_match( '//u', $cut ) ) {
			return $cut;
		}
		// A multibyte character cut in half at the end.
		$trimmed = (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut );
		if ( 1 === preg_match( '//u', $trimmed ) ) {
			return $trimmed;
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '?', $cut );
	}
}
