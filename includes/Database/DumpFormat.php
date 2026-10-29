<?php
/**
 * Text blocks of database.sql.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Byte layout identical to existing .wpress dumps (except our own header text).
 */
final class DumpFormat {

	public const START  = "START TRANSACTION;\n";
	public const COMMIT = "COMMIT;\n";

	public static function header( string $host, string $database, string $driver ): string {
		return "-- LW Migration SQL Dump\n-- https://lwplugins.com/\n--\n"
			. "-- Host: {$host}\n-- Database: {$database}\n-- Class: {$driver}\n--\n";
	}

	public static function table( string $name, string $create ): string {
		return "\nDROP TABLE IF EXISTS " . self::quoteIdentifier( $name ) . ";\n{$create};\n\n";
	}

	public static function view( string $name, string $create ): string {
		return "\nDROP VIEW IF EXISTS " . self::quoteIdentifier( $name ) . ";\n{$create};\n\n";
	}

	/**
	 * @param string             $table  Exported table name.
	 * @param array<int, string> $values  Encoded values.
	 * @param array<int, string> $columns Column names; empty writes no column list.
	 * @return string
	 */
	public static function insert( string $table, array $values, array $columns = [] ): string {
		$list = [] === $columns ? '' : ' (' . implode( ',', array_map( [ self::class, 'quoteIdentifier' ], $columns ) ) . ')';

		return 'INSERT INTO ' . self::quoteIdentifier( $table ) . $list . ' VALUES (' . implode( ',', $values ) . ");\n";
	}

	/**
	 * Replaces the first `old` identifier (case-insensitive) with `new`.
	 *
	 * @param string $create CREATE statement.
	 * @param string $old    Old name.
	 * @param string $new    New name.
	 * @return string
	 */
	public static function renameFirst( string $create, string $old, string $new ): string {
		$needle = self::quoteIdentifier( $old );
		$pos    = stripos( $create, $needle );
		if ( false === $pos ) {
			return $create;
		}

		return substr_replace( $create, self::quoteIdentifier( $new ), $pos, strlen( $needle ) );
	}

	public static function quoteIdentifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
