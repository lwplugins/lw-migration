<?php
/**
 * Import scenarios on real WordPress sites: our own archives and one made by the
 * established .wpress importer.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Backups\BackupName;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class ImportE2ETest extends E2ETestCase {

	private const FIXTURES = __DIR__ . '/../Fixtures/wpress/';

	public static function setUpBeforeClass(): void {
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$a = WpSites::path( 'a' );
		WpSites::ok( 'a', 'post', 'create', '--post_title=Import E2E', '--post_status=publish', '--post_content=<a href="http://site-a.test/sample-page/">link</a>' );
		WpSites::wp( 'a', 'user', 'create', 'alice', 'alice@site-a.test', '--role=editor', '--user_pass=alice-pass' );
		if ( ! is_dir( $a . '/wp-content/uploads/2026/10' ) ) {
			mkdir( $a . '/wp-content/uploads/2026/10', 0755, true );
		}
		file_put_contents( $a . '/wp-content/uploads/2026/10/import-e2e.txt', "import e2e\n" );
		if ( ! is_dir( $a . '/wp-content/plugins/lwm-e2e-sample' ) ) {
			mkdir( $a . '/wp-content/plugins/lwm-e2e-sample', 0755, true );
			file_put_contents( $a . '/wp-content/plugins/lwm-e2e-sample/lwm-e2e-sample.php', "<?php\n/**\n * Plugin Name: LWM E2E Sample\n */\n" );
		}
		WpSites::ok( 'a', 'plugin', 'activate', 'lwm-e2e-sample' );
		if ( ! is_dir( $a . '/wp-content/mu-plugins' ) ) {
			mkdir( $a . '/wp-content/mu-plugins', 0755, true );
		}
		file_put_contents( $a . '/wp-content/mu-plugins/lwm-e2e-mu.php', "<?php\n/**\n * Plugin Name: LWM E2E MU\n */\n" );
	}

	/** First column of the first row (through $wpdb, so no mysql client binary is needed). */
	private static function value( string $site, string $sql ): string {
		return trim( WpSites::ok( $site, 'eval', 'global $wpdb; echo (string) $wpdb->get_var( base64_decode( "' . base64_encode( $sql ) . '" ) );' ) );
	}

	private static function home( string $site ): string {
		return trim( WpSites::ok( $site, 'option', 'get', 'home' ) );
	}

	/** Nothing of a finished or failed import is left behind on the site. */
	private function assertNoLeftovers( string $site ): void {
		$this->assertNull( WpSites::lock( $site ), 'The import released the site lock.' );
		$this->assertSame( '', self::value( $site, "SHOW TABLES LIKE 'lw%'" ), 'No temporary or previous tables are left.' );
		$temps = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WpSites::path( $site ) . '/wp-content', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 1 === preg_match( '/^\.lwm-[0-9a-f]{16}\.part\z/', $file->getFilename() ) ) {
				$temps[] = $file->getPathname();
			}
		}
		$this->assertSame( [], $temps, 'No temporary files are left.' );
		$work = array_filter( (array) glob( WpSites::storage( $site ) . '/jobs/*/*', GLOB_ONLYDIR ) );
		$this->assertSame( [], array_values( $work ), 'No work folders (staging, download, database file) are left.' );
	}

	public function test_export_from_a_imports_into_b_with_new_urls(): void {
		$storage    = trim( WpSites::ok( 'b', 'option', 'get', 'lw_migration_storage_dir' ) );
		$passHash   = self::value( 'b', "SELECT user_pass FROM wp_users WHERE user_login = 'admin'" );
		$muPlugin   = WpSites::path( 'b' ) . '/wp-content/mu-plugins/lwm-e2e-mu.php';
		[ , $path ] = self::export( 'a', '--exclude-themes' );
		$this->assertFileDoesNotExist( $muPlugin );

		WpSites::ok( 'b', 'lw-migration', 'import', $path, '--yes', '--user=admin' );

		$this->assertSame( 'http://site-b.test', self::home( 'b' ) );
		$this->assertSame( 'http://site-b.test', trim( WpSites::ok( 'b', 'option', 'get', 'siteurl' ) ) );
		$content = self::value( 'b', "SELECT post_content FROM wp_posts WHERE post_title = 'Import E2E' AND post_type = 'post' LIMIT 1" );
		$this->assertStringContainsString( 'http://site-b.test/sample-page/', $content );
		$this->assertStringNotContainsString( 'site-a.test', $content );
		$this->assertSame( 'alice', trim( WpSites::ok( 'b', 'user', 'get', 'alice', '--field=user_login' ) ) );
		$this->assertSame( $passHash, self::value( 'b', "SELECT user_pass FROM wp_users WHERE user_login = 'admin'" ), 'The importing admin keeps this site\'s password.' );
		$this->assertSame( 0, WpSites::wp( 'b', 'plugin', 'is-active', 'lwm-e2e-sample' )[0] );
		$this->assertSame( 0, WpSites::wp( 'b', 'plugin', 'is-active', 'lw-migration' )[0] );
		$this->assertFileExists( WpSites::path( 'b' ) . '/wp-content/uploads/2026/10/import-e2e.txt' );
		$this->assertFileExists( $muPlugin, 'The staged must-use plugin is restored after the swap.' );
		$this->assertSame( $storage, trim( WpSites::ok( 'b', 'option', 'get', 'lw_migration_storage_dir' ) ) );
		$list = (array) json_decode( WpSites::ok( 'b', 'lw-migration', 'list', '--format=json' ), true );
		$this->assertNotSame( [], preg_grep( '/^pre-import-/', array_column( $list, 'name' ) ), 'A safety backup was taken.' );
		$this->assertNoLeftovers( 'b' );
	}

	public function test_reference_made_full_site_archive_imports(): void {
		$facts    = json_decode( (string) file_get_contents( self::FIXTURES . 'full-site.json' ), true );
		$b        = WpSites::path( 'b' );
		$passHash = self::value( 'b', "SELECT user_pass FROM wp_users WHERE user_login = 'admin'" );
		$before   = self::backups( 'b' );

		WpSites::ok( 'b', 'lw-migration', 'import', self::FIXTURES . 'full-site.wpress', '--yes', '--user=admin', '--no-safety-backup' );

		$this->assertSame( 'http://site-b.test', self::home( 'b' ) );
		$this->assertSame( $facts['post'], self::value( 'b', "SELECT post_title FROM wp_posts WHERE post_title = '" . $facts['post'] . "' AND post_type = 'post' LIMIT 1" ) );
		$content = self::value( 'b', "SELECT post_content FROM wp_posts WHERE post_title = '" . $facts['post'] . "' AND post_type = 'post' LIMIT 1" );
		$this->assertStringContainsString( 'http://site-b.test/hello/', $content );
		$this->assertStringNotContainsString( 'site-a.test', $content );
		$this->assertSame( '0', self::value( 'b', "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%site-a.test%'" ), 'No option keeps the old address.' );
		$this->assertFileExists( $b . '/wp-content/' . $facts['upload'] );
		$this->assertSame( $facts['user'], trim( WpSites::ok( 'b', 'user', 'get', $facts['user'], '--field=user_login' ) ) );
		$this->assertSame( $passHash, self::value( 'b', "SELECT user_pass FROM wp_users WHERE user_login = 'admin'" ), 'The importing admin keeps this site\'s password.' );

		$this->assertSame( 0, WpSites::wp( 'b', 'plugin', 'is-active', dirname( $facts['plugin'] ) )[0] );
		$this->assertSame( 0, WpSites::wp( 'b', 'plugin', 'is-active', 'lw-migration' )[0], 'This plugin stays active.' );
		$this->assertSame( 1, WpSites::wp( 'b', 'plugin', 'is-active', 'lwm-e2e-sample' )[0], 'A plugin the archive does not activate is not active.' );
		$this->assertFileExists( $b . '/wp-content/themes/' . $facts['theme'] . '/style.css' );
		$this->assertSame( $facts['theme'], trim( WpSites::ok( 'b', 'option', 'get', 'stylesheet' ) ), 'The archive\'s theme is installed, so it becomes active.' );
		$this->assertFileExists( $b . '/wp-content/mu-plugins/' . $facts['muPlugin'], 'The staged must-use plugin is restored after the swap.' );

		$this->assertSame( $before, self::backups( 'b' ), 'No safety backup with --no-safety-backup.' );
		$this->assertNoLeftovers( 'b' );
	}

	public function test_encrypted_archive_needs_the_right_password(): void {
		[ , $path ] = self::export( 'a', '--password=e2e-pass', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		WpSites::ok( 'b', 'option', 'update', 'blogname', 'Before encrypted import' );

		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'import', $path, '--yes', '--password=wrong', '--no-safety-backup' );
		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'password is wrong', $err );
		$this->assertSame( 'Before encrypted import', trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ), 'A wrong password leaves the site untouched.' );
		$this->assertNoLeftovers( 'b' );

		WpSites::ok( 'b', 'lw-migration', 'import', $path, '--yes', '--password=e2e-pass', '--no-safety-backup', '--user=admin' );
		$this->assertSame( 'http://site-b.test', self::home( 'b' ) );
		$this->assertSame( 'LWM a', trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ), 'The encrypted database was imported.' );
		$this->assertNotSame( '', self::value( 'b', "SELECT ID FROM wp_posts WHERE post_title = 'Import E2E' AND post_type = 'post' LIMIT 1" ) );
		$this->assertNoLeftovers( 'b' );
	}

	public function test_damaged_archive_leaves_the_site_untouched(): void {
		[ , $path ] = self::export( 'a', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$dir        = TempDir::create();
		try {
			$copy = $dir . '/damaged.wpress';
			copy( $path, $copy );
			$h = fopen( $copy, 'r+b' );
			fseek( $h, (int) ( filesize( $copy ) / 2 ) );
			fwrite( $h, 'XXXX' );
			fclose( $h );
			WpSites::ok( 'b', 'option', 'update', 'blogname', 'Before damaged import' );

			[ $code ] = WpSites::wp( 'b', 'lw-migration', 'import', $copy, '--yes', '--no-safety-backup' );
		} finally {
			TempDir::remove( $dir );
		}
		$this->assertNotSame( 0, $code );
		$this->assertSame( 'Before damaged import', self::value( 'b', "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" ) );
		$this->assertNoLeftovers( 'b' );
	}

	public function test_restoring_an_old_backup_keeps_it_when_retention_would_drop_it(): void {
		[ , $path ] = self::export( 'b', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$dir        = dirname( $path );
		$restored   = BackupName::forSite( 'http://site-b.test', time() - 45 * 86400, 7 );
		$newer      = BackupName::forSite( 'http://site-b.test', time() - 35 * 86400, 8 );
		rename( $path, $dir . '/' . $restored );
		copy( $dir . '/' . $restored, $dir . '/' . $newer );
		$size  = filesize( $dir . '/' . $restored );
		$added = [];
		WpSites::ok( 'b', 'option', 'update', 'lw_migration_retention', '{"days":30}', '--format=json' );
		try {
			$before = self::backups( 'b' );
			WpSites::ok( 'b', 'lw-migration', 'import', $restored, '--yes', '--user=admin' );
			$added = array_diff( self::backups( 'b' ), $before );

			$this->assertFileExists( $dir . '/' . $restored, 'The backup being restored survives the safety backup.' );
			$this->assertSame( $size, filesize( $dir . '/' . $restored ) );
			$this->assertFileExists( $dir . '/' . $newer );
			$this->assertCount( 1, preg_grep( '/^pre-import-/', $added ), 'A safety backup was taken.' );
			$this->assertNoLeftovers( 'b' );
		} finally {
			WpSites::ok( 'b', 'option', 'delete', 'lw_migration_retention' );
			foreach ( array_merge( [ $restored, $newer ], $added ) as $name ) {
				if ( is_file( $dir . '/' . $name ) ) {
					unlink( $dir . '/' . $name );
				}
			}
		}
	}

	/** Runs PHP inside WordPress on a site (base64, so no shell quoting). */
	private static function php( string $site, string $code ): string {
		return WpSites::ok( $site, 'eval', 'eval( base64_decode( "' . base64_encode( $code ) . '" ) );' );
	}

	public function test_uninstall_drops_the_temporary_tables_of_this_sites_runs_only(): void {
		$saved = self::php( 'b', 'echo wp_json_encode( array_map( static fn( $o ) => get_option( $o, null ), [ "lw_migration_storage_dir", "lw_migration_backup_labels", "lw_migration_retention" ] ) );' );
		self::php(
			'b',
			'( new LightweightPlugins\Migration\WordPress\TmpRunRegistry() )->register( "zzzz", time() );'
			. 'global $wpdb; foreach ( [ "lwyzzzz_lwm_run", "lwtzzzz_options", "lwozzzz_posts", "lwtyyyy_other_site" ] as $t ) { $wpdb->query( "CREATE TABLE `$t` (v INT)" ); }'
		);
		try {
			self::php( 'b', 'define( "WP_UNINSTALL_PLUGIN", "lw-migration/lw-migration.php" ); include WP_PLUGIN_DIR . "/lw-migration/uninstall.php";' );

			$this->assertSame( '1', self::value( 'b', "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'lw%'" ) );
			$this->assertSame( 'lwtyyyy_other_site', self::value( 'b', "SHOW TABLES LIKE 'lw%'" ), 'This site\'s run is gone, marker included; another site\'s table stays.' );
			$this->assertSame( '', trim( self::php( 'b', 'echo (string) get_option( "lw_migration_tmp_runs", "" );' ) ) );
		} finally {
			self::php( 'b', 'global $wpdb; foreach ( [ "lwyzzzz_lwm_run", "lwtzzzz_options", "lwozzzz_posts", "lwtyyyy_other_site" ] as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); } delete_option( "lw_migration_tmp_runs" );' );
			self::php( 'b', 'foreach ( array_combine( [ "lw_migration_storage_dir", "lw_migration_backup_labels", "lw_migration_retention" ], json_decode( base64_decode( "' . base64_encode( $saved ) . '" ), true ) ) as $o => $v ) { if ( null !== $v ) { update_option( $o, $v, false ); } }' );
		}
		$this->assertNoLeftovers( 'b' );
	}

	public function test_uninstall_keeps_the_run_registry_when_a_table_cannot_be_dropped(): void {
		$saved = self::php( 'b', 'echo wp_json_encode( array_map( static fn( $o ) => get_option( $o, null ), [ "lw_migration_storage_dir", "lw_migration_backup_labels", "lw_migration_retention" ] ) );' );
		self::php(
			'b',
			'( new LightweightPlugins\Migration\WordPress\TmpRunRegistry() )->register( "zzzz", time() );'
			. 'global $wpdb; $wpdb->query( "CREATE TABLE lwtzzzz_parent (id INT PRIMARY KEY) ENGINE=InnoDB" ); $wpdb->query( "CREATE TABLE wp_lwm_e2e_child (pid INT, FOREIGN KEY (pid) REFERENCES lwtzzzz_parent (id)) ENGINE=InnoDB" );'
		);
		try {
			self::php( 'b', 'define( "WP_UNINSTALL_PLUGIN", "lw-migration/lw-migration.php" ); include WP_PLUGIN_DIR . "/lw-migration/uninstall.php";' );

			$this->assertSame( 'lwtzzzz_parent', self::value( 'b', "SHOW TABLES LIKE 'lwtzzzz%'" ) );
			$this->assertStringContainsString( 'zzzz', self::php( 'b', 'echo (string) get_option( "lw_migration_tmp_runs", "" );' ), 'The registry row stays for the run that is left.' );
		} finally {
			self::php( 'b', 'global $wpdb; foreach ( [ "wp_lwm_e2e_child", "lwtzzzz_parent", "lwyzzzz_lwm_run" ] as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); } delete_option( "lw_migration_tmp_runs" );' );
			self::php( 'b', 'foreach ( array_combine( [ "lw_migration_storage_dir", "lw_migration_backup_labels", "lw_migration_retention" ], json_decode( base64_decode( "' . base64_encode( $saved ) . '" ), true ) ) as $o => $v ) { if ( null !== $v ) { update_option( $o, $v, false ); } }' );
		}
		$this->assertNoLeftovers( 'b' );
	}

	public function test_url_import_downloads_in_ranges(): void {
		[ , $path ] = self::export( 'a', '--exclude-media', '--exclude-themes' );
		$mu         = WpSites::path( 'b' ) . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) ) {
			mkdir( $mu, 0755, true );
		}
		file_put_contents(
			$mu . '/lwm-e2e-allow-local.php',
			"<?php\n// E2E only: lets the URL import reach the local test server.\n"
			. "add_filter( 'http_request_host_is_external', static fn( \$external, \$host ) => '127.0.0.1' === \$host ? true : \$external, 10, 2 );\n"
			. "add_filter( 'http_allowed_safe_ports', static fn( \$ports ) => array_merge( (array) \$ports, [ 8089 ] ) );\n"
		);
		WpSites::ok( 'b', 'plugin', 'deactivate', 'lwm-e2e-sample' );
		$server = proc_open(
			[ (string) getenv( 'LWM_E2E_PHP' ), '-S', '127.0.0.1:8089', __DIR__ . '/range-server.php' ],
			[ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ],
			$pipes,
			null,
			[ 'LWM_RANGE_FILE' => $path ] + getenv()
		);
		$this->assertIsResource( $server );
		try {
			for ( $i = 0; $i < 50 && false === @fsockopen( '127.0.0.1', 8089 ); $i++ ) { // Wait until the server listens.
				usleep( 100000 );
			}
			WpSites::ok( 'b', 'lw-migration', 'import', 'http://127.0.0.1:8089/site.wpress', '--yes', '--user=admin', '--no-safety-backup' );
		} finally {
			proc_terminate( $server );
			proc_close( $server );
			if ( is_file( $mu . '/lwm-e2e-allow-local.php' ) ) {
				unlink( $mu . '/lwm-e2e-allow-local.php' );
			}
		}
		$this->assertSame( 'http://site-b.test', self::home( 'b' ) );
		$this->assertSame( 0, WpSites::wp( 'b', 'plugin', 'is-active', 'lwm-e2e-sample' )[0], 'The downloaded archive was imported.' );
		$this->assertNoLeftovers( 'b' );
	}
}
