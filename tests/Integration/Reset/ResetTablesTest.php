<?php
/**
 * Which tables a database reset replaces, retires and keeps, on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Reset;

use LightweightPlugins\Migration\Reset\ResetTables;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;

final class ResetTablesTest extends DatabaseTestCase {

	private const CORE = [ 'options', 'posts', 'postmeta', 'users', 'usermeta' ];

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'wp_options', 'wp_posts', 'wp_users', 'wp_usermeta', 'wp_wc_orders', 'wp_custom_log', 'wp_shop_options', 'wp_shop_posts', 'wp_shop_wc_orders', 'wp2_options', 'other_table', 'lwtab12_options', 'lwyab12_lwm_run' ] as $table ) {
			$this->exec( "CREATE TABLE `{$table}` (id INT)" );
		}
		$this->exec( 'CREATE VIEW `wp_report` AS SELECT id FROM `wp_posts`' );
	}

	public function test_core_tables_are_replaced_and_plugin_tables_retired(): void {
		$plan = ResetTables::plan( self::db(), 'wp_', self::CORE );
		$this->assertSame( [ 'options', 'posts', 'users', 'usermeta' ], $plan['core'], 'Only core tables that exist (no wp_postmeta here).' );
		$this->assertSame( [ 'wp_custom_log', 'wp_wc_orders' ], $plan['retire'] );
		$this->assertSame( [ 'wp_report' ], $plan['views'] );
	}

	public function test_tables_of_another_installation_are_kept(): void {
		$plan = ResetTables::plan( self::db(), 'wp_', self::CORE );
		$this->assertSame( [ 'wp_shop_options', 'wp_shop_posts', 'wp_shop_wc_orders' ], $plan['kept'] );
		$this->assertNotContains( 'wp2_options', array_merge( $plan['retire'], $plan['kept'] ), 'Another prefix altogether is not ours to list.' );
		$this->assertNotContains( 'other_table', $plan['retire'] );
	}

	public function test_a_half_installation_is_not_another_installation(): void {
		$this->exec( 'DROP TABLE `wp_shop_posts`' );
		$plan = ResetTables::plan( self::db(), 'wp_', self::CORE );
		$this->assertContains( 'wp_shop_options', $plan['retire'], 'options without posts: a plugin table named like one.' );
	}

	public function test_our_run_tables_are_never_retired(): void {
		$this->exec( 'CREATE TABLE `lwtab12_posts` (id INT)' );
		$plan = ResetTables::plan( self::db(), 'lw', [ 'options' ] );
		$this->assertSame( [], array_values( array_filter( $plan['retire'], static fn( string $t ): bool => 1 === preg_match( '/^lw[toxy][a-z0-9]{4}_/i', $t ) ) ) );
	}

	public function test_a_name_that_only_looks_like_the_prefix_under_the_collation_is_never_retired(): void {
		$this->exec( "CREATE TABLE `\u{FF57}\u{FF50}_lookalike` (id INT)", 'CREATE TABLE `wpxescaped` (id INT)' );
		$plan = ResetTables::plan( self::db(), 'wp_', self::CORE );
		$this->assertNotContains( "\u{FF57}\u{FF50}_lookalike", $plan['retire'], 'Full-width letters are another name, whatever the collation says.' );
		$this->assertNotContains( "\u{FF57}\u{FF50}_lookalike", $plan['kept'] );
		$this->assertNotContains( 'wpxescaped', $plan['retire'], 'The underscore of the prefix is no wildcard.' );
	}

	public function test_core_tables_of_real_shape_are_found(): void {
		$this->exec( 'DROP TABLE wp_options', 'DROP TABLE wp_users', 'DROP TABLE wp_usermeta' );
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$plan = ResetTables::plan( self::db(), 'wp_', self::CORE );
		$this->assertSame( [ 'options', 'posts', 'users', 'usermeta' ], $plan['core'] );
	}
}
