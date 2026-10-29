<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DumpFormat;
use LightweightPlugins\Migration\Database\ImportScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportScopeTest extends TestCase {

	public function test_allows_only_import_table_writes(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertTrue( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwt_options`;' ) );
		$this->assertTrue( $s->targetsImportTables( 'create table if not exists `lwt_x` (a int)' ) );
		$this->assertTrue( $s->targetsImportTables( 'INSERT INTO `lwt_x` (`a`, `b`) VALUES (1,2);' ) );
		$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwt_a`, `wp_live`;' ) );
		$this->assertFalse( $s->targetsImportTables( 'INSERT INTO wp_live VALUES (1);' ) );
		$this->assertFalse( $s->targetsImportTables( 'INSERT INTO lwm.`lwt_x` VALUES (1);' ) );
		$this->assertFalse( $s->targetsImportTables( 'INSERT INTO `lwt_x` SELECT * FROM wp_users;' ) );
		$this->assertFalse( $s->targetsImportTables( 'UPDATE `lwt_x` SET a=1;' ) );
		$this->assertFalse( $s->targetsImportTables( 'CREATE TABLE `wp_new` (a int);' ) );
	}

	public function test_views_must_name_a_site_view(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertSame( 'DROP VIEW IF EXISTS `wp_v`;', $s->deferrableView( 'DROP VIEW IF EXISTS `wp_v`;' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` AS select 1', $s->deferrableView( 'CREATE ALGORITHM=UNDEFINED DEFINER=`a`@`b` SQL SECURITY DEFINER VIEW `wp_v` AS select 1' ) );
		$this->assertNull( $s->deferrableView( 'DROP VIEW IF EXISTS `wp_v`, `x`;' ) );
		$this->assertNull( $s->deferrableView( 'CREATE VIEW `other` AS select 1' ) );
		$this->assertNull( $s->deferrableView( 'CREATE OR REPLACE VIEW `other` AS select 1' ) );
		$this->assertNull( $s->deferrableView( 'CREATE OR REPLACE ALGORITHM=MERGE VIEW `wp_v`.`x` AS select 1' ) );
		$this->assertNull( $s->deferrableView( 'CREATE VIEW `wp_v`.`x` AS select 1' ) );
		$this->assertNull( $s->deferrableView( "CREATE DEFINER='a b'@'c' VIEW `wp_v` AS select 1" ) );
		$this->assertTrue( $s->isTransactionControl( 'START TRANSACTION;' ) );
		$this->assertTrue( $s->isTransactionControl( 'commit' ) );
		$this->assertFalse( $s->isTransactionControl( 'COMMIT; DROP TABLE x' ) );
	}

	public function test_site_views_are_created_or_replaced_so_a_replay_can_run_them_again(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` AS select 1', $s->deferrableView( 'CREATE VIEW `wp_v` AS select 1' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` AS select 1', $s->deferrableView( 'create view `wp_v` AS select 1' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` as select 1', $s->deferrableView( 'CREATE VIEW `wp_v` as select 1' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` AS select 1', $s->deferrableView( 'CREATE OR REPLACE SQL SECURITY INVOKER VIEW `wp_v` AS select 1' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_v` AS select 1', $s->deferrableView( (string) $s->deferrableView( 'CREATE VIEW `wp_v` AS select 1' ) ), 'Its own output passes again (TableSwap re-checks the deferred file).' );
	}

	public function test_names_with_a_backtick_round_trip_from_the_dump(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$drop = explode( "\n", trim( DumpFormat::table( 'lwt_a`b', 'X' ) ) )[0];
		$this->assertSame( 'DROP TABLE IF EXISTS `lwt_a``b`;', $drop );
		$this->assertTrue( $s->targetsImportTables( $drop ) );
		$this->assertTrue( $s->namesImportTable( 'CREATE TABLE `lwt_a``b` (a int)' ) );
		$this->assertSame( 'DROP VIEW IF EXISTS `wp_a``b`;', $s->deferrableView( 'DROP VIEW IF EXISTS `wp_a``b`;' ) );
		$this->assertSame( 'CREATE OR REPLACE VIEW `wp_a``b` AS select 1', $s->deferrableView( 'CREATE VIEW `wp_a``b` AS select 1' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function backtickSmuggling(): array {
		return [
			'second table'      => [ 'DROP TABLE IF EXISTS `lwt_a``, `wp_live`;' ],
			'other database'    => [ 'DROP TABLE IF EXISTS `lwt_a```.`wp_live`;' ],
			'unterminated'      => [ 'DROP TABLE IF EXISTS `lwt_a``;' ],
		];
	}

	#[DataProvider( 'backtickSmuggling' )]
	public function test_a_doubled_backtick_never_reaches_past_the_name( string $sql ): void {
		$this->assertFalse( ( new ImportScope( 'wp_', 'lwt_' ) )->targetsImportTables( $sql ) );
	}

	public function test_qualified_targets_are_refused(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwt_a`.`wp_live`;' ) );
		$this->assertFalse( $s->targetsImportTables( 'INSERT INTO `lwt_a`.`wp_live` VALUES (1);' ) );
		$this->assertFalse( $s->isSafeCreate( 'CREATE TABLE `lwt_a`.`wp_live` (a int)' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafeCreates(): array {
		return [
			'merge'          => [ 'CREATE TABLE `lwt_m` (id int) ENGINE=MERGE UNION=(wp_live) INSERT_METHOD=LAST' ],
			'mrg_myisam'     => [ 'CREATE TABLE `lwt_m` (id int) ENGINE=MRG_MyISAM' ],
			'quoted engine'  => [ "CREATE TABLE `lwt_m` (id int) ENGINE='MERGE'" ],
			'federated'      => [ "CREATE TABLE `lwt_f` (id int) ENGINE=FEDERATED CONNECTION='mysql://x/y/z'" ],
			'connection'     => [ "CREATE TABLE `lwt_f` (id int) ENGINE=InnoDB CONNECTION='mysql://x/y/z'" ],
			'archive'        => [ 'CREATE TABLE `lwt_a` (id int) ENGINE=ARCHIVE' ],
			'data directory' => [ "CREATE TABLE `lwt_d` (id int) DATA DIRECTORY='/tmp'" ],
			'index dir'      => [ "CREATE TABLE `lwt_d` (id int) ENGINE=MyISAM INDEX DIRECTORY='/tmp'" ],
			'as select'      => [ 'CREATE TABLE `lwt_c` AS SELECT * FROM wp_users' ],
			'trailing select' => [ 'CREATE TABLE `lwt_c` (a int) SELECT * FROM wp_users' ],
			'like'           => [ 'CREATE TABLE `lwt_l` LIKE wp_live' ],
			'paren like'     => [ 'CREATE TABLE `lwt_l` (LIKE wp_live)' ],
			'table stmt'     => [ 'CREATE TABLE `lwt_l` (a int) TABLE wp_live' ],
			'foreign key'    => [ 'CREATE TABLE `lwt_k` (a int, FOREIGN KEY (a) REFERENCES wp_users (ID))' ],
			'dquote hiding'  => [ 'CREATE TABLE `lwt_h` (id int) COMMENT "\'" ENGINE=MERGE UNION=(wp_live) COMMENT "\'"' ],
			'comment'        => [ 'CREATE TABLE `lwt_e` (id int) /*!50000 ENGINE=MERGE UNION=(wp_live) */' ],
			'dash comment'   => [ "CREATE TABLE `lwt_e` (id int) -- x\n" ],
			'hash comment'   => [ "CREATE TABLE `lwt_e` (id int) # x\n" ],
			'unterminated'   => [ "CREATE TABLE `lwt_e` (id int) COMMENT 'x" ],
			'not tmp'        => [ 'CREATE TABLE `wp_new` (id int)' ],
			'marker bytes'   => [ "CREATE TABLE `lwt_x` (id int) \x010\x02" ],
		];
	}

	#[DataProvider( 'unsafeCreates' )]
	public function test_unsafe_create_table_is_refused( string $sql ): void {
		$this->assertFalse( ( new ImportScope( 'wp_', 'lwt_' ) )->isSafeCreate( $sql ) );
	}

	public function test_a_create_table_carries_no_second_statement(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );

		$this->assertFalse( $s->targetsImportTables( 'CREATE TABLE `lwt_x` (a int); DELETE FROM wp_users;' ) );
		$this->assertFalse( $s->targetsImportTables( 'CREATE TABLE `lwt_x` (a int);;' ) );
		$this->assertTrue( $s->targetsImportTables( "CREATE TABLE `lwt_x` (a varchar(9) DEFAULT ';', `b;` int) COMMENT 'x;y' ; \n" ) );
	}

	public function test_ordinary_create_table_is_allowed(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertTrue( $s->isSafeCreate( "CREATE TABLE `lwt_options` (\n  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `select` varchar(191) NOT NULL DEFAULT 'a UNION SELECT ''x'' -- #',\n  `like` longtext COMMENT \"it's LIKE\",\n  PRIMARY KEY (`option_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci" ) );
		$this->assertTrue( $s->isSafeCreate( 'create table if not exists `lwt_x` (a int) engine = Aria PAGE_CHECKSUM=1' ) );
		$this->assertFalse( $s->isSafeCreate( 'create table if not exists `LWT_x` (a int)' ), 'Task 7 security review: the temporary prefix only in its exact case.' );
		$this->assertTrue( $s->isSafeCreate( 'CREATE TABLE `lwt_x` (a int)' ) );
		$this->assertTrue( $s->isSafeCreate( 'CREATE TABLE `lwt_x` (a int) ENGINE MEMORY' ) );
	}

	public function test_like_as_an_operator_is_allowed(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertTrue( $s->isSafeCreate( "CREATE TABLE `lwt_check_like` (\n  `id` int NOT NULL,\n  `code` varchar(20) NOT NULL,\n  CONSTRAINT `chk_code` CHECK ((`code` like _utf8mb4'a%'))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci" ) );
		$this->assertTrue( $s->isSafeCreate( "CREATE TABLE `lwt_check_like` (\n  `id` int(11) NOT NULL,\n  `code` varchar(20) NOT NULL,\n  CONSTRAINT `chk_code` CHECK (`code` like 'a%')\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci" ) );
		$this->assertTrue( $s->isSafeCreate( "CREATE TABLE `lwt_gen` (\n  `a` varchar(20) DEFAULT NULL,\n  `is_a` tinyint GENERATED ALWAYS AS ((`a` like _utf8mb4'x%')) STORED\n) ENGINE=InnoDB" ) );
		$this->assertTrue( $s->isSafeCreate( "CREATE TABLE `lwt_gen` (\n  `a` varchar(20) DEFAULT NULL,\n  `is_a` tinyint(4) GENERATED ALWAYS AS (`a` like 'x%') STORED\n) ENGINE=InnoDB" ) );
		$this->assertFalse( $s->isSafeCreate( 'CREATE TABLE `lwt_l` ( LIKE wp_live )' ) );
	}

	public function test_extra_temp_prefix_targets_are_allowed_with_the_same_grammar(): void {
		$s = new ImportScope( 'wp_', 'lwt_', 'lwx_' );
		$this->assertTrue( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwx_wbk_a`;' ) );
		$this->assertTrue( $s->targetsImportTables( 'CREATE TABLE `lwx_wbk_a` (a int)' ) );
		$this->assertTrue( $s->targetsImportTables( 'INSERT INTO `lwx_wbk_a` VALUES (1);' ) );
		$this->assertTrue( $s->isSafeCreate( 'CREATE TABLE `lwx_wbk_a` (a int) ENGINE=InnoDB' ) );
		$this->assertFalse( $s->isSafeCreate( 'CREATE TABLE `lwx_wbk_m` (id int) ENGINE=MERGE UNION=(wp_live) INSERT_METHOD=LAST' ) );
		$this->assertFalse( $s->targetsImportTables( 'INSERT INTO `lwx_wbk_a` SELECT * FROM wp_users;' ) );
		$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwx_a`, `wp_live`;' ) );

		$plain = new ImportScope( 'wp_', 'lwt_' );
		$this->assertFalse( $plain->targetsImportTables( 'DROP TABLE IF EXISTS `lwx_wbk_a`;' ) );
		$this->assertFalse( $plain->targetsImportTables( 'INSERT INTO `lwx_wbk_a` VALUES (1);' ) );
		$this->assertFalse( $plain->isSafeCreate( 'CREATE TABLE `lwx_wbk_a` (a int)' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function createSeparators(): array {
		return [
			'two spaces' => [ '  ' ],
			'tab'        => [ "\t" ],
			'newline'    => [ "\n" ],
			'crlf'       => [ "\r\n" ],
		];
	}

	#[DataProvider( 'createSeparators' )]
	public function test_every_create_statement_must_pass_the_create_check( string $sep ): void {
		$s = new ImportScope( 'wp_', 'lwt_' );
		$this->assertFalse( $s->targetsImportTables( "CREATE{$sep}TABLE `lwt_m` (id int) ENGINE=MRG_MyISAM UNION=(`wp_live`) INSERT_METHOD=LAST;" ) );
		$this->assertFalse( $s->targetsImportTables( "CREATE{$sep}TABLE `lwt_s` AS SELECT * FROM `wp_live`;" ) );
		$this->assertFalse( $s->targetsImportTables( "CREATE{$sep}TABLE IF NOT EXISTS `lwt_m` (id int) ENGINE=MERGE UNION=(wp_live);" ) );
		$this->assertTrue( $s->targetsImportTables( "CREATE{$sep}TABLE `lwt_ok` (id int) ENGINE=InnoDB;" ) );
		$this->assertTrue( $s->isCreateTable( "CREATE{$sep}TABLE `lwt_ok` (id int);" ) );
	}

	public function test_a_subsite_import_reaches_only_its_sites_and_the_staging(): void {
		$s = new ImportScope( 'wp_', 'lwt_', '', [ '7_' ], [ 'lwmsrc_' ] );

		$this->assertTrue( $s->targetsImportTables( 'INSERT INTO `lwt_7_posts` VALUES (1);' ) );
		$this->assertTrue( $s->targetsImportTables( 'CREATE TABLE `lwt_lwmsrc_users` (a int);' ) );
		$this->assertTrue( $s->targetsImportTables( 'DROP TABLE IF EXISTS `lwt_7_x`;' ) );
		foreach ( [ 'lwt_users', 'lwt_options', 'lwt_3_options', 'lwt_70_posts', 'lwt_7' ] as $table ) {
			$this->assertFalse( $s->targetsImportTables( 'INSERT INTO `' . $table . '` VALUES (1);' ), $table );
			$this->assertFalse( $s->targetsImportTables( 'CREATE TABLE `' . $table . '` (a int);' ), $table );
			$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `' . $table . '`;' ), $table );
		}
		$this->assertNull( $s->deferrableView( 'CREATE VIEW `wp_7_v` AS select 1' ), 'Task 7 security ruling: no view at all.' );
		foreach ( [ 'wp_mainview', 'wp_3_view', 'wp_lwmsrc_v', 'wp_70_v' ] as $view ) {
			$this->assertNull( $s->deferrableView( 'CREATE VIEW `' . $view . '` AS select 1' ), $view );
			$this->assertNull( $s->deferrableView( 'DROP VIEW IF EXISTS `' . $view . '`;' ), $view );
		}
	}

	public function test_the_temporary_prefix_is_matched_in_its_exact_case(): void {
		foreach ( [ new ImportScope( 'wp_', 'lwt_' ), new ImportScope( 'wp_', 'lwt_', '', [ '7_' ], [ 'lwmsrc_' ] ) ] as $s ) {
			foreach ( [ 'LWT_7_x', 'Lwt_7_x', 'LWT_LWMSRC_users' ] as $table ) {
				$this->assertFalse( $s->targetsImportTables( 'INSERT INTO `' . $table . '` VALUES (1);' ), $table );
				$this->assertFalse( $s->targetsImportTables( 'CREATE TABLE `' . $table . '` (a int);' ), $table );
				$this->assertFalse( $s->targetsImportTables( 'DROP TABLE IF EXISTS `' . $table . '`;' ), $table );
			}
		}
	}

	/**
	 * Task 7 security ruling: no hand-rolled SQL grammar as a boundary; a subsite
	 * restore into a network brings no view at all.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function subsiteViews(): array {
		return [
			'straight join'        => [ 'CREATE VIEW `wp_7_v` AS select `u`.`user_pass` AS `p` from `wp_7_posts` `x` STRAIGHT_JOIN `wp_users` `u`' ],
			'straight join bare'   => [ 'CREATE VIEW `wp_7_v` AS select u.user_pass from wp_7_posts x STRAIGHT_JOIN wp_users u' ],
			'subquery then comma'  => [ 'CREATE VIEW `wp_7_v` AS select `u`.`user_pass` AS `p` from (select 1) `a`, `wp_users` `u`' ],
			'subquery comma bare'  => [ 'CREATE VIEW `wp_7_v` AS select u.user_pass from (select 1) a, wp_users u' ],
			'other database'       => [ 'CREATE VIEW `wp_7_v` AS select `u`.`user_pass` AS `p` from (select 1) `a`, `mysql`.`user` `u`' ],
			'direct users'         => [ 'CREATE VIEW `wp_7_v` AS select `user_pass` from `wp_users`' ],
			'plain own view'       => [ 'CREATE VIEW `wp_7_v` AS select 1 from `wp_7_posts`' ],
			'drop own view'        => [ 'DROP VIEW IF EXISTS `wp_7_v`;' ],
		];
	}

	#[DataProvider( 'subsiteViews' )]
	public function test_a_subsite_restore_defers_no_view( string $sql ): void {
		$this->assertNull( ( new ImportScope( 'wp_', 'lwt_', '', [ '7_' ], [ 'lwmsrc_' ] ) )->deferrableView( $sql ) );
	}

	public function test_a_view_name_is_read_for_the_warning(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );

		$this->assertSame( 'wp_7_v', $s->viewName( 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `wp_7_v` AS select 1' ) );
		$this->assertSame( 'a`b', $s->viewName( 'create view `a``b` as select 1' ) );
		$this->assertNull( $s->viewName( 'DROP VIEW IF EXISTS `wp_7_v`;' ) );
	}

	public function test_whole_network_and_single_site_views_are_unchanged(): void {
		$s = new ImportScope( 'wp_', 'lwt_' );

		$this->assertNotNull( $s->deferrableView( 'CREATE VIEW `wp_v` AS select `user_login` from `wp_users`' ) );
		$this->assertNotNull( $s->deferrableView( 'CREATE VIEW `WP_v` AS select 1 AS `a` from dual' ) );
		$this->assertNotNull( $s->deferrableView( 'CREATE VIEW `wp_7_v` AS select 1 from `wp_7_posts` `x` STRAIGHT_JOIN `wp_users` `u`' ) );
		$this->assertNotNull( $s->deferrableView( 'DROP VIEW IF EXISTS `wp_7_v`;' ) );
	}
}
