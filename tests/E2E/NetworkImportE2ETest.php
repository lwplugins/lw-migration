<?php
/**
 * Restores into real networks: network to network, the reference-made archives,
 * a single-site archive as a new subsite, and the refusals.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

final class NetworkImportE2ETest extends NetworkE2ETestCase {

	public function test_network_to_network_keeps_the_admin_and_the_plugin(): void {
		$this->changed[] = 's';
		$extra           = (int) trim( WpSites::ok( 's', 'site', 'create', '--slug=extra', '--porcelain' ) );

		$out = self::import( 's', self::$resets['n'] );

		$this->assertStringContainsString( 'Import complete: 3 sites restored. The previous database is saved as pre-import-', $out );
		$this->assertStringContainsString( 'http://extra.net-s.test', $out, 'The site the archive leaves out is named.' );
		$this->assertContains( 'Network Fixture B2', self::lines( WpSites::wpUrl( 's', 'http://b2.net-s.test', 'post', 'list', '--field=post_title' )[1] ) );
		$this->assertSame( [ self::S . '/', 'http://b2.net-s.test/', 'http://b3.net-s.test/', 'http://extra.net-s.test/' ], self::urls( 's' ), 'Final review I2: the left-out site stays on the network.' );
		$this->assertSame( '1', self::value( 's', 'SELECT deleted FROM wp_blogs WHERE blog_id = ' . $extra ), 'Marked as deleted (Network Admin > Sites).' );
		$this->assertStringContainsString( 'They stay on the network marked as deleted', $out );
		$this->assertContains( 'admin', self::lines( WpSites::ok( 's', 'super-admin', 'list' ) ) );
		$this->assertSame( 0, WpSites::wp( 's', 'user', 'check-password', 'admin', 'admin-pass' )[0], 'The importing admin keeps its login.' );
		$this->assertContains( 'lw-migration', self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) ) );
		$this->assertFileExists( WpSites::path( 's' ) . '/wp-content/uploads/sites/2/2026/10/b2.txt' );
		$this->assertStringContainsString( 'http://b2.net-s.test/hello/', self::value( 's', "SELECT post_content FROM wp_2_posts WHERE post_title = 'Network Fixture B2'" ) );
		$this->assertNoLeftovers( 's' );

		$this->assertSame( 'wp_' . $extra . '_options', self::value( 's', "SHOW TABLES LIKE 'wp\\_" . $extra . "\\_options'" ), 'The left-out site\'s tables stay.' );
		$next = (int) trim( WpSites::ok( 's', 'site', 'create', '--slug=after', '--porcelain' ) );
		$this->assertGreaterThan( $extra, $next, 'A new site never reuses a left-out site\'s ID (ruling R16).' );
	}

	/**
	 * Review flag (site identity collision): a left-out site at the address
	 * of an archive site never shadows it in WordPress' site lookup.
	 */
	public function test_a_left_out_site_at_an_archive_sites_address_never_shadows_it(): void {
		$this->changed[] = 's';
		$dup             = (int) trim( WpSites::ok( 's', 'site', 'create', '--slug=dup', '--porcelain' ) );
		WpSites::ok( 's', 'eval', 'global $wpdb; $wpdb->update( $wpdb->blogs, [ "domain" => "B2.net-s.test" ], [ "blog_id" => ' . $dup . ' ] );' );

		$out = self::import( 's', self::$resets['n'] );

		$this->assertSame( '2', trim( WpSites::ok( 's', 'eval', 'echo get_site_by_path( "b2.net-s.test", "/" )->blog_id;' ) ), 'WordPress resolves the archive site.' );
		$this->assertContains( 'Network Fixture B2', self::lines( WpSites::wpUrl( 's', 'http://b2.net-s.test', 'post', 'list', '--field=post_title' )[1] ) );
		$this->assertSame( '/lwm-left-out-' . $dup . '/|1', self::value( 's', 'SELECT CONCAT(path, "|", deleted) FROM wp_blogs WHERE blog_id = ' . $dup ), 'The left-out site is kept aside, marked deleted.' );
		$this->assertSame( 'wp_' . $dup . '_options', self::value( 's', "SHOW TABLES LIKE 'wp\\_" . $dup . "\\_options'" ), 'Its tables stay.' );
		$this->assertMatchesRegularExpression( '#so they were kept under a new address: \\S+ -> B2\\.net-s\\.test/lwm-left-out-' . $dup . '/#', $out );
		$this->assertNoLeftovers( 's' );
	}

	public function test_reference_archives_restore_into_a_network(): void {
		$this->changed = [ 's', 'n' ];

		self::import( 's', self::FIXTURES . 'network.wpress' );
		$this->assertSame( [ self::S . '/', 'http://b2.net-s.test/', 'http://b3.net-s.test/' ], self::urls( 's' ) );
		$this->assertSame( [ '1', '2', '3' ], self::lines( WpSites::ok( 's', 'site', 'list', '--field=blog_id' ) ) );
		$this->assertSame( 'editor', trim( WpSites::wpUrl( 's', 'http://b2.net-s.test', 'user', 'get', 'editor2', '--field=roles' )[1] ) );

		self::import( 's', self::FIXTURES . 'sites-2.wpress', '--site-url=http://shop.net-s.test' );
		$shop = self::siteId( 's', 'http://shop.net-s.test/' );
		$this->assertGreaterThan( 3, $shop );
		$this->assertContains( 'Network Fixture B2', self::lines( WpSites::wpUrl( 's', 'http://shop.net-s.test', 'post', 'list', '--field=post_title' )[1] ) );
		$this->assertSame( '1', self::value( 's', "SELECT COUNT(*) FROM wp_users WHERE user_login = 'editor2'" ) );
		$editor = self::value( 's', "SELECT ID FROM wp_users WHERE user_login = 'editor2'" );
		$this->assertSame( $editor, self::value( 's', "SELECT post_author FROM wp_{$shop}_posts WHERE post_title = 'By editor2'" ) );
		$this->assertFileExists( WpSites::path( 's' ) . '/wp-content/uploads/sites/' . $shop . '/2026/10/b2.txt' );
		$this->assertNoLeftovers( 's' );

		$marker = trim( NetworkSeed::okUrl( NetworkSeed::HOME . '/b2/', 'post', 'create', '--post_title=Only here before', '--post_status=publish', '--porcelain' ) );
		$this->assertNotSame( '', $marker );
		$before = self::urls( 'n' );
		self::import( 'n', self::FIXTURES . 'subdomain-sites-2.wpress' );
		$this->assertSame( $before, self::urls( 'n' ), 'The proposal http://net-n.test/b2 reuses blog 2.' );
		$titles = self::lines( NetworkSeed::okUrl( NetworkSeed::HOME . '/b2/', 'post', 'list', '--field=post_title' ) );
		$this->assertContains( 'Network Fixture B2', $titles );
		$this->assertNotContains( 'Only here before', $titles, 'Blog 2 holds the archive\'s posts.' );

		WpSites::ok( 's', 'post', 'create', '--post_title=Main stays', '--post_status=publish' );
		$uploads = WpSites::path( 's' ) . '/wp-content/uploads';
		if ( is_file( $uploads . '/2026/10/main.txt' ) ) {
			unlink( $uploads . '/2026/10/main.txt' ); // Restored with network.wpress above.
		}
		$mainOptions = [ trim( WpSites::ok( 's', 'option', 'get', 'blogname' ) ), trim( WpSites::ok( 's', 'option', 'get', 'home' ) ) ];
		self::import( 's', self::FIXTURES . 'sites-1-3.wpress', '--site-url=http://main1.net-s.test,http://b3x.net-s.test' );
		$this->assertFileExists( $uploads . '/sites/' . self::siteId( 's', 'http://main1.net-s.test/' ) . '/2026/10/main.txt', 'The old main site\'s uploads go to its new site.' );
		$this->assertFileExists( $uploads . '/sites/' . self::siteId( 's', 'http://b3x.net-s.test/' ) . '/2026/10/b3.txt' );
		$this->assertFileDoesNotExist( $uploads . '/2026/10/main.txt', 'The main site\'s uploads are never written.' );
		$this->assertSame( $mainOptions, [ trim( WpSites::ok( 's', 'option', 'get', 'blogname' ) ), trim( WpSites::ok( 's', 'option', 'get', 'home' ) ) ] );
		$this->assertContains( 'Network Fixture Main', self::lines( WpSites::wpUrl( 's', 'http://main1.net-s.test', 'post', 'list', '--field=post_title' )[1] ), 'The old main site landed in the first new site.' );
		$this->assertContains( 'Network Fixture B3', self::lines( WpSites::wpUrl( 's', 'http://b3x.net-s.test', 'post', 'list', '--field=post_title' )[1] ) );
		$this->assertContains( 'Main stays', self::lines( WpSites::ok( 's', 'post', 'list', '--field=post_title' ) ), 'The main site is never written.' );
		$this->assertSame( 'http://main1.net-s.test', trim( WpSites::wpUrl( 's', 'http://main1.net-s.test', 'option', 'get', 'home' )[1] ) );
	}

	public function test_a_sites_archive_merges_its_users_with_only_the_restored_site_roles(): void {
		$this->changed[] = 's';

		$out = self::import( 's', self::FIXTURES . 'sites-2.wpress', '--site-url=http://shop.net-s.test' );

		$this->assertStringContainsString( 'Site http://net-n.test/b2 -> http://shop.net-s.test', $out );
		$this->assertStringContainsString( 'Import complete: 1 site restored.', $out );
		$this->assertStringContainsString( "Kept the network's own copies of these plugins and themes", $out, 'Shared code is never overwritten by a sites restore.' );
		$this->assertStringContainsString( 'Did not restore 1 must-use plugin or wp-content root file', $out );
		$this->assertSame( 'editor', trim( WpSites::wpUrl( 's', 'http://shop.net-s.test', 'user', 'get', 'editor2', '--field=roles' )[1] ) );
		$this->assertSame( '', trim( WpSites::ok( 's', 'user', 'get', 'editor2', '--field=roles' ) ), 'No role on the main site.' );
		$this->assertNotContains( 'editor2', self::lines( WpSites::ok( 's', 'super-admin', 'list' ) ) );
		$caps = 'echo user_can( get_user_by( "login", "editor2" ), "manage_network_users" ) || user_can( get_user_by( "login", "editor2" ), "manage_network_options" ) ? "yes" : "no";';
		$this->assertSame( 'no', trim( WpSites::ok( 's', 'eval', $caps ) ), 'No network capability.' );
		$this->assertSame( 'no', trim( WpSites::wpUrl( 's', 'http://shop.net-s.test', 'eval', $caps )[1] ) );
		$this->assertSame( 0, WpSites::wp( 's', 'user', 'check-password', 'admin', 'admin-pass' )[0], 'The importing admin is unchanged.' );
		$this->assertContains( 'admin', self::lines( WpSites::ok( 's', 'super-admin', 'list' ) ) );
		$this->assertNoLeftovers( 's' );
	}

	public function test_a_single_site_archive_becomes_a_subsite(): void {
		$this->changed[] = 'n';
		$shop            = NetworkSeed::HOME . '/shop';
		WpSites::ok( 'n', 'option', 'update', 'upload_path', 'wp-content/custom-up' );
		try {
			self::import( 'n', self::FIXTURES . 'full-site.wpress', '--site-url=' . $shop );

			$this->assertContains( 'Reference Fixture Post', self::lines( NetworkSeed::okUrl( $shop, 'post', 'list', '--field=post_title' ) ) );
			$this->assertSame( 'editor', trim( NetworkSeed::okUrl( $shop, 'user', 'get', 'editor1', '--field=roles' ) ) );
			$this->assertSame( $shop, trim( NetworkSeed::okUrl( $shop, 'option', 'get', 'home' ) ) );
			$uploads = trim( NetworkSeed::okUrl( $shop, 'eval', 'echo wp_upload_dir( null, false )["basedir"];' ) );
			$this->assertStringEndsWith( '/wp-content/custom-up/sites/' . self::siteId( 'n', NetworkSeed::HOME . '/shop/' ), $uploads );
			$this->assertFileExists( $uploads . '/2026/10/ref.txt', 'The uploads land in the site\'s own folder, also with a custom upload path.' );
			$this->assertNoLeftovers( 'n' );
		} finally {
			WpSites::ok( 'n', 'option', 'delete', 'upload_path' );
		}
	}

	public function test_a_subsite_archive_still_restores_into_a_single_site(): void {
		$this->changed[] = 'a';

		self::import( 'a', self::FIXTURES . 'sites-2.wpress' );

		$this->assertSame( 'http://site-a.test', trim( WpSites::ok( 'a', 'option', 'get', 'home' ) ) );
		$this->assertContains( 'Network Fixture B2', self::lines( WpSites::ok( 'a', 'post', 'list', '--field=post_title' ) ) );
	}

	public function test_refusals(): void {
		$sites = self::urls( 's' );

		[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'import', self::FIXTURES . 'network.wpress', '--yes' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, 'It can only be restored into a multisite network, not into a single site.' ) ], $err );

		[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'import', self::FIXTURES . 'sites-2.wpress', '--yes', '--site-url=http://x.test' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, '--site-url works only when restoring into a multisite network.' ) ], $err );

		[ $code, $out, $err ] = WpSites::wp( 's', 'lw-migration', 'import', self::FIXTURES . 'sites-1-3.wpress', '--yes', '--user=admin' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, 'The main site cannot be replaced by a subsite import: choose another address.' ) ], $out . $err );

		[ $code, , $err ] = WpSites::wpUrl( 's', 'http://b2.net-s.test', 'lw-migration', 'import', self::FIXTURES . 'sites-2.wpress', '--yes' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, "Run this command on the network's main site" ) ], $err );

		[ $code, , $err ] = WpSites::wp( 's', 'lw-migration', 'import', self::FIXTURES . 'sites-1-3.wpress', '--yes', '--user=admin', '--site-url=http://dup.net-s.test,http://dup.net-s.test' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, 'Two sites of the archive cannot get the same address.' ) ], $err );

		[ $code, , $err ] = WpSites::wp( 's', 'lw-migration', 'import', self::FIXTURES . 'network.wpress', '--yes', '--user=admin', '--site-url=http://x.net-s.test' );
		$this->assertSame( [ 1, true ], [ $code, str_contains( $err, 'Leave out --site-url.' ) ], $err );

		$this->assertSame( $sites, self::urls( 's' ), 'No site was created.' );
		$this->assertNoLeftovers( 's' );
	}

	public function test_a_failure_before_any_file_is_written_removes_the_created_site(): void {
		$sites   = self::urls( 'n' );
		$next    = self::nextSiteId( 'n' );
		$folder  = WpSites::path( 'n' ) . '/wp-content/uploads/sites';
		chmod( $folder, 0555 );
		try {
			[ $code, $out, $err ] = WpSites::wp( 'n', 'lw-migration', 'import', self::FIXTURES . 'sites-2.wpress', '--yes', '--user=admin', '--no-safety-backup', '--site-url=' . NetworkSeed::HOME . '/fail' );
		} finally {
			chmod( $folder, 0755 );
		}

		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'The new sites this restore had created were removed again.', $err );
		$this->assertCreatedSiteIsGone( 'n', $next, $sites );
	}

	public function test_a_failure_after_files_were_written_removes_the_created_site_and_its_uploads(): void {
		$late = WpSites::path( 'n' ) . '/wp-content/uploads/sites/2/zz/late.txt';
		mkdir( dirname( $late ), 0755, true );
		file_put_contents( $late, "late\n" );
		try {
			[ , $archive ] = self::export( 'n', '--sites=2' );
		} finally {
			unlink( $late );
			rmdir( dirname( $late ) );
		}
		$sites   = self::urls( 's' );
		$next    = self::nextSiteId( 's' );
		$blocker = WpSites::path( 's' ) . '/wp-content/uploads/sites/' . $next;
		mkdir( $blocker, 0755, true );
		file_put_contents( $blocker . '/zz', 'a file where the archive needs a folder' );

		[ $code, $out, $err ] = WpSites::wp( 's', 'lw-migration', 'import', $archive, '--yes', '--user=admin', '--no-safety-backup', '--site-url=http://late.net-s.test' );

		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'uploads/sites/2/zz/late.txt', $err );
		$this->assertStringContainsString( 'Some files from the archive were already put in place on the site', $err, 'b2.txt (2026/ sorts first) was written into the new site\'s folder.' );
		$this->assertStringContainsString( 'The new sites this restore had created were removed again.', $err );
		$this->assertCreatedSiteIsGone( 's', $next, $sites );
	}

	public function test_users_are_not_duplicated_and_keep_their_passwords(): void {
		$this->changed[] = 's';

		self::import( 's', self::FIXTURES . 'sites-2.wpress', '--site-url=http://one.net-s.test' );
		self::import( 's', self::FIXTURES . 'sites-2.wpress', '--site-url=http://two.net-s.test' );

		$this->assertSame( '1', self::value( 's', "SELECT COUNT(*) FROM wp_users WHERE user_login = 'editor2'" ) );
		$this->assertSame( 0, WpSites::wp( 's', 'user', 'check-password', 'editor2', 'editor-pass' )[0] );
		foreach ( [ 'http://one.net-s.test', 'http://two.net-s.test' ] as $url ) {
			$this->assertSame( 'editor', trim( WpSites::wpUrl( 's', $url, 'user', 'get', 'editor2', '--field=roles' )[1] ), $url );
		}
	}

	public function test_network_plugins_of_a_sites_archive_join_the_network_ones(): void {
		$this->changed[] = 's';
		$plugin          = WpSites::path( 's' ) . '/wp-content/plugins/lwm-net-sample';
		if ( is_dir( $plugin ) ) {
			unlink( $plugin . '/lwm-net-sample.php' );
			rmdir( $plugin );
		}
		[ , $withoutPlugins ] = self::export( 'n', '--sites=2', '--exclude-plugins' );
		[ , $withPlugins ]    = self::export( 'n', '--sites=2' );

		$out = self::import( 's', $withoutPlugins, '--site-url=http://noplug.net-s.test' );

		$this->assertStringContainsString( 'lwm-net-sample/lwm-net-sample.php stays inactive: it is not installed on this site.', $out );
		$active = self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) );
		$this->assertNotContains( 'lwm-net-sample', $active );
		$this->assertContains( 'lw-migration', $active );

		$out = self::import( 's', $withPlugins, '--site-url=http://withplug.net-s.test' );

		$active = self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) );
		$this->assertDirectoryDoesNotExist( $plugin, 'Final re-review: a plugin the network does not have is not written.' );
		$this->assertNotContains( 'lwm-net-sample', $active );
		$this->assertMatchesRegularExpression( '/Not restored, because the network does not have them: [^\n]*plugins\/lwm-net-sample/', $out );

		mkdir( $plugin, 0755, true ); // The network installs it.
		copy( WpSites::path( 'n' ) . '/wp-content/plugins/lwm-net-sample/lwm-net-sample.php', $plugin . '/lwm-net-sample.php' );
		self::import( 's', $withPlugins, '--site-url=http://again.net-s.test' );

		$active = self::lines( WpSites::ok( 's', 'plugin', 'list', '--status=active-network', '--field=name' ) );
		$this->assertContains( 'lwm-net-sample', $active, 'Installed before this import, the archive\'s network plugin joins the network ones.' );
		$this->assertContains( 'lw-migration', $active, 'This plugin stays network-active.' );
	}

	/**
	 * @param array<int, string> $sites The network's sites before the import.
	 */
	private function assertCreatedSiteIsGone( string $site, int $id, array $sites ): void {
		$this->assertSame( $sites, self::urls( $site ) );
		$this->assertSame( '', self::value( $site, "SHOW TABLES LIKE 'wp\\_" . $id . "\\_%'" ), 'The created site\'s tables are gone.' );
		$this->assertDirectoryDoesNotExist( WpSites::path( $site ) . '/wp-content/uploads/sites/' . $id, 'The created site\'s uploads folder is gone.' );
		$this->assertNoLeftovers( $site );
	}
}
