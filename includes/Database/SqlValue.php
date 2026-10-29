<?php
/**
 * Encodes one column value for an INSERT statement.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Numeric types raw, binary types as 0x-hex, everything else quoted+escaped.
 * BIT arrives as an unsigned integer (RowSelector casts it) and is written as a
 * decimal literal, valid for BIT columns; the reference dump writes raw bytes.
 */
final class SqlValue {

	private const NUMERIC = [ 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'float', 'double', 'decimal', 'bit' ];

	private const BINARY = [ 'binary', 'varbinary', 'tinyblob', 'mediumblob', 'longblob', 'blob', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection' ];

	public static function encode( ?string $value, string $columnType ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$type = strtolower( $columnType );
		foreach ( self::NUMERIC as $numeric ) {
			if ( str_starts_with( $type, $numeric ) ) {
				return $value;
			}
		}
		foreach ( self::BINARY as $binary ) {
			if ( str_starts_with( $type, $binary ) ) {
				return '' === $value ? "''" : '0x' . bin2hex( $value );
			}
		}

		return "'" . SqlEscape::escape( $value ) . "'";
	}
}
