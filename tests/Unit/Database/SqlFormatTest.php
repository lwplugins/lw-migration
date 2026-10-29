<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\SqlEscape;
use LightweightPlugins\Migration\Database\SqlValue;
use PHPUnit\Framework\TestCase;

final class SqlFormatTest extends TestCase {

	public function test_escape_matches_mysqli_map_and_round_trips(): void {
		$raw = "a\x00b\nc\rd\\e'f\"g\x1ah";
		$this->assertSame( 'a\\0b\\nc\\rd\\\\e\\\'f\\"g\\Zh', SqlEscape::escape( $raw ) );
		$this->assertSame( $raw, SqlEscape::unescape( SqlEscape::escape( $raw ) ) );
		$this->assertSame( '\\n', SqlEscape::unescape( '\\\\n' ) );
	}

	public function test_unescape_follows_mysql_semantics(): void {
		$this->assertSame( "a\tb", SqlEscape::unescape( 'a\\tb' ) );
		$this->assertSame( "a\x08b", SqlEscape::unescape( 'a\\bb' ) );
		$this->assertSame( 'axb', SqlEscape::unescape( 'a\\xb' ) );
		$this->assertSame( 'a\\%b\\_c', SqlEscape::unescape( 'a\\%b\\_c' ) );
		$this->assertSame( "it's", SqlEscape::unescape( "it''s" ) );
		$this->assertSame( "it's", SqlEscape::unescape( "it\\'s" ) );
	}

	public function test_value_encoding_by_column_type(): void {
		$this->assertSame( 'NULL', SqlValue::encode( null, 'varchar(10)' ) );
		$this->assertSame( '42', SqlValue::encode( '42', 'bigint(20) unsigned' ) );
		$this->assertSame( '1.50', SqlValue::encode( '1.50', 'decimal(5,2)' ) );
		$this->assertSame( '7', SqlValue::encode( '7', 'INT' ) );
		$this->assertSame( '0x00ff', SqlValue::encode( "\x00\xff", 'longblob' ) );
		$this->assertSame( "''", SqlValue::encode( '', 'varbinary(16)' ) );
		$this->assertSame( '682', SqlValue::encode( '682', 'bit(10)' ) );
		$this->assertSame( '18446744073709551615', SqlValue::encode( '18446744073709551615', 'bit(64)' ) );
		$this->assertSame( 'NULL', SqlValue::encode( null, 'bit(1)' ) );
		$this->assertSame( "'it\\'s'", SqlValue::encode( "it's", 'longtext' ) );
		$this->assertSame( "'2026'", SqlValue::encode( '2026', 'year(4)' ) );
	}

	public function test_spatial_types_are_hex_encoded(): void {
		foreach ( [ 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection' ] as $type ) {
			$this->assertSame( '0x0001ff', SqlValue::encode( "\x00\x01\xff", $type ), $type );
		}
	}

	public function test_identifiers_with_backticks_are_escaped(): void {
		$this->assertSame( "\nDROP TABLE IF EXISTS `a``b`;\nX;\n\n", DumpFormat::table( 'a`b', 'X' ) );
		$this->assertSame( "\nDROP VIEW IF EXISTS `a``b`;\nX;\n\n", DumpFormat::view( 'a`b', 'X' ) );
		$this->assertSame( "INSERT INTO `a``b` VALUES (1);\n", DumpFormat::insert( 'a`b', [ '1' ] ) );
		$this->assertSame( 'CREATE TABLE `n``w` (`x` int)', DumpFormat::renameFirst( 'CREATE TABLE `o``ld` (`x` int)', 'o`ld', 'n`w' ) );
	}

	public function test_dump_blocks(): void {
		$this->assertSame(
			"-- LW Migration SQL Dump\n-- https://lwplugins.com/\n--\n-- Host: h\n-- Database: d\n-- Class: mysqli\n--\n",
			DumpFormat::header( 'h', 'd', 'mysqli' )
		);
		$this->assertSame( "\nDROP TABLE IF EXISTS `p_x`;\nCREATE TABLE `p_x` (a int);\n\n", DumpFormat::table( 'p_x', 'CREATE TABLE `p_x` (a int)' ) );
		$this->assertSame( "\nDROP VIEW IF EXISTS `p_v`;\nCREATE VIEW `p_v` AS select 1;\n\n", DumpFormat::view( 'p_v', 'CREATE VIEW `p_v` AS select 1' ) );
		$this->assertSame( "INSERT INTO `t` VALUES (1,'a',NULL);\n", DumpFormat::insert( 't', [ '1', "'a'", 'NULL' ] ) );
		$this->assertSame( "INSERT INTO `t` (`a`,`b``c`) VALUES (1,2);\n", DumpFormat::insert( 't', [ '1', '2' ], [ 'a', 'b`c' ] ) );
		$this->assertSame( 'CREATE TABLE `new` (`wp_x` int)', DumpFormat::renameFirst( 'CREATE TABLE `WP_X` (`wp_x` int)', 'wp_x', 'new' ) );
		$this->assertSame( '`a``b`', DumpFormat::quoteIdentifier( 'a`b' ) );
	}
}
