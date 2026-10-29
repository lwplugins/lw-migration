<?php
/**
 * Export scenarios on a real WordPress site.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Package\PackageConfig;
use LightweightPlugins\Migration\Tests\Support\TempDir;

final class ExportE2ETest extends E2ETestCase {

	public static function setUpBeforeClass(): void {
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$a = WpSites::path( 'a' );
		WpSites::ok( 'a', 'post', 'create', '--post_title=Hello E2E', '--post_status=publish', '--post_content=<a href="http://site-a.test/sample-page/">link</a>' );
		if ( ! is_dir( $a . '/wp-content/uploads/2026/10' ) ) {
			mkdir( $a . '/wp-content/uploads/2026/10', 0755, true );
		}
		file_put_contents( $a . '/wp-content/uploads/2026/10/e2e.txt', "e2e upload\n" );
		if ( ! is_dir( $a . '/wp-content/plugins/lwm-e2e-sample' ) ) {
			mkdir( $a . '/wp-content/plugins/lwm-e2e-sample', 0755, true );
		}
		file_put_contents( $a . '/wp-content/plugins/lwm-e2e-sample/lwm-e2e-sample.php', "<?php\n/**\n * Plugin Name: LWM E2E Sample\n */\n" );
		WpSites::ok( 'a', 'plugin', 'activate', 'lwm-e2e-sample' );
	}

	public function test_plain_export_contains_site_files_and_database(): void {
		[ $name, $path ] = self::export( 'a' );
		$this->assertNull( WpSites::lock( 'a' ), 'A finished export releases the site lock.' );
		$reader = new Reader( $path );
		$reader->validate();
		$names = self::names( $reader );

		$this->assertSame( 'package.json', $names[0] );
		$this->assertSame( 'database.sql', end( $names ) );
		$this->assertContains( 'uploads/2026/10/e2e.txt', $names );
		$this->assertContains( 'plugins/lwm-e2e-sample/lwm-e2e-sample.php', $names );
		$this->assertSame( [], preg_grep( '#^plugins/lw-migration/#', $names ) );
		$this->assertSame( [], preg_grep( '#^lw-migration-[a-f0-9]{12}/#', $names ) );

		$first = $reader->firstEntry();
		$this->assertNotNull( $first );
		$package = PackageConfig::fromJson( $reader->readContents( $first ) );
		$this->assertSame( 'http://site-a.test', $package->get( 'SiteURL' ) );
		$this->assertSame( WpSites::pluginVersion(), $package->get( 'Plugin.Version' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', (string) $package->get( 'Plugin.Version' ) );
		$this->assertContains( 'lwm-e2e-sample/lwm-e2e-sample.php', (array) $package->get( 'Plugins' ) );
		$this->assertNotContains( 'lw-migration/lw-migration.php', (array) $package->get( 'Plugins' ) );

		$sql = $reader->readContents( self::entry( $reader, 'database.sql' ), 64 * 1048576 );
		$reader->close();
		$this->assertStringContainsString( 'Hello E2E', $sql );
		$this->assertStringContainsString( 'CREATE TABLE `SERVMASK_PREFIX_posts`', $sql );
		$this->assertStringNotContainsString( "'lw_migration_", $sql );

		$this->assertContains( $name, self::backups( 'a' ) );
		$this->assertStringContainsString( 'package.json', WpSites::ok( 'a', 'lw-migration', 'browse', $name ) );
	}

	public function test_encrypted_gzip_export_extracts_with_its_password(): void {
		[ , $path ] = self::export( 'a', '--compression=gzip', '--password=e2e-secret', '--exclude-media' );
		$package    = PackageCodec::read( $path );
		$this->assertSame( 'gzip', $package->compression() );
		$this->assertTrue( $package->isEncrypted() );
		$this->assertNotSame( '', $package->encryptedSignature() );

		$reader = new Reader( $path, PackageCodec::forPackage( $package, 'e2e-secret' ) );
		$this->assertSame( [], preg_grep( '#^uploads/#', self::names( $reader ) ) );
		$this->assertStringContainsString( 'Hello E2E', $reader->readContents( self::entry( $reader, 'database.sql' ), 64 * 1048576 ) );
		$reader->close();

		$dir = TempDir::create();
		try {
			$out = WpSites::ok( 'a', 'lw-migration', 'extract', $path, '--dir=' . $dir . '/out', '--password=e2e-secret' );
			$this->assertStringContainsString( 'Extracted', $out );
			$this->assertFileExists( $dir . '/out/database.sql' );
			$this->assertStringContainsString( 'Hello E2E', (string) file_get_contents( $dir . '/out/database.sql' ) );
		} finally {
			TempDir::remove( $dir );
		}

		$files = array_merge( (array) glob( WpSites::storage( 'a' ) . '/jobs/*/job.*' ), (array) glob( WpSites::storage( 'a' ) . '/jobs/*/*.log' ) );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertStringNotContainsString( 'e2e-secret', (string) file_get_contents( (string) $file ), (string) $file );
		}
	}

	public function test_delete_removes_the_backup(): void {
		[ $name ] = self::export( 'a', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$this->assertContains( $name, self::backups( 'a' ) );
		WpSites::ok( 'a', 'lw-migration', 'delete', $name, '--yes' );
		$this->assertNotContains( $name, self::backups( 'a' ) );
	}

	public function test_a_second_job_is_refused_while_another_holds_the_site(): void {
		// The lock is a JSON string (SiteLock::encode), stored as is.
		$held = '{"job":"ffffffffffffffff","expires":9999999999}';
		WpSites::ok( 'a', 'option', 'update', 'lw_migration_lock', $held );
		try {
			$before           = self::backups( 'a' );
			[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'export', '--exclude-media' );
			$after            = WpSites::lock( 'a' );
			$this->assertSame( $before, self::backups( 'a' ) );
		} finally {
			WpSites::ok( 'a', 'option', 'delete', 'lw_migration_lock' );
		}

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Another backup or restore is running', $err );
		$this->assertSame( $held, $after, 'A refused job leaves the holder\'s lock alone.' );
	}

	public function test_a_failed_export_exits_non_zero_and_releases_the_lock(): void {
		$backups = WpSites::storage( 'a' ) . '/backups';
		$before  = self::backups( 'a' );
		chmod( $backups, 0555 );
		try {
			[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'export', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		} finally {
			chmod( $backups, 0755 );
		}

		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'Error:', $err );
		$this->assertStringNotContainsString( 'Backup file:', $out );
		$this->assertStringNotContainsString( 'Warning: ', $err, 'No raw PHP warning reaches the user.' );
		$this->assertNull( WpSites::lock( 'a' ), 'A failed export releases the site lock.' );
		$this->assertSame( $before, self::backups( 'a' ) );
		$this->assertSame( [], (array) glob( $backups . '/.lwm-*' ) );

		// The site is free again: the next export runs.
		[ $name ] = self::export( 'a', '--exclude-media', '--exclude-plugins', '--exclude-themes' );
		$this->assertContains( $name, self::backups( 'a' ) );
		$this->assertNull( WpSites::lock( 'a' ) );
	}

	public function test_wpdb_and_sql_mode_survive_an_in_process_export(): void {
		$dir    = TempDir::create();
		$script = $dir . '/probe.php';
		file_put_contents(
			$script,
			<<<'PHP'
<?php
global $wpdb;
$wpdb->query( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'" );
$before = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
$run    = WP_CLI::runcommand( 'lw-migration export --exclude-media --exclude-plugins --exclude-themes', [ 'launch' => false, 'return' => 'all', 'exit_error' => false ] );
$after  = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
$title  = $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE post_title = %s LIMIT 1", 'Hello E2E' ) );
echo wp_json_encode( [ 'code' => $run->return_code, 'stdout' => $run->stdout, 'before' => $before, 'after' => $after, 'title' => $title, 'error' => $wpdb->last_error ] );
PHP
		);
		try {
			$result = json_decode( WpSites::ok( 'a', 'eval-file', $script ), true );
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'Backup file:', $result['stdout'] );
		$this->assertStringContainsString( 'STRICT_TRANS_TABLES', $result['before'] );
		$this->assertSame( $result['before'], $result['after'], 'The dump restores the session sql_mode of $wpdb.' );
		$this->assertSame( 'Hello E2E', $result['title'], '$wpdb still answers after the dump.' );
		$this->assertSame( '', $result['error'] );
	}

	private static function entry( Reader $reader, string $name ): \LightweightPlugins\Migration\Archive\Entry {
		$entry = $reader->find( $name );
		if ( null === $entry ) {
			throw new \RuntimeException( 'No ' . $name . ' in the archive.' );
		}

		return $entry;
	}
}
