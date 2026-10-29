<?php
/**
 * MySQL string literal escaping.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The exact mapping of mysqli_real_escape_string, independent of the link charset.
 */
final class SqlEscape {

	private const MAP = [
		"\x00" => '\\0',
		"\n"   => '\\n',
		"\r"   => '\\r',
		'\\'   => '\\\\',
		"'"    => "\\'",
		'"'    => '\\"',
		"\x1a" => '\\Z',
	];

	public static function escape( string $value ): string {
		return strtr( $value, self::MAP );
	}

	/**
	 * Inverse of a MySQL literal body: the MAP escapes, \t, \b, any other \x as x (except \% and \_, which keep the backslash), and '' as one quote.
	 *
	 * @param string $value Literal body without the surrounding quotes.
	 * @return string
	 */
	public static function unescape( string $value ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [ "''" => "'" ];
			for ( $i = 0; $i < 256; $i++ ) {
				$map[ '\\' . chr( $i ) ] = chr( $i );
			}
			$map = array_flip( self::MAP ) + [
				'\\t' => "\t",
				'\\b' => "\x08",
				'\\%' => '\\%',
				'\\_' => '\\_',
			] + $map;
		}

		return strtr( $value, $map );
	}
}
