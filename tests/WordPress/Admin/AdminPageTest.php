<?php
/**
 * Tests for Admin\AdminPage.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Admin\AdminPage;
use LightweightPlugins\Migration\Admin\Hub\Hub;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class AdminPageTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'wp_login_url' )->alias( static fn( string $redirect = '' ): string => 'https://site.test/wp-login.php?redirect_to=' . rawurlencode( $redirect ) );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://site.test' );
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => 'admin_email' === $name ? 'admin@site.test' : $fallback );
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		parent::tearDown();
	}

	public function test_the_mount_point_is_not_inside_wrap_and_needs_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		( new AdminPage() )->render();
		$this->assertSame( '', ob_get_clean() );

		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		( new AdminPage() )->render();
		$html = (string) ob_get_clean();
		$this->assertSame( '<div id="lw-migration-root" class="lw-migration-root"></div>', $html );
	}

	public function test_boot_data_is_whitelisted(): void {
		$_GET['tab'] = 'settings';
		$data        = AdminPage::scriptData();
		$this->assertSame( [ 'version', 'namespace', 'chunkBytes', 'tab', 'loginUrl', 'adminEmail', 'siteFolder', 'network' ], array_keys( $data ) );
		$this->assertSame( 'site-test', $data['siteFolder'] );
		$this->assertNull( $data['network'], 'A single site is no network.' );
		$this->assertSame( 'admin@site.test', $data['adminEmail'] );
		$this->assertSame( 'settings', $data['tab'] );
		$this->assertSame( 'lw-migration/v1', $data['namespace'] );
		$this->assertSame( '1.0.0', $data['version'] );
		$this->assertGreaterThan( 0, $data['chunkBytes'] );
		$this->assertSame( 'https://site.test/wp-login.php?redirect_to=' . rawurlencode( 'https://site.test/wp-admin/admin.php?page=lw-migration' ), $data['loginUrl'] );

		$_GET['tab'] = 'schedules';
		$this->assertSame( 'schedules', AdminPage::scriptData()['tab'] );

		$_GET['tab'] = 'connections'; // The login link of the Connections section lands there.
		$this->assertSame( 'connections', AdminPage::scriptData()['tab'] );

		$_GET['tab'] = 'reset'; // The login link of the Reset section lands there.
		$this->assertSame( 'reset', AdminPage::scriptData()['tab'] );

		$_GET['tab'] = 'destinations'; // The reconnect mail links there.
		$this->assertSame( 'destinations', AdminPage::scriptData()['tab'] );

		$_GET['tab'] = '"><script>';
		$this->assertSame( 'overview', AdminPage::scriptData()['tab'] );

		unset( $_GET['tab'] ); // The page opens on the Overview.
		$this->assertSame( 'overview', AdminPage::scriptData()['tab'] );

		$_GET['tab'] = 'overview'; // The Overview's login link lands there.
		$this->assertSame( 'overview', AdminPage::scriptData()['tab'] );
	}

	public function test_no_assets_on_other_screens(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		( new AdminPage() )->enqueue( 'edit.php' );
	}

	public function test_assets_load_on_this_screen(): void {
		$page = new AdminPage();
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'lw-migration-admin', LW_MIGRATION_URL . 'build/index.js', \Mockery::type( 'array' ), \Mockery::type( 'string' ), true );
		Functions\expect( 'wp_set_script_translations' )->once()->with( 'lw-migration-admin', 'lw-migration', LW_MIGRATION_PATH . 'languages' );
		Functions\expect( 'wp_add_inline_script' )->once()->with( 'lw-migration-admin', \Mockery::pattern( '/^window\.lwMigration = \{"version":"1\.0\.0",.*"loginUrl":.*\};$/' ), 'before' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'lw-migration-admin', LW_MIGRATION_URL . 'build/index.css', [ 'wp-components' ], \Mockery::type( 'string' ) );
		Functions\expect( 'wp_style_add_data' )->once()->with( 'lw-migration-admin', 'rtl', 'replace' );
		$page->enqueue( 'lw-plugins_page_lw-migration' );
	}

	public function test_the_menu_entry_uses_the_rest_capability(): void {
		Filters\expectApplied( 'lw_migration_capability' )->once()->andReturn( 'manage_options' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'add_submenu_page' )->once()->with( 'lw-plugins', 'Migration', 'Migration', 'manage_options', 'lw-migration', \Mockery::type( 'array' ) )->andReturn( 'lw-plugins_page_lw-migration' );
		// No hub copy initialised in this process: ParentPage::maybe_register() adds nothing.
		Hub::reset();
		( new AdminPage( static fn(): int => 0 ) )->addMenu();
	}

	public function test_the_menu_entry_shows_how_many_backups_there_are(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n ) );
		Hub::reset();
		Functions\expect( 'add_submenu_page' )->once()->with(
			'lw-plugins',
			'Migration',
			'Migration <span class="awaiting-mod count-1234"><span class="pending-count" aria-hidden="true">1,234</span><span class="screen-reader-text">1,234 backups</span></span>',
			\Mockery::any(),
			'lw-migration',
			\Mockery::type( 'array' )
		)->andReturn( 'lw-plugins_page_lw-migration' );

		( new AdminPage( static fn(): int => 1234 ) )->addMenu();
	}

	/**
	 * @dataProvider provide_no_count
	 */
	public function test_the_menu_entry_has_no_count( bool $allowed, \Closure $count ): void {
		Functions\when( 'current_user_can' )->justReturn( $allowed );
		Hub::reset();
		Functions\expect( 'add_submenu_page' )->once()->with( 'lw-plugins', 'Migration', 'Migration', \Mockery::any(), 'lw-migration', \Mockery::type( 'array' ) )->andReturn( '' );

		( new AdminPage( $count ) )->addMenu();
	}

	/**
	 * @return array<string, array{bool, \Closure}>
	 */
	public static function provide_no_count(): array {
		return [
			'no backups'                  => [ true, static fn(): int => 0 ],
			'a folder that cannot be read' => [ true, static function (): int {
				throw new \RuntimeException( 'unreadable' );
			} ],
			'a user who cannot open it'   => [ false, static function (): int {
				throw new \LogicException( 'Never counted for this user.' );
			} ],
		];
	}

	public function test_on_a_network_the_page_is_under_network_settings(): void {
		$this->network( true );
		Actions\expectAdded( 'network_admin_menu' )->once();
		Actions\expectAdded( 'admin_menu' )->once(); // The main site's own admin: the notice that points to the network page.
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		AdminPage::register();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'add_submenu_page' )->once()->with( 'settings.php', 'Migration', 'Migration', 'manage_network_options', 'lw-migration', \Mockery::type( 'array' ) )->andReturn( 'settings_page_lw-migration' );
		( new AdminPage( static fn(): int => 0 ) )->addNetworkMenu();
	}

	public function test_another_subsite_gets_no_menu_entry_only_the_activation_notice(): void {
		$this->network( false );
		Actions\expectAdded( 'admin_menu' )->never();
		Actions\expectAdded( 'network_admin_menu' )->once();
		Actions\expectAdded( 'admin_notices' )->once()->with( [ \LightweightPlugins\Migration\Admin\SubsiteNotice::class, 'render' ] );
		AdminPage::register();
	}

	public function test_on_a_network_the_assets_load_only_on_the_network_screen(): void {
		$this->network( true );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\expect( 'wp_enqueue_script' )->never();
		$page = new AdminPage();
		$page->enqueue( 'lw-plugins_page_lw-migration' );
		$page->enqueue( 'settings_page_lw-migration' );
	}

	public function test_on_the_network_screen_the_app_loads(): void {
		$this->network( true );
		Functions\when( 'is_network_admin' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'lw-migration-admin', LW_MIGRATION_URL . 'build/index.js', \Mockery::type( 'array' ), \Mockery::type( 'string' ), true );
		Functions\expect( 'wp_add_inline_script' )->once()->with( 'lw-migration-admin', \Mockery::on( static fn( string $js ): bool => str_contains( $js, '"network":{"subdomain":false,"mainUrl":"http:\\/\\/net.test\\/","siteCount":3}' ) ), 'before' );
		$page = new AdminPage();
		$page->enqueue( 'lw-plugins_page_lw-migration' ); // A site admin screen of the same name loads nothing.
		$page->enqueue( 'settings_page_lw-migration' );
	}

	public function test_the_network_screen_gets_the_body_class(): void {
		$this->network( true );
		Functions\when( 'is_network_admin' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'settings_page_lw-migration-network' ] );

		$this->assertSame( 'a lw-migration-screen', ( new AdminPage() )->bodyClass( 'a' ) );
	}

	public function test_the_main_site_page_points_to_the_network_page(): void {
		$this->network( true );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		( new AdminPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="wrap">', $html );
		$this->assertStringContainsString( 'class="notice notice-info lw-notice"', $html );
		$this->assertStringContainsString( 'Migrations of this network are under Network Admin &gt; Settings &gt; Migration.', $html );
		$this->assertStringContainsString( 'href="http://net.test/wp-admin/network/settings.php?page=lw-migration"', $html );
		$this->assertStringNotContainsString( 'lw-migration-root', $html );
	}

	public function test_the_network_page_mounts_the_app(): void {
		$this->network( true );
		Functions\when( 'is_network_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		( new AdminPage() )->render();

		$this->assertSame( '<div id="lw-migration-root" class="lw-migration-root"></div>', ob_get_clean() );
	}

	public function test_boot_data_on_a_network(): void {
		$this->network( true );
		$data = AdminPage::scriptData();

		$this->assertSame(
			[
				'subdomain' => false,
				'mainUrl'   => 'http://net.test/',
				'siteCount' => 3,
			],
			$data['network']
		);
		$this->assertSame( 'https://site.test/wp-login.php?redirect_to=' . rawurlencode( 'http://net.test/wp-admin/network/settings.php?page=lw-migration' ), $data['loginUrl'] );
	}

	/**
	 * A subdirectory network of three sites; the request is on its main site (or not).
	 */
	private function network( bool $mainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		Functions\when( 'is_subdomain_install' )->justReturn( false );
		Functions\when( 'network_home_url' )->justReturn( 'http://net.test/' );
		Functions\when( 'get_blog_count' )->justReturn( 99 ); // Final review M5: a stale cached count is never shown.
		Functions\when( 'get_sites' )->alias( static fn( array $args ): int => [ 'network_id' => 1, 'number' => 0, 'count' => true ] === $args ? 3 : -1 );
		Functions\when( 'network_admin_url' )->alias( static fn( string $path = '' ): string => 'http://net.test/wp-admin/network/' . $path );
	}
}
