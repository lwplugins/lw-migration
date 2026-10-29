<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\SchemaFixer;
use PHPUnit\Framework\TestCase;

final class SchemaFixerTest extends TestCase {

	public function test_version_parsing(): void {
		$this->assertSame( '8.0.36', SchemaFixer::parseVersion( '8.0.36' ) );
		$this->assertSame( '10.11.6', SchemaFixer::parseVersion( '5.5.5-10.11.6-MariaDB-log' ) );
		$this->assertSame( '10.11.6', SchemaFixer::parseVersion( '10.11.6-MariaDB-1:10.11.6+maria~ubu2204' ) );
		$this->assertSame( '0.0.0', SchemaFixer::parseVersion( 'unknown' ) );
		$this->assertTrue( ( new SchemaFixer( '10.11.6-MariaDB' ) )->isMariaDb() );
	}

	public function test_create_rewrites(): void {
		$in  = "CREATE TABLE `t` (\n  `id` int AUTOINCREMENT,\n  `ip` INET4,\n  `u` UUID,\n  `v` VECTOR(3),\n  `k` int,\n  CONSTRAINT `fk` FOREIGN KEY (`k`) REFERENCES `x` (`id`)\n) /*!50100 TABLESPACE x */ ENGINE=Aria TRANSACTIONAL=1 PAGE_CHECKSUM=1 ROW_FORMAT=PAGE DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
		$out = ( new SchemaFixer( '5.7.44' ) )->fixCreate( $in );

		$this->assertStringContainsString( 'AUTO_INCREMENT', $out );
		$this->assertStringContainsString( '`ip` VARCHAR(15)', $out );
		$this->assertStringContainsString( '`u` CHAR(36)', $out );
		$this->assertStringContainsString( '`v` BLOB', $out );
		$this->assertStringNotContainsString( 'CONSTRAINT', $out );
		$this->assertStringNotContainsString( '/*', $out );
		$this->assertStringContainsString( 'ENGINE=MyISAM', $out );
		$this->assertStringNotContainsString( 'TRANSACTIONAL', $out );
		$this->assertStringNotContainsString( 'ROW_FORMAT', $out );
		$this->assertStringContainsString( 'utf8mb4_unicode_520_ci', $out );
		$this->assertStringNotContainsString( '0900', $out );
	}

	public function test_modern_servers_keep_native_types_and_collations(): void {
		$sql = 'CREATE TABLE `t` (`ip` INET4) ENGINE=InnoDB COLLATE=utf8mb4_0900_ai_ci';
		$this->assertStringContainsString( 'INET4', ( new SchemaFixer( '11.4.2-MariaDB' ) )->fixCreate( $sql ) );
		$this->assertStringContainsString( 'utf8mb4_0900_ai_ci', ( new SchemaFixer( '8.0.36' ) )->fixCreate( $sql ) );
		$this->assertStringContainsString( 'VARCHAR(15)', ( new SchemaFixer( '8.0.36' ) )->fixCreate( $sql ) );
	}

	public function test_old_servers_downgrade_utf8mb4(): void {
		$out = ( new SchemaFixer( '5.5.0' ) )->fixCreate( 'CREATE TABLE `t` (a text) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci' );
		$this->assertStringContainsString( 'CHARSET=utf8 COLLATE=utf8_unicode_ci', $out );
	}

	public function test_error_fixes(): void {
		$f = new SchemaFixer( '8.0.36' );
		$this->assertSame( 'CREATE TABLE t (a int) ENGINE=InnoDB', $f->fixForError( 'CREATE TABLE t (a int) ENGINE=MyISAM', 1030 ) );
		$this->assertSame( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC', $f->fixForError( 'CREATE TABLE t (a int) ENGINE=InnoDB', 1071 ) );
		$this->assertSame( "CREATE TABLE t (\n  `a` text,\n  KEY `k` (`a`)\n)", $f->fixForError( "CREATE TABLE t (\n  `a` text,\n  KEY `k` (`a`),\n  FULLTEXT KEY `f` (`a`)\n)", 1214 ) );
		$this->assertSame( "CREATE TABLE t (\n  `a` text NOT NULL,\n  `b` int DEFAULT '1'\n)", $f->fixForError( "CREATE TABLE t (\n  `a` text NOT NULL DEFAULT '',\n  `b` int DEFAULT '1'\n)", 1101 ) );
		$this->assertSame( 'CREATE TABLE t (a int) COLLATE=utf8mb4_unicode_520_ci', $f->fixForError( 'CREATE TABLE t (a int) COLLATE=utf8mb4_0900_ai_ci', 1273 ) );
		$this->assertSame( 'CREATE TABLE t (a int) COLLATE=utf8mb4_unicode_ci', $f->fixForError( 'CREATE TABLE t (a int) COLLATE=utf8mb4_unicode_520_ci', 1273 ) );
		$this->assertNull( $f->fixForError( 'CREATE TABLE t (a int)', 1064 ) );
		$this->assertNull( $f->fixForError( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC', 1071 ) );
	}

	public function test_string_literals_and_identifiers_are_untouched(): void {
		$in  = "CREATE TABLE `t` (\n  `a` enum('uuid','inet4') DEFAULT 'INET4',\n  `b` int COMMENT 'sort type=asc',\n  `c` int COMMENT 'a /* b */ c',\n  `type` varchar(5) DEFAULT 'AUTOINCREMENT',\n  `ROW_FORMAT` int COMMENT 'WITHOUT ROWID',\n  `d` int COMMENT 'it''s ROW_FORMAT=PAGE'\n) ENGINE=InnoDB COMMENT='x ROW_FORMAT=PAGE TRANSACTIONAL=1 utf8mb4_0900_ai_ci'";
		foreach ( [ '5.7.44', '8.0.36', '10.11.6-MariaDB' ] as $version ) {
			$out = ( new SchemaFixer( $version ) )->fixCreate( $in );
			$this->assertStringContainsString( "enum('uuid','inet4') DEFAULT 'INET4'", $out );
			$this->assertStringContainsString( "COMMENT 'sort type=asc'", $out );
			$this->assertStringContainsString( "COMMENT 'a /* b */ c'", $out );
			$this->assertStringContainsString( "`type` varchar(5) DEFAULT 'AUTOINCREMENT'", $out );
			$this->assertStringContainsString( "`ROW_FORMAT` int COMMENT 'WITHOUT ROWID'", $out );
			$this->assertStringContainsString( "COMMENT 'it''s ROW_FORMAT=PAGE'", $out );
			$this->assertStringContainsString( "COMMENT='x ROW_FORMAT=PAGE TRANSACTIONAL=1 utf8mb4_0900_ai_ci'", $out );
		}
	}

	public function test_row_format_keeps_supported_values_and_drops_page(): void {
		$f = new SchemaFixer( '8.0.36' );
		$this->assertStringContainsString( 'ROW_FORMAT=DYNAMIC', $f->fixCreate( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC' ) );
		$this->assertStringContainsString( 'ROW_FORMAT=COMPRESSED', $f->fixCreate( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=COMPRESSED' ) );
		$this->assertStringNotContainsString( 'ROW_FORMAT', $f->fixCreate( 'CREATE TABLE t (a int) ENGINE=Aria ROW_FORMAT=PAGE' ) );
	}

	public function test_0900_bin_and_mariadb_upfront_mapping(): void {
		$sql = 'CREATE TABLE t (a int) COLLATE=utf8mb4_0900_bin';
		$this->assertStringContainsString( 'COLLATE=utf8mb4_bin', ( new SchemaFixer( '5.7.44' ) )->fixCreate( $sql ) );
		$this->assertStringContainsString( 'COLLATE=utf8mb4_0900_bin', ( new SchemaFixer( '8.0.36' ) )->fixCreate( $sql ) );
		$maria = new SchemaFixer( '11.4.2-MariaDB' );
		$this->assertStringContainsString( 'COLLATE=utf8mb4_bin', $maria->fixCreate( $sql ) );
		$this->assertStringContainsString( 'COLLATE=utf8mb4_unicode_520_ci', $maria->fixCreate( 'CREATE TABLE t (a int) COLLATE=utf8mb4_0900_ai_ci' ) );
		$this->assertSame( 'CREATE TABLE t (a int) COLLATE=utf8mb4_bin', ( new SchemaFixer( '8.0.36' ) )->fixForError( $sql, 1273 ) );
	}

	public function test_error_fixes_leave_literals_alone(): void {
		$f = new SchemaFixer( '8.0.36' );
		$this->assertSame(
			"CREATE TABLE t (a text NOT NULL COMMENT 'DEFAULT x') ENGINE=InnoDB",
			$f->fixForError( "CREATE TABLE t (a text NOT NULL DEFAULT 'q' COMMENT 'DEFAULT x') ENGINE=InnoDB", 1101 )
		);
		$this->assertNull( $f->fixForError( "CREATE TABLE t (a int COMMENT 'ENGINE=MyISAM') ENGINE=InnoDB", 1030 ) );
		$this->assertSame( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC', $f->fixForError( 'CREATE TABLE t (a int) ENGINE=InnoDB ROW_FORMAT=COMPACT', 1071 ) );
	}

	public function test_unknown_charset_retry(): void {
		$f = new SchemaFixer( '5.5.40' );
		$this->assertSame(
			"CREATE TABLE t (a text) DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='utf8mb3'",
			$f->fixForError( "CREATE TABLE t (a text) DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='utf8mb3'", 1115 )
		);
		$this->assertNull( $f->fixForError( 'CREATE TABLE t (a text) DEFAULT CHARSET=utf8', 1115 ) );
	}
	public function test_check_constraint_names_are_dropped(): void {
		$mysql = "CREATE TABLE `t` (\n  `a` int DEFAULT NULL,\n  `b` int CONSTRAINT b_pos CHECK (`b` > 0),\n  CONSTRAINT `wp_x_chk_1` CHECK ((`a` > 0)),\n  CONSTRAINT `c``2` CHECK ((`a` < 9)) /*!80016 NOT ENFORCED */\n) ENGINE=InnoDB";
		$maria = "CREATE TABLE `t` (\n  `a` int DEFAULT NULL CHECK (`a` > 0),\n  CONSTRAINT `CONSTRAINT_1` CHECK (`a` < 9)\n) ENGINE=InnoDB";
		foreach ( [ '8.0.36', '10.11.6-MariaDB' ] as $version ) {
			$out = ( new SchemaFixer( $version ) )->fixCreate( $mysql );
			$this->assertStringNotContainsString( 'CONSTRAINT', $out );
			$this->assertStringContainsString( '`b` int CHECK (`b` > 0),', $out );
			$this->assertStringContainsString( '  CHECK ((`a` > 0)),', $out );
			$this->assertStringContainsString( '  CHECK ((`a` < 9))', $out );

			$out = ( new SchemaFixer( $version ) )->fixCreate( $maria );
			$this->assertSame( "CREATE TABLE `t` (\n  `a` int DEFAULT NULL CHECK (`a` > 0),\n  CHECK (`a` < 9)\n) ENGINE=InnoDB", $out );
		}
	}

	public function test_check_constraint_names_inside_literals_are_untouched(): void {
		$in  = "CREATE TABLE `t` (\n  `a` varchar(40) DEFAULT 'CONSTRAINT `x` CHECK (1)' COMMENT 'CONSTRAINT y CHECK (',\n  `CONSTRAINT` int\n) ENGINE=InnoDB COMMENT='CONSTRAINT `z` CHECK (a)'";
		$out = ( new SchemaFixer( '8.0.36' ) )->fixCreate( $in );
		$this->assertSame( $in, $out );
	}

	public function test_duplicate_check_constraint_name_retry(): void {
		$f = new SchemaFixer( '8.0.36' );
		$this->assertSame(
			"CREATE TABLE t (a int, CHECK (a > 0), b varchar(9) DEFAULT 'CONSTRAINT `c` CHECK (')",
			$f->fixForError( "CREATE TABLE t (a int, CONSTRAINT `c1` CHECK (a > 0), b varchar(9) DEFAULT 'CONSTRAINT `c` CHECK (')", 3822 )
		);
		$this->assertNull( $f->fixForError( 'CREATE TABLE t (a int, CHECK (a > 0))', 3822 ) );
	}

	public function test_double_quoted_text_is_untouched_and_comments_are_still_stripped(): void {
		$f   = new SchemaFixer( '5.7.44' );
		$out = $f->fixCreate( "CREATE TABLE `t` (`a` int COMMENT \"type=INET4 it's\") /* it's a comment */ ENGINE=Aria COMMENT 'x'" );
		$this->assertSame( "CREATE TABLE `t` (`a` int COMMENT \"type=INET4 it's\")  ENGINE=MyISAM COMMENT 'x'", $out );
	}

	public function test_huge_literal_does_not_empty_the_statement(): void {
		$in  = "CREATE TABLE `t` (`a` text) ENGINE=Aria COMMENT='" . str_repeat( 'ab', 1500000 ) . "'";
		$out = ( new SchemaFixer( '8.0.36' ) )->fixCreate( $in );
		$this->assertSame( str_replace( 'ENGINE=Aria', 'ENGINE=MyISAM', $in ), $out );
	}

	public function test_unscannable_input_is_returned_unchanged(): void {
		$f = new SchemaFixer( '5.7.44' );
		foreach ( [ "CREATE TABLE `t` (a int) ENGINE=Aria COMMENT 'open", "CREATE TABLE `t` (a int) \x01" . "0\x02 ENGINE=Aria", 'CREATE TABLE `t` (a int) ENGINE=Aria /* open' ] as $in ) {
			$this->assertSame( $in, $f->fixCreate( $in ) );
		}
	}
}
