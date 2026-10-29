<?php
/**
 * What a database reset carries from the live tables into the fresh ones, on a real MySQL/MariaDB.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Reset;

use Brain\Monkey;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\TmpCarry;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Integration\Import\WpCoreTables;
use PHPUnit\Framework\Attributes\Group;

#[Group( 'monkey' )]
final class TmpCarryTest extends DatabaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->exec( ...WpCoreTables::create( 'wp_' ) );
		$this->exec( ...WpCoreTables::create( 'lwtab12_' ) );
		$this->exec(
			"INSERT INTO wp_users (ID, user_login, user_pass, user_email, display_name) VALUES (3, 'eve', 'h3', 'e@x.test', 'Eve'), (7, 'anna', '\$wp\$hash', 'a@x.test', 'Anna')",
			"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (7, 'session_tokens', 'tok'), (7, 'first_name', 'Anna'), (7, 'wp_capabilities', 'a:1:{s:6:\"editor\";b:1;}'), (7, 'billing_city', 'Pécs'), (3, 'session_tokens', 'eve-tok')",
			"INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://live.test'), ('home', 'https://live.test'), ('blogname', 'Live'), ('blog_public', '0'), ('permalink_structure', '/%postname%/'), ('admin_email', 'owner@live.test'), ('upload_path', 'files'), ('upload_url_path', 'https://cdn.live.test/files'), ('auth_salt', 'S1'), ('nonce_key', 'K1'), ('secret_key', 'SK'), ('lw_migration_links', '[1]'), ('lw_migration_lock', '{\"job\":\"x\"}'), ('_transient_lw_migration_job_secret_x', 'sealed'), ('lw_migration_export_estimate', '{\"result\":{}}'), ('active_plugins', 'a:2:{}'), ('woocommerce_db_version', '9')",
			"INSERT INTO lwtab12_options (option_name, option_value) VALUES ('siteurl', 'http://guessed'), ('home', 'http://guessed'), ('blogname', 'My Site'), ('admin_email', 'a@x.test'), ('lwtab12_user_roles', 'roles'), ('template', 'twentytwentyfive'), ('stylesheet', 'twentytwentyfive'), ('fresh_site', '1')",
			"INSERT INTO lwtab12_usermeta (user_id, meta_key, meta_value) VALUES (7, 'show_welcome_panel', '1')"
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function value( string $sql ): ?string {
		$rows = self::db()->query( $sql );

		return [] === $rows ? null : (string) array_values( $rows[0] )[0];
	}

	public function test_the_current_user_keeps_id_password_and_sessions(): void {
		$this->assertTrue( ( new TmpCarry( self::db(), 'lwtab12_', 'wp_' ) )->carry( 7, 'anna', 'lw-migration/lw-migration.php', null ) );
		$this->assertSame( 'anna|$wp$hash|a@x.test', $this->value( "SELECT CONCAT(user_login, '|', user_pass, '|', user_email) FROM lwtab12_users WHERE ID = 7" ) );
		$this->assertNull( $this->value( 'SELECT ID FROM lwtab12_users WHERE ID = 3' ), 'Every other user goes.' );
		$this->assertSame( 'tok', $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'session_tokens'" ) );
		$this->assertSame( 'Anna', $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'first_name'" ) );
		$this->assertSame( 'a:1:{s:13:"administrator";b:1;}', $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'wp_capabilities'" ) );
		$this->assertSame( '10', $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'wp_user_level'" ) );
		$this->assertNull( $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE meta_key = 'billing_city'" ), 'Plugin meta goes.' );
		$this->assertNull( $this->value( 'SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 3' ), 'Nothing of a removed user.' );
		$this->assertSame( '1', $this->value( "SELECT meta_value FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'show_welcome_panel'" ), 'What the fresh install wrote stays.' );
		$create = (string) array_values( self::db()->query( 'SHOW CREATE TABLE lwtab12_users' )[0] )[1]; // Ruling M3: never the stats-cached information_schema value.
		$this->assertMatchesRegularExpression( '/AUTO_INCREMENT=([89]|[1-9][0-9]+)\b/', $create );
	}

	public function test_salts_and_our_rows_are_carried(): void {
		( new TmpCarry( self::db(), 'lwtab12_', 'wp_' ) )->carry( 7, 'anna', 'lw-migration/lw-migration.php', null );
		foreach ( [ 'siteurl' => 'https://live.test', 'blogname' => 'Live', 'blog_public' => '0', 'permalink_structure' => '/%postname%/', 'admin_email' => 'owner@live.test', 'upload_path' => 'files', 'upload_url_path' => 'https://cdn.live.test/files', 'auth_salt' => 'S1', 'nonce_key' => 'K1', 'secret_key' => 'SK', 'lw_migration_links' => '[1]', 'lw_migration_lock' => '{"job":"x"}', '_transient_lw_migration_job_secret_x' => 'sealed' ] as $name => $value ) {
			$this->assertSame( $value, $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = '{$name}'" ), $name );
		}
		$this->assertNull( $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'woocommerce_db_version'" ) );
		$this->assertNull( $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'lw_migration_export_estimate'" ), 'The size estimate describes the site before the reset.' );
		$this->assertSame( 'a:1:{i:0;s:29:"lw-migration/lw-migration.php";}', $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'active_plugins'" ) );
		$this->assertSame( 'roles', $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'wp_user_roles'" ) );
		$this->assertNull( $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'lwtab12_user_roles'" ) );
		$this->assertSame( 'twentytwentyfive', $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'template'" ), 'No theme given: the fresh default stays.' );
	}

	public function test_the_live_theme_is_kept_when_given_and_a_replay_is_harmless(): void {
		$carry = new TmpCarry( self::db(), 'lwtab12_', 'wp_' );
		$carry->carry( 7, 'anna', 'lw-migration/lw-migration.php', [ 'template' => 'parentx', 'stylesheet' => 'child' ] );
		$carry->carry( 7, 'anna', 'lw-migration/lw-migration.php', [ 'template' => 'parentx', 'stylesheet' => 'child' ] );
		$this->assertSame( 'child', $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'stylesheet'" ) );
		$this->assertSame( '1', $this->value( 'SELECT COUNT(*) FROM lwtab12_users WHERE ID = 7' ) );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_usermeta WHERE user_id = 7 AND meta_key = 'session_tokens'" ) );
		$this->assertSame( 'roles', $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = 'wp_user_roles'" ), 'The renamed roles survive a replay.' );
	}

	public function test_a_user_that_changed_is_not_carried(): void {
		$this->assertFalse( ( new TmpCarry( self::db(), 'lwtab12_', 'wp_' ) )->carry( 7, 'not-anna', 'lw-migration/lw-migration.php', null ) );
		$this->assertFalse( ( new TmpCarry( self::db(), 'lwtab12_', 'wp_' ) )->carry( 99, 'anna', 'lw-migration/lw-migration.php', null ) );
		$this->assertNull( $this->value( 'SELECT ID FROM lwtab12_users WHERE ID = 7' ), 'Nothing was written.' );
	}

	public function test_every_row_of_ours_survives(): void {
		$names = array_merge( Options::all(), [ Options::SCHEDULE_LOG_PREFIX . 'abcd1234' ] );
		$this->exec( 'DELETE FROM wp_options WHERE option_name LIKE ' . "'lw\\_migration\\_%'" );
		foreach ( $names as $name ) {
			$this->exec( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('{$name}', 'v-{$name}', 'off')" );
		}
		( new TmpCarry( self::db(), 'lwtab12_', 'wp_' ) )->carry( 7, 'anna', 'lw-migration/lw-migration.php', null );
		foreach ( $names as $name ) {
			$this->assertSame( 'v-' . $name, $this->value( "SELECT option_value FROM lwtab12_options WHERE option_name = '{$name}'" ), $name );
		}
	}
}
