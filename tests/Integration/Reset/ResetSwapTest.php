<?php
/**
 * The one RENAME of a database reset on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Reset;

use LightweightPlugins\Migration\Database\DatabaseException;
use LightweightPlugins\Migration\Database\QueryException;
use LightweightPlugins\Migration\Reset\ResetSwap;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;
use LightweightPlugins\Migration\Tests\Support\MysqliWpdb;

final class ResetSwapTest extends DatabaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->exec(
			'CREATE TABLE wp_options (v VARCHAR(20))',
			"INSERT INTO wp_options VALUES ('live')",
			'CREATE TABLE wp_posts (v VARCHAR(20))',
			"INSERT INTO wp_posts VALUES ('live-post')",
			'CREATE TABLE wp_wc_orders (v INT)',
			'CREATE TABLE wp_a_very_long_plugin_table_name_that_fills_all_64_characters_xx (v INT)',
			'CREATE TABLE wp_shop_options (v INT)',
			'CREATE TABLE wp_shop_posts (v INT)',
			'CREATE TABLE lwtab12_options (v VARCHAR(20))',
			"INSERT INTO lwtab12_options VALUES ('fresh')",
			'CREATE TABLE lwtab12_posts (v VARCHAR(20))',
			"INSERT INTO lwtab12_posts VALUES ('hello-world')",
			'CREATE TABLE lwtab12_users (v INT)'
		);
	}

	/** @return array<int, string> */
	private function names(): array {
		return array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( 'SHOW TABLES' ) );
	}

	public function test_one_rename_puts_the_fresh_tables_live_and_retires_the_rest(): void {
		$swap     = new ResetSwap( self::db(), 'lwtab12_', 'wp_' );
		$retire   = [ 'wp_wc_orders', 'wp_a_very_long_plugin_table_name_that_fills_all_64_characters_xx' ];
		$warnings = $swap->swap( [ 'options', 'posts', 'users' ], $retire );
		$this->assertSame( [], $warnings );
		$this->assertSame( 'fresh', (string) self::db()->query( 'SELECT v FROM wp_options' )[0]['v'] );
		$this->assertSame( 'hello-world', (string) self::db()->query( 'SELECT v FROM wp_posts' )[0]['v'] );
		$this->assertContains( 'wp_users', $this->names(), 'A core table the site lacked arrives too.' );
		$this->assertContains( 'lwoab12_r0', $this->names() );
		$this->assertContains( 'lwoab12_r1', $this->names(), 'Short names: a 64-character table never overflows.' );
		$this->assertContains( 'lwoab12_options', $this->names() );
		$this->assertContains( 'wp_shop_options', $this->names(), 'Not in the plan: untouched.' );
		$this->assertSame( [], $swap->pending( [ 'options', 'posts', 'users' ] ) );
		$swap->dropOld();
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, 'lwoab12_' ) ) ) );
	}

	public function test_pending_lists_the_core_tables_built_beside_the_live_ones(): void {
		$this->assertSame( [ 'options', 'posts', 'users' ], ( new ResetSwap( self::db(), 'lwtab12_', 'wp_' ) )->pending( [ 'options', 'posts', 'postmeta', 'users' ] ) );
	}

	public function test_drop_fresh_removes_only_the_runs_temporary_tables(): void {
		$this->exec( 'CREATE TABLE lwyab12_lwm_run (id TINYINT)' );
		( new ResetSwap( self::db(), 'lwtab12_', 'wp_' ) )->dropFresh();
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, 'lwtab12_' ) ) ) );
		$this->assertContains( 'lwyab12_lwm_run', $this->names(), 'The reservation stays until the run is forgotten.' );
		$this->assertContains( 'wp_options', $this->names() );
	}

	public function test_a_wrong_prefix_is_refused(): void {
		$this->expectException( DatabaseException::class );
		new ResetSwap( self::db(), 'wp_', 'wp_' );
	}

	public function test_a_base_that_starts_with_the_temporary_prefix_is_refused(): void {
		$this->expectException( DatabaseException::class );
		new ResetSwap( self::db(), 'lwtab12_', 'lwtab12_x_' );
	}

	public function test_check_names_what_would_make_the_rename_fail(): void {
		$swap = new ResetSwap( self::db(), 'lwtab12_', 'wp_' );
		$this->assertNull( $swap->check( [ 'options', 'posts' ], [ 'wp_wc_orders' ] ) );
		$this->assertSame( 'lwtab12_usermeta', $swap->check( [ 'options', 'usermeta' ], [] ), 'A fresh table that is gone.' );
		$this->assertSame( 'wp_gone', $swap->check( [ 'options' ], [ 'wp_gone' ] ), 'A retired table that is gone.' );
		$this->exec( 'CREATE TABLE lwoab12_r0 (v INT)' );
		$this->assertSame( 'lwoab12_r0', $swap->check( [ 'options' ], [] ), 'A target name already taken.' );
		$this->exec( 'DROP TABLE lwoab12_r0', 'DROP TABLE wp_posts', 'CREATE VIEW wp_posts AS SELECT v FROM wp_options' );
		$this->assertSame( 'wp_posts', $swap->check( [ 'options', 'posts' ], [] ), 'A live core name that is a view.' );
	}

	public function test_a_foreign_key_from_a_kept_table_keeps_a_retired_table_with_a_warning(): void {
		$this->exec(
			'CREATE TABLE wp_parent (id INT PRIMARY KEY) ENGINE=InnoDB',
			'CREATE TABLE wp_shop_child (pid INT, FOREIGN KEY (pid) REFERENCES wp_parent (id)) ENGINE=InnoDB'
		);
		$swap = new ResetSwap( self::db(), 'lwtab12_', 'wp_' );
		$swap->swap( [ 'options' ], [ 'wp_parent' ] );

		$kept = $swap->dropOld();

		$this->exec( 'DROP TABLE wp_shop_child' );
		$this->assertSame( [ 'lwoab12_r0' ], array_keys( $kept ) );
		$this->assertContains( $kept['lwoab12_r0'], [ 1451, 3730 ], 'Another table references it.' );
		$this->assertSame( 'wp_parent', $swap->originalName( 'lwoab12_r0', [ 'wp_parent' ] ), 'Named as the site knew it.' );
		$this->assertSame( 'wp_options', $swap->originalName( 'lwoab12_options', [ 'wp_parent' ] ) );
		$this->assertContains( 'lwoab12_r0', $this->names() );
		$this->assertNotContains( 'lwoab12_options', $this->names(), 'The others went.' );
	}

	public function test_retired_tables_that_reference_each_other_all_go(): void {
		$this->exec(
			'CREATE TABLE wp_a_parent (id INT PRIMARY KEY) ENGINE=InnoDB',
			'CREATE TABLE wp_b_child (pid INT, FOREIGN KEY (pid) REFERENCES wp_a_parent (id)) ENGINE=InnoDB'
		);
		$swap = new ResetSwap( self::db(), 'lwtab12_', 'wp_' );
		$swap->swap( [ 'options' ], [ 'wp_a_parent', 'wp_b_child' ] );

		$this->assertSame( [], $swap->dropOld(), 'The parent is tried again after its child went.' );
		$this->assertSame( [], array_values( array_filter( $this->names(), static fn( string $t ): bool => str_starts_with( $t, 'lwoab12_' ) ) ) );
	}

	public function test_the_core_tables_of_real_shape_swap_in(): void {
		$this->exec( 'DROP TABLE lwtab12_options', 'DROP TABLE lwtab12_users', 'DROP TABLE wp_options' );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$swap = new ResetSwap( self::db(), 'lwtab12_', 'wp_' );
		$swap->swap( $swap->pending( [ 'options', 'users', 'usermeta' ] ), [] );
		$this->assertSame( [], $swap->pending( [ 'options', 'users', 'usermeta' ] ) );
		$this->assertContains( 'lwoab12_usermeta', $this->names() );
	}

	public function test_a_rename_that_would_wait_behind_a_long_transaction_gives_up_soon(): void {
		$other = new MysqliWpdb();
		$other->query( 'START TRANSACTION' );
		$other->get_var( 'SELECT COUNT(*) FROM wp_options' ); // Holds a metadata lock until it ends.
		$swap = new ResetSwap( self::db(), 'lwtab12_', 'wp_', 1 );
		$before = (string) self::db()->query( 'SELECT @@SESSION.lock_wait_timeout AS t' )[0]['t'];
		try {
			$swap->swap( [ 'options' ], [] );
			$this->fail( 'Waited.' );
		} catch ( QueryException $e ) {
			$this->assertSame( 1205, $e->errno() );
		} finally {
			$other->query( 'ROLLBACK' );
			$other->close();
		}
		$this->assertSame( $before, (string) self::db()->query( 'SELECT @@SESSION.lock_wait_timeout AS t' )[0]['t'], 'The session setting is restored.' );
		$this->assertSame( 'live', (string) self::db()->query( 'SELECT v FROM wp_options' )[0]['v'], 'Nothing was renamed.' );
		$this->assertTrue( ResetSwap::busy( $e ) );
	}
}
