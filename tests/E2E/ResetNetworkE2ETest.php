<?php
/**
 * End to end: the resets a network allows, on a throwaway network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Network m (subdirectory, sites 1, b2 and b3) is built by
 * LWM_E2E_NETWORK=1 bin/test-wp.sh for these tests only. Plugins, themes and
 * media are reset on every site from the main site; the database and a full
 * reset are refused. The tests run in file order: the refusals first, while
 * the network is untouched.
 */
final class ResetNetworkE2ETest extends E2ETestCase {

	private const URL = 'http://net-m.test';

	protected function setUp(): void {
		parent::setUp();
		if ( false === getenv( 'LWM_E2E_M' ) || '' === getenv( 'LWM_E2E_M' ) ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to build network m.' );
		}
	}

	public function test_the_database_and_a_full_reset_are_refused_and_nothing_changes(): void {
		$posts = trim( WpSites::ok( 'm', 'post', 'list', '--format=count' ) );
		foreach ( [ 'database', 'full' ] as $type ) {
			[ $code, $out, $err ] = WpSites::wpInput( 'm', "admin-pass\n", 'lw-migration', 'reset', $type, '--no-backup', '--yes', '--user=admin' );
			$this->assertSame( 1, $code, $out . $err );
			$this->assertStringContainsString( 'On a network only plugins, themes and media can be reset in this version.', $err );
		}
		$this->assertSame( $posts, trim( WpSites::ok( 'm', 'post', 'list', '--format=count' ) ) );
		$this->assertNull( WpSites::lock( 'm' ) );
		$this->assertSame( 'none', trim( WpSites::ok( 'm', 'eval', 'echo false === get_option( "lw_migration_reset_tries" ) ? "none" : "set";' ) ), 'Refused before the password: nothing counted.' );
	}

	public function test_a_subsite_address_is_refused(): void {
		[ $code, $out, $err ] = WpSites::wpUrl( 'm', self::URL . '/b2/', 'lw-migration', 'reset', 'plugins', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 1, $code, $out . $err );
		$this->assertStringContainsString( 'Run this command on the network\'s main site', $err );
	}

	public function test_plugins_go_from_every_site(): void {
		WpSites::ok( 'm', 'plugin', 'activate', 'hello', '--network' );
		self::onSite( '/b2/', 'plugin', 'activate', 'akismet' );
		$this->assertContains( 'akismet/akismet.php', self::activeOn( '/b2/' ) );
		[ $code, $out, $err ] = WpSites::wpInput( 'm', "admin-pass\n", 'lw-migration', 'reset', 'plugins', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertSame( [ 'lw-migration/lw-migration.php' ], array_values( array_column( array_filter( (array) json_decode( WpSites::ok( 'm', 'plugin', 'list', '--fields=file,status', '--format=json' ), true ), static fn( array $row ): bool => ! in_array( $row['status'], [ 'must-use', 'dropin' ], true ) ), 'file' ) ) );
		$this->assertDirectoryDoesNotExist( WpSites::path( 'm' ) . '/wp-content/plugins/akismet' );
		foreach ( [ '/', '/b2/', '/b3/' ] as $path ) {
			$this->assertNotContains( 'akismet/akismet.php', self::activeOn( $path ), $path );
		}
		$sitewide = (array) json_decode( WpSites::ok( 'm', 'site', 'option', 'get', 'active_sitewide_plugins', '--format=json' ), true );
		$this->assertSame( [ 'lw-migration/lw-migration.php' ], array_keys( $sitewide ), 'Still network-active: LW Migration only.' );
	}

	public function test_every_site_gets_the_default_theme(): void {
		self::onSite( '/b3/', 'theme', 'enable', 'twentytwentythree', '--network' );
		self::onSite( '/b3/', 'theme', 'activate', 'twentytwentythree' );
		[ $code, $out, $err ] = WpSites::wpInput( 'm', "admin-pass\n", 'lw-migration', 'reset', 'themes', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		foreach ( [ '/', '/b2/', '/b3/' ] as $path ) {
			$this->assertSame( 'twentytwentyfive', trim( self::onSite( $path, 'option', 'get', 'stylesheet' ) ), $path );
		}
		$this->assertSame( [ 'twentytwentyfive' ], json_decode( WpSites::ok( 'm', 'theme', 'list', '--field=name', '--format=json' ), true ) );
		$allowed = (array) json_decode( WpSites::ok( 'm', 'site', 'option', 'get', 'allowedthemes', '--format=json' ), true );
		$this->assertArrayHasKey( 'twentytwentyfive', $allowed, 'Network-enabled.' );
		$this->assertArrayNotHasKey( 'twentytwentythree', $allowed, 'A deleted theme is network-disabled.' );
	}

	public function test_media_goes_from_every_site(): void {
		$m = WpSites::path( 'm' );
		WpSites::ok( 'm', 'media', 'import', __DIR__ . '/../Fixtures/reset/pixel.png' );
		self::onSite( '/b2/', 'media', 'import', __DIR__ . '/../Fixtures/reset/pixel.png' );
		$b2 = self::siteId( '/b2/' );
		$this->assertDirectoryExists( $m . '/wp-content/uploads/sites/' . $b2 );
		[ $code, $out, $err ] = WpSites::wpInput( 'm', "admin-pass\n", 'lw-migration', 'reset', 'media', '--no-backup', '--yes', '--user=admin' );
		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'Every media item of every site', $out );
		foreach ( [ '/', '/b2/', '/b3/' ] as $path ) {
			$this->assertSame( '0', trim( self::onSite( $path, 'eval', 'echo (int) array_sum( (array) wp_count_attachments() );' ) ), $path );
		}
		$this->assertDirectoryDoesNotExist( $m . '/wp-content/uploads/sites/' . $b2 );
		$this->assertNull( WpSites::lock( 'm' ) );
	}

	private static function onSite( string $path, string ...$args ): string {
		[ $code, $out, $err ] = WpSites::wpUrl( 'm', self::URL . $path, ...$args );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "wp --url=%s %s failed (%d):\n%s\n%s", self::URL . $path, implode( ' ', $args ), $code, $out, $err ) );
		}

		return $out;
	}

	/**
	 * @return array<int, string> The site's own active plugins.
	 */
	private static function activeOn( string $path ): array {
		return (array) json_decode( self::onSite( $path, 'option', 'get', 'active_plugins', '--format=json' ), true );
	}

	private static function siteId( string $path ): int {
		foreach ( (array) json_decode( WpSites::ok( 'm', 'site', 'list', '--fields=blog_id,url', '--format=json' ), true ) as $row ) {
			if ( self::URL . $path === ( $row['url'] ?? null ) ) {
				return (int) $row['blog_id'];
			}
		}
		throw new \RuntimeException( 'No site at ' . $path );
	}
}
