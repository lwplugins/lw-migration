<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\PrefixRules;
use PHPUnit\Framework\TestCase;

final class PrefixRulesTest extends TestCase {

	public function test_export_table_names(): void {
		$rules = new PrefixRules( 'wp_' );
		$this->assertSame( 'SERVMASK_PREFIX_posts', $rules->exportTableName( 'wp_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_posts', $rules->exportTableName( 'WP_posts' ) );
		$this->assertSame( 'wbk_bookings', $rules->exportTableName( 'wbk_bookings' ) );
		$this->assertSame( 'SERVMASK_PREFIX_posts', ( new PrefixRules( '' ) )->exportTableName( 'posts' ) );
	}

	public function test_export_column_values(): void {
		$rules = new PrefixRules( 'wp_' );
		$this->assertSame( 'SERVMASK_PREFIX_user_roles', $rules->exportColumnValue( 'wp_user_roles' ) );
		$this->assertSame( 'SERVMASK_PREFIX_capabilities', $rules->exportColumnValue( 'wp_capabilities' ) );
		$this->assertSame( 'wp_rocket_settings', $rules->exportColumnValue( 'wp_rocket_settings' ) );
		$this->assertSame( 'wp_force_deactivated_plugins', $rules->exportColumnValue( 'wp_force_deactivated_plugins' ) );
		$this->assertSame( 'siteurl', $rules->exportColumnValue( 'siteurl' ) );

		$short = new PrefixRules( 'w' );
		$this->assertSame( 'SERVMASK_PREFIX_user_level', $short->exportColumnValue( 'wuser_level' ) );
		$this->assertSame( 'widget_text', $short->exportColumnValue( 'widget_text' ) );
	}

	public function test_import_pairs_order_and_identifier_pairs(): void {
		$pairs = PrefixRules::importPairs( 'site_' );
		$this->assertSame(
			[
				'SERVMASK_PREFIX_mainsite_users'    => 'site_users',
				'SERVMASK_PREFIX_mainsite_usermeta' => 'site_usermeta',
				'SERVMASK_PREFIX_basesite_'         => 'site_',
				'SERVMASK_PREFIX_'                  => 'site_',
			],
			$pairs
		);

		$sub = PrefixRules::importPairs( 'wp_', 3 );
		$this->assertSame( 'wp_', $sub['SERVMASK_PREFIX_3_'] );
		$this->assertSame( 'SERVMASK_PREFIX_3_', array_key_first( $sub ) );

		$this->assertSame(
			[ '`SERVMASK_PREFIX_mainsite_users' => '`lwtab12_users', '`SERVMASK_PREFIX_' => '`lwtab12_' ],
			PrefixRules::identifierPairs( [ 'SERVMASK_PREFIX_mainsite_users' => 'wp_users', 'SERVMASK_PREFIX_' => 'wp_' ], 'wp_', 'lwtab12_' )
		);
	}

	public function test_prefix_rules_refuse_a_trailing_newline(): void {
		$this->assertTrue( PrefixRules::isValidTempPrefix( 'lwtab12_' ) );
		$this->assertFalse( PrefixRules::isValidTempPrefix( "lwtab12_\n" ) );
		$this->assertTrue( PrefixRules::isValidExtraPrefix( 'wbk_' ) );
		$this->assertFalse( PrefixRules::isValidExtraPrefix( "wbk_\n" ) );
	}

	public function test_extra_temp_prefix_shares_the_run_id(): void {
		$this->assertSame( 'lwxab12_', PrefixRules::extraTempPrefix( 'lwtab12_' ) );
	}

	public function test_extra_prefix_tables_are_mapped_only_in_the_table_position(): void {
		$extra = [ 'wbk_', 'civicrm_' ];
		$this->assertSame( 'DROP TABLE IF EXISTS `lwxab12_wbk_bookings`;', PrefixRules::mapExtraTables( 'DROP TABLE IF EXISTS `wbk_bookings`;', $extra, 'lwxab12_' ) );
		$this->assertSame( 'CREATE TABLE `lwxab12_civicrm_contact` (`a` int)', PrefixRules::mapExtraTables( 'CREATE TABLE `civicrm_contact` (`a` int)', $extra, 'lwxab12_' ) );
		$this->assertSame( 'create table if not exists `lwxab12_wbk_x` (a int)', PrefixRules::mapExtraTables( 'create table if not exists `wbk_x` (a int)', $extra, 'lwxab12_' ) );
		$this->assertSame( "INSERT INTO `lwxab12_wbk_x` VALUES ('`wbk_y` and wbk_z');", PrefixRules::mapExtraTables( "INSERT INTO `wbk_x` VALUES ('`wbk_y` and wbk_z');", $extra, 'lwxab12_' ) );
		$this->assertSame( 'INSERT INTO `WBK_x` VALUES (1);', PrefixRules::mapExtraTables( 'INSERT INTO `WBK_x` VALUES (1);', $extra, 'lwxab12_' ) );
		$this->assertSame( 'INSERT INTO `wp_x` VALUES (1);', PrefixRules::mapExtraTables( 'INSERT INTO `wp_x` VALUES (1);', $extra, 'lwxab12_' ) );
		$this->assertSame( 'INSERT INTO `wbk_x` VALUES (1);', PrefixRules::mapExtraTables( 'INSERT INTO `wbk_x` VALUES (1);', [], 'lwxab12_' ) );
	}

	public function test_exact_identifier_pairs_name_whole_tables_with_both_backticks(): void {
		$this->assertSame(
			[ '`SERVMASK_PREFIX_users`' => '`lwtab12_lwmsrc_users`' ],
			PrefixRules::exactIdentifierPairs( [ 'SERVMASK_PREFIX_users' => 'wp_lwmsrc_users' ], 'wp_', 'lwtab12_' )
		);
		$this->assertSame( [], PrefixRules::exactIdentifierPairs( [], 'wp_', 'lwtab12_' ) );
	}

	public function test_left_out_table_is_named_only_in_the_table_position(): void {
		$extra = [ 'wbk_', 'civicrm_' ];
		$this->assertSame( 'wbk_bookings', PrefixRules::leftOutTable( 'DROP TABLE IF EXISTS `wbk_bookings`;', $extra ) );
		$this->assertSame( 'civicrm_contact', PrefixRules::leftOutTable( 'CREATE TABLE `civicrm_contact` (`a` int)', $extra ) );
		$this->assertSame( 'wbk_x', PrefixRules::leftOutTable( "insert into `wbk_x` VALUES ('a')", $extra ) );
		$this->assertNull( PrefixRules::leftOutTable( "INSERT INTO `wp_x` VALUES ('`wbk_y`')", $extra ) );
		$this->assertNull( PrefixRules::leftOutTable( 'INSERT INTO `WBK_x` VALUES (1)', $extra ) );
		$this->assertNull( PrefixRules::leftOutTable( 'INSERT INTO `wbk_x` VALUES (1)', [] ) );
	}
}
