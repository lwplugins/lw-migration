<?php
/**
 * database.sql text written the way the plugin's dump writer writes it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\SqlValue;

/**
 * A fixed corpus, the same on every run: every table name suffix with every
 * CREATE TABLE shape (SHOW CREATE TABLE text of MySQL 8 and MariaDB: WordPress
 * core and WooCommerce-style tables, timestamp and UUID defaults, partition
 * and SRID version comments, CHECK constraints, generated columns, a foreign
 * key), written by DumpFormat::table, and row batches (DumpFormat::insert of
 * SqlValue::encode values, with and without a column list, between START
 * TRANSACTION and COMMIT). Names and values carry backticks, quotes,
 * backslashes, all 256 bytes and SQL keywords.
 */
final class DumpStatements {

	private const SUFFIXES = [ 'options', 'posts', 'postmeta', 'wc_orders', 'a`b', 'x y', "\xc3\xa9_t\xc3\xa1bla", 'select', 'drop table x;', 'n--1', '``', 'v#1', 'q\'"\\' ];

	private const TRICKY = [ "it's", '\\', '"', '`', '; DROP TABLE wp_users', 'UNION SELECT 1', '/*!50000 x */', '-- x', '# x', 'ENGINE=MERGE', "\xc3\xa9", "\xc2\xa0", "\x00", "\n", "\r\n", "\x1a", "''", '\\\'', ' ) VALUES (', 'SERVMASK_PREFIX_x' ];

	private const TYPES = [ 'bigint(20) unsigned', 'int', 'double', 'float', 'decimal(10,2)', 'bit(1)', 'longtext', 'varchar(191)', 'datetime', 'longblob', 'varbinary(16)', 'json' ];

	private const INTEGERS = [ '0', '1', '-7', '18446744073709551615', '424242' ];

	private const DECIMALS = [ '-0', '1.5e20', '1.5E+20', '-1.25e-7', '-12.50', '0.000', '3.4028234663852886e38' ];

	private const COLUMNS = [ 'id', 'c`d', 'option_value', 'select', "\xc3\xa9", 'a b' ];

	private const SHAPES = 9;

	/**
	 * @param array<int, string> $prefixes Exported table prefixes.
	 */
	public function dump( array $prefixes ): string {
		$out = DumpFormat::header( 'localhost', 'wp', 'mysqli' );
		$k   = 0;
		foreach ( $prefixes as $prefix ) {
			foreach ( self::SUFFIXES as $suffix ) {
				for ( $shape = 0; $shape < self::SHAPES; $shape++, $k++ ) {
					$name = $prefix . $suffix . ( 0 === $k % 2 ? '_' . $k : '' );
					$out .= DumpFormat::table( $name, $this->create( $name, $shape, $this->text( $k ) ) );
					$out .= $this->rows( $name, $k );
				}
			}
		}

		return $out;
	}

	private function create( string $name, int $shape, string $s ): string {
		$n = DumpFormat::quoteIdentifier( $name );
		switch ( $shape ) {
			case 0:
				return "CREATE TABLE {$n} (\n  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '{$s}',\n  `option_value` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  `autoload` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'yes',\n  PRIMARY KEY (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`),\n  KEY `autoload` (`autoload`)\n) ENGINE=InnoDB AUTO_INCREMENT=96838 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
			case 1:
				$engine = [ 'InnoDB', 'Aria', 'MyISAM' ][ strlen( $name ) % 3 ];

				return "CREATE TABLE {$n} (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\n  `post_content` longtext NOT NULL,\n  `post_title` text NOT NULL COMMENT '{$s}',\n  `menu_order` int(11) NOT NULL DEFAULT 0,\n  `rating` decimal(10,2) DEFAULT -1.50,\n  `flags` bit(2) DEFAULT b'01',\n  `mask` varbinary(4) DEFAULT 0x1f,\n  PRIMARY KEY (`ID`),\n  KEY `type_date` (`post_date`,`ID`),\n  FULLTEXT KEY `ft` (`post_title`)\n) ENGINE={$engine} AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci PAGE_CHECKSUM=1 COMMENT='{$s}'";
			case 2:
				return "CREATE TABLE {$n} (\n  `id` int NOT NULL,\n  `code` varchar(20) NOT NULL,\n  `is_a` tinyint GENERATED ALWAYS AS ((`code` like _utf8mb4'a%')) STORED,\n  `label` varchar(40) GENERATED ALWAYS AS (concat(`code`,_utf8mb4'-',`id`)) VIRTUAL,\n  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  `doc` json DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `chk_1` CHECK ((`code` like _utf8mb4'{$s}%'))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci\n/*!50100 PARTITION BY HASH (`id`)\nPARTITIONS 4 */";
			case 3:
				return "CREATE TABLE {$n} (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `order_id` bigint unsigned NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `order_id` (`order_id`),\n  CONSTRAINT `fk_1` FOREIGN KEY (`order_id`) REFERENCES {$n} (`id`) ON DELETE CASCADE\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
			case 4:
				return "CREATE TABLE {$n} (\n  `k` varchar(64) NOT NULL DEFAULT '{$s}',\n  `v` varbinary(255) DEFAULT NULL,\n  PRIMARY KEY (`k`) USING HASH\n) ENGINE=MEMORY DEFAULT CHARSET=latin1";
			case 5:
				return "CREATE TABLE {$n} (\n  `id` int NOT NULL,\n  `g` point NOT NULL /*!80003 SRID 4326 */,\n  `token` varchar(36) DEFAULT (uuid()),\n  `bin` binary(16) DEFAULT (uuid_to_bin(uuid(),1)),\n  `day` date DEFAULT (curdate()),\n  `label` varchar(20) DEFAULT (_utf8mb4'{$s}'),\n  PRIMARY KEY (`id`),\n  SPATIAL KEY `g` (`g`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC";
			case 6:
				return "CREATE TABLE {$n} (\n  `id` uuid NOT NULL DEFAULT uuid(),\n  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),\n  `created` datetime NOT NULL DEFAULT current_timestamp(),\n  `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n  `note` varchar(64) DEFAULT '{$s}',\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
			case 7:
				return "CREATE TABLE {$n} (\n  `id` bigint(20) unsigned NOT NULL,\n  `status` varchar(20) DEFAULT NULL,\n  `date_created_gmt` datetime DEFAULT NULL,\n  `date_updated_gmt` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),\n  `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  `total_amount` decimal(26,8) DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  KEY `status` (`status`),\n  KEY `date_created` (`date_created_gmt`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
			default:
				return "CREATE TABLE {$n} (\n  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',\n  `meta_key` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,\n  `meta_value` longtext COLLATE utf8mb4_unicode_520_ci,\n  PRIMARY KEY (`meta_id`),\n  KEY `post_id` (`post_id`),\n  KEY `meta_key` (`meta_key`(191))\n) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
		}
	}

	/**
	 * Text as SHOW CREATE TABLE prints it inside '…' (quote doubled, backslash and newline escaped).
	 */
	private function text( int $k ): string {
		$raw = '';
		for ( $i = $k % 4; $i > 0; $i-- ) {
			$raw .= self::TRICKY[ ( $k * 7 + $i ) % count( self::TRICKY ) ] . [ '', ' ', 'a' ][ ( $k + $i ) % 3 ];
		}

		return str_replace( [ '\\', "'", "\n", "\r", "\x00", "\x1a" ], [ '\\\\', "''", '\\n', '\\r', '\\0', '\\Z' ], $raw );
	}

	private function rows( string $name, int $k ): string {
		$count = $k % 5;
		if ( 0 === $count ) {
			return '';
		}
		$width   = 1 + $k % 6;
		$types   = [];
		$columns = [];
		for ( $c = 0; $c < $width; $c++ ) {
			$types[]   = self::TYPES[ ( $k + $c * 5 ) % count( self::TYPES ) ];
			$columns[] = self::COLUMNS[ ( $k + $c ) % count( self::COLUMNS ) ] . $c;
		}
		$list = 0 === $k % 3 ? $columns : [];
		$out  = DumpFormat::START;
		for ( $r = 0; $r < $count; $r++ ) {
			$values = [];
			foreach ( $types as $c => $type ) {
				$values[] = SqlValue::encode( $this->value( $type, $k + $r * 3 + $c ), $type );
			}
			$out .= DumpFormat::insert( $name, $values, $list );
		}

		return $out . DumpFormat::COMMIT;
	}

	private function value( string $type, int $n ): ?string {
		if ( 0 === $n % 10 ) {
			return null;
		}
		if ( 1 === preg_match( '/^(bigint|int|bit)/', $type ) ) {
			return self::INTEGERS[ $n % count( self::INTEGERS ) ];
		}
		if ( 1 === preg_match( '/^(double|float|decimal)/', $type ) ) {
			return self::DECIMALS[ $n % count( self::DECIMALS ) ];
		}
		if ( 0 === $n % 3 ) {
			return substr( self::allBytes(), $n % 256 ) . substr( self::allBytes(), 0, $n % 256 );
		}
		$raw = '';
		for ( $i = $n % 5; $i > 0; $i-- ) {
			$raw .= self::TRICKY[ ( $n + $i * 3 ) % count( self::TRICKY ) ];
		}

		return $raw;
	}

	private static function allBytes(): string {
		return implode( '', array_map( 'chr', range( 0, 255 ) ) );
	}
}
