<?php
/**
 * Crafted sites and single-site archives restored into a network never give
 * anyone a right beyond the restored site: hostile data is planted on a
 * source, backed up with our own export and checked through WordPress itself.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

final class NetworkSecurityE2ETest extends NetworkE2ETestCase {

	private const CRAFTED = 'http://crafted.net-s.test';

	/** Rights a restored user must not have on the main site. */
	private const MAIN_RIGHTS = [ 'manage_network_options', 'manage_network_users', 'manage_options' ];

	public function test_a_crafted_sites_archive_gives_no_right_beyond_the_restored_site(): void {
		$this->changed = [ 'n', 's' ];
		WpSites::ok( 'n', 'eval-file', __DIR__ . '/plant-crafted-network.php' );
		$this->assertContains( 'editor2', self::lines( WpSites::ok( 'n', 'super-admin', 'list' ) ), 'The source really is hostile.' );
		[ , $archive ] = self::export( 'n', '--sites=2' );
		$this->assertSame( "''", trim( WpSites::ok( 's', 'eval', 'var_export( get_user_meta( 1, "_application_passwords", true ) );' ) ), 'The admin of s has no application passwords before.' );
		$before  = trim( WpSites::ok( 's', 'eval', 'echo wp_json_encode( get_site_option( "site_admins" ) );' ) );
		$roleOf  = 'echo implode( ",", get_user_by( "login", "editor2" )->roles );';
		$canMain = 'echo wp_json_encode( array_map( static fn( $cap ) => user_can( get_user_by( "login", "editor2" ), $cap ), ' . var_export( self::MAIN_RIGHTS, true ) . ' ) );';

		$out = self::import( 's', $archive, '--site-url=' . self::CRAFTED );

		// (a) A main-site role and raw network rights in the archive.
		$this->assertSame( '', trim( WpSites::ok( 's', 'eval', $roleOf ) ), 'No role on the main site.' );
		$this->assertNotContains( 'editor2', self::lines( WpSites::ok( 's', 'super-admin', 'list' ) ) );
		$this->assertSame( '[false,false,false]', trim( WpSites::ok( 's', 'eval', $canMain ) ) );
		$this->assertSame( '', trim( self::evalOn( 's', self::CRAFTED, $roleOf ) ), 'A role with a raw network right next to it is dropped on the restored site.' );
		$this->assertSame( 'false', trim( self::evalOn( 's', self::CRAFTED, 'var_export( user_can( get_user_by( "login", "editor2" ), "manage_network_options" ) );' ) ) );
		$this->assertStringContainsString( 'These users got no role on the restored sites, because the archive gave them roles those sites do not have or rights beyond a role: editor2.', $out );
		// (b) Application passwords and (e) network settings.
		$this->assertSame( "''", trim( WpSites::ok( 's', 'eval', 'var_export( get_user_meta( 1, "_application_passwords", true ) );' ) ), 'The source admin\'s application passwords are not added to the admin of s.' );
		$this->assertSame( $before, trim( WpSites::ok( 's', 'eval', 'echo wp_json_encode( get_site_option( "site_admins" ) );' ) ), 'The super admins stay as they were.' );
		$this->assertSame( "''", trim( WpSites::ok( 's', 'eval', 'var_export( get_user_meta( get_user_by( "login", "editor2" )->ID, "_application_passwords", true ) );' ) ) );
		// (c) Roles named after a network right, odd role names, network rights in a role.
		$this->assertCleanRoles( 's', self::CRAFTED, $out );
		$this->assertNoLeftovers( 's' );
	}

	public function test_repeated_role_rows_leave_one_clean_row(): void {
		$this->changed = [ 'n', 's' ];
		$this->assertSame( '4', trim( WpSites::ok( 'n', 'eval-file', __DIR__ . '/plant-repeated-roles.php' ) ), 'The source holds the roles four times (case, NBSP, U+3000), the last ones hostile.' );
		[ , $archive ] = self::export( 'n', '--sites=2' );

		self::import( 's', $archive, '--site-url=' . self::CRAFTED );

		$id = self::siteId( 's', self::CRAFTED . '/' );
		$this->assertSame( '1', self::value( 's', "SELECT COUNT(*) FROM wp_{$id}_options WHERE option_name = 'wp_{$id}_user_roles'" ), 'One row, as the table compares names.' );
		$this->assertSame( 'wp_' . $id . '_user_roles', self::value( 's', "SELECT option_name FROM wp_{$id}_options WHERE option_name = 'wp_{$id}_user_roles' AND autoload IN ('yes', 'on')" ), 'Exactly the WordPress name, autoloaded.' );
		$this->assertSame( 'false', trim( self::evalOn( 's', self::CRAFTED, 'var_export( get_role( "editor" )->has_cap( "manage_network_users" ) );' ) ) );
		$this->assertNoLeftovers( 's' );
	}

	public function test_a_crafted_single_site_archive_gives_no_right_beyond_the_new_site(): void {
		$this->changed = [ 'a', 'n' ];
		$site          = NetworkSeed::HOME . '/crafted';
		WpSites::ok( 'a', 'eval-file', __DIR__ . '/plant-crafted-single.php' );
		[ , $archive ] = self::export( 'a' );
		$roleOf        = 'echo implode( ",", get_user_by( "login", "evil1" )->roles );';

		$out = self::import( 'n', $archive, '--site-url=' . $site );

		$this->assertSame( '', trim( WpSites::ok( 'n', 'eval', $roleOf ) ), 'No role on the main site.' );
		$this->assertNotContains( 'evil1', self::lines( WpSites::ok( 'n', 'super-admin', 'list' ) ) );
		$this->assertSame( '[false,false,false]', trim( WpSites::ok( 'n', 'eval', 'echo wp_json_encode( array_map( static fn( $cap ) => user_can( get_user_by( "login", "evil1" ), $cap ), ' . var_export( self::MAIN_RIGHTS, true ) . ' ) );' ) ) );
		$this->assertSame( '', trim( self::evalOn( 'n', $site, $roleOf ) ) );
		$this->assertSame( 'false', trim( self::evalOn( 'n', $site, 'var_export( user_can( get_user_by( "login", "evil1" ), "manage_network_options" ) );' ) ) );
		$this->assertStringContainsString( 'rights beyond a role: evil1.', $out );
		$this->assertCleanRoles( 'n', $site, $out );
		$this->assertNoLeftovers( 'n' );
	}

	/**
	 * Final review I1 and re-review: plugins and themes a sites archive brings
	 * are not written at all (the restored site's allowedthemes would let its
	 * administrator switch to them), never activated, and a new PHP file in a
	 * shared folder is not restored.
	 */
	public function test_code_a_sites_archive_brings_is_never_written_or_activated(): void {
		$this->changed = [ 'n', 's' ];
		$planted       = [ '/wp-content/plugins/evil', '/wp-content/themes/evil-theme', '/wp-content/languages/evil-hu_HU.l10n.php' ];
		try {
			self::plantCode( WpSites::path( 'n' ) );
			WpSites::ok( 'n', 'plugin', 'activate', 'evil', '--network' );
			WpSites::ok( 'n', 'eval', 'update_blog_option( 2, "active_plugins", array_merge( (array) get_blog_option( 2, "active_plugins" ), [ "evil/evil.php" ] ) ); update_blog_option( 2, "template", "evil-theme" ); update_blog_option( 2, "stylesheet", "evil-theme" ); update_blog_option( 2, "allowedthemes", [ "evil-theme" => true ] );' );
			[ , $archive ] = self::export( 'n', '--sites=2' );
			$this->assertDirectoryDoesNotExist( WpSites::path( 's' ) . '/wp-content/plugins/evil', 'Network s does not have the plugin before.' );

			$out = self::import( 's', $archive, '--site-url=' . self::CRAFTED );

			$this->assertDirectoryDoesNotExist( WpSites::path( 's' ) . '/wp-content/plugins/evil', 'The plugin is not written.' );
			$this->assertDirectoryDoesNotExist( WpSites::path( 's' ) . '/wp-content/themes/evil-theme', 'The theme is not written, so the site\'s allowedthemes cannot reach it.' );
			$this->assertSame( 'false', trim( self::evalOn( 's', self::CRAFTED, 'var_export( wp_get_theme( "evil-theme" )->exists() );' ) ) );
			$this->assertNotContains( 'evil', self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) ), 'Not network-active.' );
			$this->assertContains( 'lw-migration', self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) ) );
			$this->assertSame( 'false', trim( self::evalOn( 's', self::CRAFTED, 'var_export( is_plugin_active( "evil/evil.php" ) || defined( "LWM_EVIL" ) );' ) ), 'Not active on the restored site and never run.' );
			$this->assertSame( 'false', trim( WpSites::ok( 's', 'eval', 'var_export( is_plugin_active( "evil/evil.php" ) || defined( "LWM_EVIL" ) );' ) ), 'Not active on the main site.' );
			$this->assertNotSame( 'evil-theme', trim( self::evalOn( 's', self::CRAFTED, 'echo get_stylesheet();' ) ), 'The restored site keeps a theme the network had.' );
			$this->assertFileDoesNotExist( WpSites::path( 's' ) . '/wp-content/languages/evil-hu_HU.l10n.php', 'A new PHP file in a shared folder is not restored.' );
			$this->assertMatchesRegularExpression( '/Not restored, because the network does not have them: [^\n]*plugins\/evil[^\n]*themes\/evil-theme[^\n]*\. Install them on the network if you trust them\./', $out );
			$this->assertStringContainsString( 'Did not restore 1 new PHP or server configuration file into the shared wp-content folders', $out );
			$this->assertNoLeftovers( 's' );
		} finally {
			foreach ( [ 'n', 's' ] as $site ) {
				foreach ( $planted as $rel ) {
					self::remove( WpSites::path( $site ) . $rel );
				}
			}
		}
	}

	private static function plantCode( string $root ): void {
		$files = [
			'/wp-content/plugins/evil/evil.php'            => "<?php\n/**\n * Plugin Name: Evil\n */\ndefine( 'LWM_EVIL', true );\n",
			'/wp-content/themes/evil-theme/style.css'      => "/*\nTheme Name: Evil Theme\n*/\n",
			'/wp-content/themes/evil-theme/index.php'      => "<?php\n// Silence.\n",
			'/wp-content/languages/evil-hu_HU.l10n.php'    => "<?php\nreturn [];\n",
		];
		foreach ( $files as $rel => $contents ) {
			if ( ! is_dir( dirname( $root . $rel ) ) ) {
				mkdir( dirname( $root . $rel ), 0755, true );
			}
			file_put_contents( $root . $rel, $contents );
		}
	}

	private static function remove( string $path ): void {
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), [ '.', '..' ] ) as $name ) {
			self::remove( $path . '/' . $name );
		}
		rmdir( $path );
	}

	/**
	 * No role named after a network right or with an odd name, and no network right in the editor role.
	 */
	private function assertCleanRoles( string $network, string $url, string $out ): void {
		$roles = self::lines( self::evalOn( $network, $url, 'echo implode( "\n", array_keys( wp_roles()->roles ) );' ) );
		$this->assertNotContains( 'manage_network_users', $roles );
		$this->assertNotContains( 'evil role!', $roles );
		$this->assertContains( 'editor', $roles );
		$this->assertSame( '[false,false]', trim( self::evalOn( $network, $url, '$e = get_role( "editor" ); echo wp_json_encode( [ $e->has_cap( "manage_network_users" ), $e->has_cap( "manage_network_plugins" ) ] );' ) ) );
		$this->assertStringContainsString( 'were checked: rights over the whole network were removed', $out );
	}
}
