<?php
/**
 * The backups folder with WP-CLI run as root, in a Linux container.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * A throwaway site inside a wordpress:cli container (its own file system, so
 * owners are real: the site belongs to www-data, uid 82), on the E2E
 * database server (the container shares its network). As root the plugin
 * never creates a backups folder (it would belong to root and nothing may
 * change an owner), and says to run the command as the web server's user; a
 * folder that user prepared is used by root as well. Skipped when Docker or
 * the image is not there. Every docker call is bounded by timeout.
 */
final class BackupDirRootE2ETest extends E2ETestCase {

	private const IMAGE = 'wordpress:cli';

	private const CONTAINER = 'lwm-e2e-root';

	private const DATABASE = 'lwm_e2e_root';

	private const SITE = '/srv/site';

	private const SMALL = [ '--exclude-media', '--exclude-plugins', '--exclude-themes' ];

	protected function setUp(): void {
		parent::setUp();
		if ( 0 !== self::docker( 60, 'image', 'inspect', self::IMAGE )[0] || 0 !== self::docker( 60, 'inspect', 'lwm-e2e-db' )[0] ) {
			$this->markTestSkipped( 'Needs Docker with the ' . self::IMAGE . ' image and the E2E database container.' );
		}
	}

	/**
	 * @param int    $seconds Limit.
	 * @param string ...$args Docker arguments.
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	private static function docker( int $seconds, string ...$args ): array {
		$proc = proc_open( array_merge( [ 'timeout', (string) $seconds, 'docker' ], $args ), [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) ) {
			throw new \RuntimeException( 'Cannot start docker.' );
		}
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return [ proc_close( $proc ), $out, $err ];
	}

	/**
	 * WP-CLI in the container.
	 *
	 * @param bool   $root    As root (else as www-data).
	 * @param string ...$args WP-CLI arguments.
	 * @return array{0: int, 1: string, 2: string}
	 */
	private static function wp( bool $root, string ...$args ): array {
		$user = $root ? [ '-u', '0', self::CONTAINER, 'wp', '--allow-root' ] : [ '-u', '82', self::CONTAINER, 'wp' ];

		return self::docker( 300, 'exec', ...array_merge( $user, [ '--path=' . self::SITE ], $args ) );
	}

	private static function ok( bool $root, string ...$args ): string {
		[ $code, $out, $err ] = self::wp( $root, ...$args );
		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( "wp %s failed (%d):\n%s\n%s", implode( ' ', $args ), $code, $out, $err ) );
		}

		return $out;
	}

	private static function database( string $sql ): void {
		$link = mysqli_connect( '127.0.0.1', 'root', 'lwm', '', (int) getenv( 'LWM_E2E_DB_PORT' ) );
		mysqli_query( $link, $sql );
		mysqli_close( $link );
	}

	private static function shell( string $script ): void {
		[ $code, $out, $err ] = self::docker( 120, 'exec', '-u', '0', self::CONTAINER, 'sh', '-c', $script );
		if ( 0 !== $code ) {
			throw new \RuntimeException( "Container shell failed ($code):\n$out\n$err" );
		}
	}

	private function build(): void {
		self::database( 'DROP DATABASE IF EXISTS `' . self::DATABASE . '`' );
		self::database( 'CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
		self::docker( 60, 'rm', '-f', self::CONTAINER );
		[ $code, , $err ] = self::docker( 300, 'run', '-d', '--name', self::CONTAINER, '--network', 'container:lwm-e2e-db', '--user', '0', '--entrypoint', 'sleep', self::IMAGE, '1800' );
		$this->assertSame( 0, $code, $err );
		$this->assertSame( 0, self::docker( 300, 'cp', dirname( WpSites::path( 'a' ) ) . '/core', self::CONTAINER . ':' . self::SITE )[0] );
		$this->assertSame( 0, self::docker( 300, 'cp', WpSites::path( 'a' ) . '/wp-content/plugins/lw-migration', self::CONTAINER . ':' . self::SITE . '/wp-content/plugins/lw-migration' )[0] );
		self::ok( true, 'config', 'create', '--dbname=' . self::DATABASE, '--dbuser=root', '--dbpass=lwm', '--dbhost=127.0.0.1:3306', '--skip-check', '--quiet' );
		self::ok( true, 'core', 'install', '--url=http://site-root.test', '--title=LWM root', '--admin_user=admin', '--admin_password=admin-pass', '--admin_email=admin@site-root.test', '--skip-email', '--quiet' );
		self::ok( true, 'plugin', 'activate', 'lw-migration', '--quiet' );
		self::shell( 'mkdir -p /srv/keep && chown -R 82:82 ' . self::SITE . ' /srv/keep && chmod 0750 /srv/keep' );
	}

	public function test_as_root_a_folder_is_never_created_but_one_the_web_user_prepared_is_used(): void {
		try {
			$this->build();
			self::ok( false, 'lw-migration', 'export', ...self::SMALL ); // The web user makes the storage folder.

			[ $code, $out, $err ] = self::wp( true, 'lw-migration', 'backup-dir', 'move', '/srv/keep/backups', '--yes' );
			$this->assertSame( 1, $code, $out . $err );
			$this->assertStringContainsString( 'As root the plugin creates nothing in the backups folder. Run this as the web server\'s user, for example: sudo -u www-data wp', $err );
			$this->assertNotSame( 0, self::docker( 60, 'exec', self::CONTAINER, 'test', '-e', '/srv/keep/backups' )[0], 'Nothing was created.' );
			$this->assertStringContainsString( 'Backups folder: default (', self::ok( true, 'lw-migration', 'backup-dir', 'show' ) );
			$this->assertNull( json_decode( self::ok( true, 'eval', 'echo wp_json_encode( get_option( "lw_migration_lock", null ) );' ), true ), 'The refused start left no lock.' );

			$this->assertStringContainsString( 'Success:', self::ok( false, 'lw-migration', 'backup-dir', 'move', '/srv/keep/backups', '--yes' ) );
			$this->assertStringContainsString( 'Backups folder: /srv/keep/backups', self::ok( true, 'lw-migration', 'backup-dir', 'show' ) );
			$export = self::ok( true, 'lw-migration', 'export', ...self::SMALL );
			$this->assertMatchesRegularExpression( '#Backup location: /srv/keep/backups/\S+\.wpress#', $export, 'Root uses the prepared folder.' );
			$this->assertCount( 2, (array) json_decode( self::ok( true, 'lw-migration', 'list', '--format=json' ), true ) );
		} finally {
			self::docker( 60, 'rm', '-f', self::CONTAINER );
			self::database( 'DROP DATABASE IF EXISTS `' . self::DATABASE . '`' );
		}
	}
}
