<?php
/**
 * End to end: every reset of a throwaway site, from the command line and the REST API.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Site r is built by bin/test-wp.sh for these tests only. The tests run in
 * file order, each seeds what it needs and leaves the site usable; job
 * folders of earlier tests stay, so a test counts the jobs it adds.
 */
final class ResetE2ETest extends E2ETestCase {

	private const TMP_TABLE = '/^lw[toxy][a-z0-9]{4}_/';

	protected function setUp(): void {
		parent::setUp();
		if ( false === getenv( 'LWM_E2E_R' ) || '' === getenv( 'LWM_E2E_R' ) ) {
			$this->markTestSkipped( 'Run bin/test-wp.sh to build site r.' );
		}
	}

	public function test_plugins_go_but_this_plugin_mu_plugins_drop_ins_and_link_targets_stay(): void {
		$r = WpSites::path( 'r' );
		self::plugin( $r, 'lwm-dummy', "<?php\n/* Plugin Name: LWM dummy */", "<?php\nupdate_option( 'lwm_dummy_uninstalled', 'yes' );" );
		@mkdir( $r . '/../r-shared', 0755 );
		file_put_contents( $r . '/../r-shared/keep.php', '<?php // shared' );
		@symlink( (string) realpath( $r . '/../r-shared' ), $r . '/wp-content/plugins/r-linked' );
		@mkdir( $r . '/wp-content/mu-plugins', 0755 );
		file_put_contents( $r . '/wp-content/mu-plugins/keep-mu.php', '<?php' );
		file_put_contents( $r . '/wp-content/object-cache.php', '<?php' ); // A drop-in WordPress never loads in this test (no cache class): harmless.
		WpSites::ok( 'r', 'plugin', 'activate', 'lwm-dummy' );
		$this->assertContains( 'akismet/akismet.php', self::plugins( 'r' ), 'The core download brought a plugin to delete.' );
		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'plugins', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( [ 'lw-migration/lw-migration.php' ], self::plugins( 'r' ) );
		$this->assertSame( 'yes', trim( WpSites::ok( 'r', 'option', 'get', 'lwm_dummy_uninstalled' ) ) );
		$this->assertFileExists( $r . '/../r-shared/keep.php' );
		$this->assertFileExists( $r . '/wp-content/mu-plugins/keep-mu.php' );
		$this->assertFileExists( $r . '/wp-content/object-cache.php' );
		$this->assertStringContainsString( 'object-cache.php', $out, 'The drop-in notice.' );
		$this->assertStringContainsString( 'Reset finished.', $out );
		$this->assertStringNotContainsString( 'admin-pass', $out . $err );
		unlink( $r . '/wp-content/object-cache.php' );
	}

	public function test_a_plugin_whose_uninstall_ends_the_process_is_finished_by_running_the_command_again(): void {
		$r = WpSites::path( 'r' );
		self::plugin( $r, 'lwm-exit', "<?php\n/* Plugin Name: LWM exit */", "<?php\nupdate_option( 'lwm_exit_runs', (int) get_option( 'lwm_exit_runs', 0 ) + 1 );\nexit( 7 );" );
		$before = self::jobs( 'r' );
		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'plugins', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 7, $code, 'The uninstall routine ended the process: ' . $out . $err );
		$this->assertDirectoryExists( $r . '/wp-content/plugins/lwm-exit' );
		$this->assertNotNull( WpSites::lock( 'r' ), 'The reset still holds the site.' );

		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'plugins', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'It continues now.', $out );
		$this->assertDirectoryDoesNotExist( $r . '/wp-content/plugins/lwm-exit' );
		$this->assertSame( '1', trim( WpSites::ok( 'r', 'option', 'get', 'lwm_exit_runs' ) ), 'The routine ran once, never again.' );
		$this->assertCount( count( $before ) + 1, self::jobs( 'r' ), 'The same job was continued.' );
		$this->assertNull( WpSites::lock( 'r' ) );
		WpSites::ok( 'r', 'option', 'delete', 'lwm_exit_runs' );
	}

	public function test_a_wrong_password_is_refused_and_nothing_changes(): void {
		$before          = self::jobs( 'r' );
		[ $code, , $err ] = WpSites::wpInput( 'r', "nope\n", 'lw-migration', 'reset', 'themes', '--yes', '--user=admin' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'This is not your password.', $err );
		$this->assertNull( WpSites::lock( 'r' ) );
		$this->assertSame( $before, self::jobs( 'r' ) );
		[ $code, , $err ] = WpSites::wpInput( 'r', "\n", 'lw-migration', 'reset', 'themes', '--yes', '--user=admin' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Give the password of admin on a terminal, or as one line on standard input.', $err );
		WpSites::ok( 'r', 'option', 'delete', 'lw_migration_reset_tries' ); // The one failure must not count against the next tests.
	}

	public function test_themes_reset_to_the_default_and_the_backup_is_labelled(): void {
		WpSites::ok( 'r', 'theme', 'activate', 'twentytwentythree' );
		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'themes', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'The theme Twenty Twenty-Five will be active;', $out );
		$this->assertSame( 'twentytwentyfive', trim( WpSites::ok( 'r', 'option', 'get', 'stylesheet' ) ) );
		$this->assertSame( [ 'twentytwentyfive' ], json_decode( WpSites::ok( 'r', 'theme', 'list', '--field=name', '--format=json' ), true ) );
		$backups = self::backups( 'r' );
		$this->assertCount( 1, $backups );
		$this->assertMatchesRegularExpression( '/^pre-reset-\d{8}-\d{6}\.wpress\z/', $backups[0] );
		$this->assertStringContainsString( 'Backup before the reset: ' . $backups[0], $out );
		$this->assertSame( 'Before reset — themes', json_decode( WpSites::ok( 'r', 'option', 'get', 'lw_migration_backup_labels', '--format=json' ), true )[ $backups[0] ] );
	}

	public function test_without_any_default_theme_and_no_network_the_blank_theme_is_written(): void {
		$r = WpSites::path( 'r' );
		copy( __DIR__ . '/reset-probe.php', $r . '/wp-content/mu-plugins/reset-probe.php' );
		touch( $r . '/wp-content/lwm-e2e-offline' ); // The probe refuses every *.wordpress.org request.
		@mkdir( $r . '/wp-content/themes/plain', 0755 );
		file_put_contents( $r . '/wp-content/themes/plain/style.css', "/*\nTheme Name: Plain\n*/" );
		file_put_contents( $r . '/wp-content/themes/plain/index.php', '<?php echo "plain";' );
		WpSites::ok( 'r', 'theme', 'activate', 'plain' );
		@mkdir( $r . '/../r-themes-aside', 0755 );
		rename( $r . '/wp-content/themes/twentytwentyfive', $r . '/../r-themes-aside/twentytwentyfive' );
		try {
			[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'themes', '--no-backup', '--yes', '--user=admin' );
			$this->assertSame( 0, $code, $out . $err );
			$this->assertSame( 'lw-migration-blank', trim( WpSites::ok( 'r', 'option', 'get', 'stylesheet' ) ) );
			$this->assertStringContainsString( 'Theme Name: Blank (LW Migration)', (string) file_get_contents( $r . '/wp-content/themes/lw-migration-blank/style.css' ) );
			$this->assertDirectoryDoesNotExist( $r . '/wp-content/themes/plain' );
			$rest = new RestClient( 'r' );
			$rest->start();
			try {
				$front = $rest->call( 'GET', '', null, [], null, [], '' );
				$this->assertSame( 200, $front['status'] ); // The namespace index answers; the theme loaded without a fatal error.
			} finally {
				$rest->stop();
			}
		} finally {
			unlink( $r . '/wp-content/lwm-e2e-offline' );
			unlink( $r . '/wp-content/mu-plugins/reset-probe.php' );
			rename( $r . '/../r-themes-aside/twentytwentyfive', $r . '/wp-content/themes/twentytwentyfive' );
			WpSites::ok( 'r', 'theme', 'activate', 'twentytwentyfive' );
			WpSites::ok( 'r', 'theme', 'delete', 'lw-migration-blank' );
		}
	}

	public function test_media_goes_but_protection_files_and_backups_stay(): void {
		$r  = WpSites::path( 'r' );
		$id = (int) trim( WpSites::ok( 'r', 'media', 'import', __DIR__ . '/../Fixtures/reset/pixel.png', '--porcelain' ) );
		file_put_contents( $r . '/wp-content/uploads/.htaccess', "deny php\n" );
		@symlink( (string) realpath( $r . '/../r-shared/keep.php' ), $r . '/wp-content/uploads/linked.php' );
		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'media', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( '0', trim( WpSites::ok( 'r', 'eval', 'echo (int) wp_count_posts( "attachment" )->inherit;' ) ) );
		$this->assertSame( [ '.', '..', '.htaccess' ], scandir( $r . '/wp-content/uploads' ) );
		$this->assertFileExists( $r . '/../r-shared/keep.php' );
		$this->assertNotSame( 0, $id );
		$this->assertCount( 1, self::backups( 'r' ), 'The earlier pre-reset backup is still there.' );
	}

	public function test_a_database_reset_keeps_the_admin_logged_in(): void {
		$rest = new RestClient( 'r' );
		$rest->start();
		try {
			WpSites::ok( 'r', 'user', 'create', 'eve', 'eve@site-r.test', '--role=editor' );
			WpSites::ok( 'r', 'post', 'create', '--post_title=Marker', '--post_status=publish' );
			self::exec( 'r', 'CREATE TABLE wp_lwmdummy_data (id INT)' );
			self::exec( 'r', 'CREATE TABLE wp_other_options (id INT)', 'CREATE TABLE wp_other_posts (id INT)', 'CREATE TABLE wp_other_extra (id INT)' );
			WpSites::ok( 'r', 'option', 'update', 'lw_migration_retention', '{"maxCount":3}', '--format=json' );
			$auth    = $rest->login( 'admin' );
			$before  = (int) trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=ID' ) );
			$pass    = trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=user_pass' ) );
			$summary = $rest->call( 'GET', '/reset', $auth )['json'];
			$this->assertSame( 'site-r.test', $summary['phrase'] );
			$this->assertContains( 'wp_lwmdummy_data', $summary['types']['database']['tables']['retiredNames'] );
			$this->assertSame( 3, $summary['types']['database']['tables']['kept'], 'Another installation\'s tables are counted as kept.' );
			$wrong = $rest->call( 'POST', '/reset', $auth, [], [ 'type' => 'database', 'password' => 'admin-pass', 'confirm' => 'site-r.test/x', 'backup' => false ] );
			$this->assertSame( [ 400, 'confirm' ], [ $wrong['status'], $wrong['json']['data']['field'] ] );
			$start = $rest->call( 'POST', '/reset', $auth, [], [ 'type' => 'database', 'password' => 'admin-pass', 'confirm' => $summary['phrase'], 'backup' => false ] );
			$this->assertSame( 201, $start['status'], $start['body'] );
			$this->assertStringNotContainsString( 'admin-pass', $start['body'] );
			$job = ( new CheckedRest( $rest, [ 'admin-pass' ] ) )->drive( $auth, (string) $start['json']['id'] );
			$this->assertSame( 'done', $job['status'], (string) json_encode( $job ) );
			$this->assertSame( 200, $rest->call( 'GET', '/jobs/active', $auth )['status'], 'Same cookie and nonce after the reset: still logged in.' );
			$this->assertSame( (string) $before, trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=ID' ) ) );
			$this->assertSame( $pass, trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=user_pass' ) ) );
			$this->assertSame( 'administrator', trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=roles' ) ) );
			$this->assertSame( 1, (int) trim( WpSites::ok( 'r', 'user', 'list', '--format=count' ) ), 'Every other user went.' );
			$this->assertSame( '', trim( WpSites::ok( 'r', 'post', 'list', '--s=Marker', '--field=ID' ) ) );
			$this->assertSame( 'Hello world!', trim( WpSites::ok( 'r', 'post', 'list', '--post_type=post', '--field=post_title' ) ), 'The fresh install\'s first post.' );
			$this->assertSame( [ 'lw-migration/lw-migration.php' ], json_decode( WpSites::ok( 'r', 'plugin', 'list', '--status=active', '--field=file', '--format=json' ), true ) );
			$this->assertSame( '{"maxCount":3}', trim( WpSites::ok( 'r', 'option', 'get', 'lw_migration_retention', '--format=json' ) ) );
			$tables = self::tables( 'r' );
			$this->assertNotContains( 'wp_lwmdummy_data', $tables );
			$this->assertContains( 'wp_other_options', $tables, 'Another installation\'s tables stay.' );
			$this->assertContains( 'wp_other_extra', $tables );
			$this->assertSame( [], self::temporary( $tables ), 'No temporary table left.' );
			$this->assertNotSame( '', trim( WpSites::ok( 'r', 'cron', 'event', 'list', '--hook=lw_migration_cleanup', '--field=hook' ) ), 'Our daily cleanup is planned again.' );
		} finally {
			$rest->stop();
		}
	}

	public function test_a_database_reset_killed_after_the_rename_finishes_on_the_next_run(): void {
		$r = WpSites::path( 'r' );
		WpSites::ok( 'r', 'user', 'create', 'eve', 'eve@site-r.test', '--role=editor' );
		copy( __DIR__ . '/reset-probe.php', $r . '/wp-content/mu-plugins/reset-probe.php' );
		touch( $r . '/wp-content/lwm-e2e-die-after-rename' );
		$before = self::jobs( 'r' );
		try {
			[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'database', '--no-backup', '--yes', '--user=admin' );
			$this->assertSame( 3, $code, 'The probe killed the process right after the RENAME: ' . $out . $err );
			$ids = array_values( array_diff( self::jobs( 'r' ), $before ) );
			$this->assertCount( 1, $ids );
			$dir = WpSites::storage( 'r' ) . '/jobs/' . $ids[0];
			$this->assertFileExists( $dir . '/swap-started' );
			$this->assertSame( 1, (int) trim( WpSites::ok( 'r', 'user', 'list', '--format=count' ) ), 'The fresh tables are live already.' );
			[ $busy, , $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'media', '--no-backup', '--yes', '--user=admin' );
			$this->assertSame( 1, $busy );
			$this->assertStringContainsString( 'Another job is running on this site, so nothing was reset and no password was asked for.', $err );
			WpSites::ok( 'r', 'eval', 'update_option( "lw_migration_lock", wp_json_encode( [ "job" => "' . $ids[0] . '", "expires" => 1 ] ), false );' );
			$rest = new RestClient( 'r' );
			$rest->start();
			try {
				$auth   = $rest->login( 'admin' );
				$active = $rest->call( 'GET', '/jobs/active', $auth );
				$this->assertSame( $ids[0], $active['json']['job']['id'], 'Offered again.' );
				$this->assertTrue( $active['json']['job']['pastNoReturn'] );
				$job = ( new CheckedRest( $rest, [ 'admin-pass' ] ) )->drive( $auth, $ids[0] );
				$this->assertSame( 'done', $job['status'], (string) json_encode( $job ) );
			} finally {
				$rest->stop();
			}
			$this->assertSame( [], self::temporary( self::tables( 'r' ) ) );
			$this->assertSame( 'administrator', trim( WpSites::ok( 'r', 'user', 'get', 'admin', '--field=roles' ) ) );
			$this->assertNull( WpSites::lock( 'r' ) );
		} finally {
			@unlink( $r . '/wp-content/lwm-e2e-die-after-rename' );
			unlink( $r . '/wp-content/mu-plugins/reset-probe.php' );
		}
	}

	public function test_links_and_protected_folders_survive_a_full_reset(): void {
		$r = WpSites::path( 'r' );
		self::plugin( $r, 'lwm-dummy', "<?php\n/* Plugin Name: LWM dummy */", '' );
		@symlink( (string) realpath( $r . '/../r-shared' ), $r . '/wp-content/plugins/r-linked' );
		@mkdir( $r . '/wp-content/themes/plain2', 0755 ); // Ruling I6: the themes test left only twentytwentyfive.
		file_put_contents( $r . '/wp-content/themes/plain2/style.css', "/*\nTheme Name: Plain 2\n*/" );
		file_put_contents( $r . '/wp-content/themes/plain2/index.php', '<?php echo "plain";' );
		WpSites::ok( 'r', 'theme', 'activate', 'plain2' );
		WpSites::ok( 'r', 'media', 'import', __DIR__ . '/../Fixtures/reset/pixel.png' );
		$before  = self::backups( 'r' );
		$summary = trim( WpSites::ok( 'r', 'eval', 'echo wp_json_encode( \LightweightPlugins\Migration\Reset\ResetSummary::of( \LightweightPlugins\Migration\Services::get(), \LightweightPlugins\Migration\WordPress\SiteReader::read(), get_user_by( "login", "admin" ) ) );' ) );
		$this->assertTrue( json_decode( $summary, true )['types']['full']['available'] );
		[ $code, $out, $err ] = WpSites::wpInput( 'r', "admin-pass\n", 'lw-migration', 'reset', 'full', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( [ 'lw-migration/lw-migration.php' ], self::plugins( 'r' ) );
		$this->assertTrue( is_link( $r . '/wp-content/plugins/r-linked' ), 'A linked folder in the plugins folder is left.' );
		$this->assertFileExists( $r . '/../r-shared/keep.php' );
		$this->assertSame( 'twentytwentyfive', trim( WpSites::ok( 'r', 'option', 'get', 'stylesheet' ) ) );
		$this->assertSame( [ '.', '..', '.htaccess' ], scandir( $r . '/wp-content/uploads' ) );
		$after = self::backups( 'r' );
		$this->assertCount( count( $before ) + 1, $after );
		$new    = array_values( array_diff( $after, $before ) )[0];
		$labels = json_decode( WpSites::ok( 'r', 'option', 'get', 'lw_migration_backup_labels', '--format=json' ), true );
		$this->assertSame( 'Before reset — everything', $labels[ $new ] );
		$this->assertDirectoryExists( WpSites::storage( 'r' ) . '/jobs' );
		$this->assertSame( 1, (int) trim( WpSites::ok( 'r', 'user', 'list', '--format=count' ) ) );
		unlink( $r . '/wp-content/plugins/r-linked' );
	}

	/**
	 * Writes wp-content/plugins/<slug>/<slug>.php and, when given, its uninstall.php.
	 */
	private static function plugin( string $root, string $slug, string $main, string $uninstall ): void {
		$dir = $root . '/wp-content/plugins/' . $slug;
		@mkdir( $dir, 0755, true );
		file_put_contents( $dir . '/' . $slug . '.php', $main );
		if ( '' !== $uninstall ) {
			file_put_contents( $dir . '/uninstall.php', $uninstall );
		}
	}

	/**
	 * @return array<int, string> Plugin files of the plugins list (no must-use plugins, no drop-ins).
	 */
	private static function plugins( string $site ): array {
		$rows = (array) json_decode( WpSites::ok( $site, 'plugin', 'list', '--fields=file,status', '--format=json' ), true );

		return array_values( array_column( array_filter( $rows, static fn( array $row ): bool => ! in_array( $row['status'], [ 'must-use', 'dropin' ], true ) ), 'file' ) );
	}

	/**
	 * @return array<int, string> Job ids in the site's storage, sorted.
	 */
	private static function jobs( string $site ): array {
		$ids = array_values( array_filter( (array) scandir( WpSites::storage( $site ) . '/jobs' ), static fn( $name ): bool => 1 === preg_match( '/^[a-f0-9]{16}\z/', (string) $name ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * @return array<int, string> Every table of the site's database.
	 */
	private static function tables( string $site ): array {
		return (array) json_decode( WpSites::ok( $site, 'eval', 'echo wp_json_encode( $GLOBALS["wpdb"]->get_col( "SHOW TABLES" ) );' ), true );
	}

	/**
	 * @param array<int, string> $tables Table names.
	 * @return array<int, string> Our temporary tables among them.
	 */
	private static function temporary( array $tables ): array {
		return array_values( array_filter( $tables, static fn( string $t ): bool => 1 === preg_match( self::TMP_TABLE, $t ) ) );
	}

	private static function exec( string $site, string ...$sql ): void {
		foreach ( $sql as $statement ) {
			WpSites::ok( $site, 'eval', '$GLOBALS["wpdb"]->query( base64_decode( "' . base64_encode( $statement ) . '" ) ) !== false || exit( 1 );' );
		}
	}
}
