<?php
/**
 * The REST API on a real network served over HTTP.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;

/**
 * A throwaway subdirectory network h (own database lwm_e2e_h, sites b2 and b3,
 * this plugin network-active) whose domain is 127.0.0.1:<RestClient::PORT>,
 * so PHP's built-in web server serves it as it is: the browser flow of a
 * network backup and restore runs over HTTP on the main site, and a subsite
 * address or a subsite admin reaches nothing. Removed when the class ends.
 */
final class NetworkRestE2ETest extends E2ETestCase {

	private const DB = 'lwm_e2e_h';

	private const HOST = '127.0.0.1:' . RestClient::PORT;

	private const HOME = 'http://' . self::HOST;

	private static string $dir = '';

	private static ?RestClient $client = null;

	private static ?CheckedRest $rest = null;

	public static function setUpBeforeClass(): void {
		if ( ! WpSites::hasNetworks() ) {
			return;
		}
		self::$dir = dirname( WpSites::path( 'a' ) ) . '/h';
		self::build();
		putenv( 'LWM_E2E_H=' . self::$dir );
		self::$client = new RestClient( 'h', self::HOST );
		self::$client->start();
		self::$rest = new CheckedRest( self::$client, [ basename( WpSites::storage( 'h' ) ), self::$dir, (string) realpath( self::$dir ), 'admin-pass', 'sub-pass', 'main-pass' ] );
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$client ) {
			self::$client->stop();
			self::$client = null;
		}
		self::$rest = null;
		if ( '' !== self::$dir ) {
			self::shell( [ 'rm', '-rf', self::$dir ] );
			self::database( 'DROP DATABASE IF EXISTS `' . self::DB . '`' );
			putenv( 'LWM_E2E_H' );
			self::$dir = '';
		}
	}

	protected function setUp(): void {
		parent::setUp();
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to execute the network tests.' );
		}
	}

	public function test_the_network_answers_on_its_port_address(): void {
		$page = self::get( '/b2/' );

		$this->assertSame( 200, $page['status'], 'WordPress serves the subsite under the port address without a redirect.' );
		$this->assertStringContainsString( 'LWM h b2', $page['body'] );
	}

	public function test_the_site_list_pages_and_searches_the_network(): void {
		$auth = self::$client->login( 'admin' );

		$all = self::$rest->call( 'GET', '/network/sites', $auth );
		$this->assertSame( 200, $all['status'], $all['body'] );
		$this->assertSame( 3, $all['json']['total'] );
		$this->assertSame( [ 1, 2, 3 ], array_column( $all['json']['items'], 'id' ) );
		$this->assertSame( [ 'id', 'url', 'title' ], array_keys( $all['json']['items'][1] ) );
		$this->assertSame( 'LWM h b2', $all['json']['items'][1]['title'] );

		$second = self::$rest->call( 'GET', '/network/sites', $auth, [ 'page' => 2, 'perPage' => 1 ] );
		$this->assertSame( [ 2 ], array_column( $second['json']['items'], 'id' ) );
		$this->assertSame( 3, $second['json']['pages'] );

		$this->assertSame( [ 3 ], array_column( self::$rest->call( 'GET', '/network/sites', $auth, [ 'search' => 'b3' ] )['json']['items'], 'id' ) );
		$this->assertSame( [], self::$rest->call( 'GET', '/network/sites', $auth, [ 'search' => 'LWM h b2' ] )['json']['items'], 'Titles are not searched.' );

		$bad = self::$rest->call( 'GET', '/network/sites', $auth, [ 'page' => 'x' ] );
		$this->assertSame( 400, $bad['status'] );
		$this->assertSame( 'page', $bad['json']['data']['field'] );
	}

	public function test_a_chosen_site_is_backed_up_and_restored_as_a_new_site(): void {
		$auth   = self::$client->login( 'admin' );
		$export = self::$rest->call(
			'POST',
			'/jobs',
			$auth,
			[],
			[
				'type'    => 'export',
				'options' => [
					'sites'   => [ 2 ],
					'noMedia' => true,
				],
			]
		);
		$this->assertSame( 201, $export['status'], $export['body'] );
		$done = self::$rest->drive( $auth, (string) $export['json']['id'] );
		$this->assertSame( 'done', $done['status'], (string) json_encode( $done ) );
		$name = (string) $done['result']['backup']['name'];

		$reader    = new Reader( WpSites::storage( 'h' ) . '/backups/' . $name );
		$multisite = $reader->find( 'multisite.json' );
		$this->assertNotNull( $multisite );
		$json = (array) json_decode( $reader->readContents( $multisite ), true );
		$reader->close();
		$this->assertSame( [ 2 ], array_map( 'intval', array_column( (array) $json['Sites'], 'BlogID' ) ) );

		$import = self::$rest->call(
			'POST',
			'/jobs',
			$auth,
			[],
			[
				'type'         => 'import',
				'source'       => 'backup',
				'name'         => $name,
				'folder'       => 'own',
				'safetyBackup' => false,
			]
		);
		$this->assertSame( 201, $import['status'], $import['body'] );
		$id      = (string) $import['json']['id'];
		$waiting = self::$rest->drive( $auth, $id );
		$this->assertSame( 'needs_input', $waiting['status'], (string) json_encode( $waiting ) );
		$network = $waiting['input']['summary']['network'];
		$this->assertSame( 'sites', $network['kind'] );
		$this->assertSame( 2, $network['sites'][0]['from']['blogId'] );
		$this->assertNotSame( '', $network['sites'][0]['proposal'] );

		$answer = self::$rest->call(
			'POST',
			'/jobs/' . $id . '/input',
			$auth,
			[],
			[
				'confirm' => true,
				'sites'   => (object) [ '0' => self::HOME . '/shop' ],
			]
		);
		$this->assertSame( 200, $answer['status'], $answer['body'] );
		$finished = 'done' === ( $answer['json']['status'] ?? '' ) ? $answer['json'] : self::$rest->drive( $auth, $id );
		$this->assertSame( 'done', $finished['status'], (string) json_encode( $finished ) );
		$this->assertSame( [ self::HOME . '/shop' ], array_column( $finished['result']['sites'], 'url' ), 'The new site\'s home address.' );
		$this->assertTrue( $finished['result']['sites'][0]['created'] );

		$this->assertContains( self::HOME . '/shop/', self::lines( WpSites::ok( 'h', 'site', 'list', '--field=url' ) ) );
		$this->assertSame( 200, self::get( '/shop/' )['status'] );
		$this->assertNull( WpSites::lock( 'h' ) );
	}

	public function test_a_subsite_address_has_no_migration_route(): void {
		$auth = self::$client->login( 'admin' );
		$this->assertSame( 200, self::$client->call( 'GET', '/jobs/active', $auth )['status'], 'The super admin reaches the main site.' );

		foreach ( [ [ 'GET', '/jobs/active' ], [ 'GET', '/network/sites' ], [ 'GET', '/backups' ], [ 'POST', '/schedules/continue' ] ] as [ $method, $route ] ) {
			$answer = self::$client->call( $method, $route, $auth, [], 'POST' === $method ? [] : null, [], '/b2' );
			$this->assertSame( 404, $answer['status'], $method . ' /b2' . $route );
			$this->assertSame( 'rest_no_route', $answer['json']['code'] ?? null, $method . ' /b2' . $route );
		}
	}

	public function test_a_subsite_admin_reaches_nothing(): void {
		[ $code, $out, $err ] = WpSites::wpUrl( 'h', self::HOME . '/b2/', 'user', 'create', 'subadmin', 'subadmin@h.test', '--role=administrator', '--user_pass=sub-pass' );
		$this->assertSame( 0, $code, $out . $err );

		$this->assertEveryRouteRefused( self::$client->login( 'subadmin' ) );
	}

	public function test_a_main_site_admin_without_super_admin_reaches_nothing(): void {
		WpSites::ok( 'h', 'user', 'create', 'mainadmin', 'mainadmin@h.test', '--role=administrator', '--user_pass=main-pass' );
		$this->assertContains( 'administrator', self::lines( WpSites::ok( 'h', 'user', 'get', 'mainadmin', '--field=roles' ) ), 'An administrator of the main site.' );
		$this->assertNotContains( 'mainadmin', self::lines( WpSites::ok( 'h', 'super-admin', 'list' ) ), 'Not a super admin.' );

		$this->assertEveryRouteRefused( self::$client->login( 'mainadmin' ) );
	}

	/**
	 * Every route of the plugin, called on the main site with this login, is a 403.
	 *
	 * @param array{cookie: string, nonce: string} $auth Login from RestClient::login().
	 */
	private function assertEveryRouteRefused( array $auth ): void {
		$job    = '/jobs/0123456789abcdef';
		$routes = [
			[ 'POST', '/jobs' ],
			[ 'GET', '/jobs/active' ],
			[ 'GET', $job ],
			[ 'DELETE', $job ],
			[ 'POST', $job . '/tick' ],
			[ 'POST', $job . '/input' ],
			[ 'POST', $job . '/upload' ],
			[ 'GET', '/backups' ],
			[ 'DELETE', '/backups' ],
			[ 'POST', '/backups/label' ],
			[ 'GET', '/backups/entries' ],
			[ 'GET', '/backups/contents' ],
			[ 'POST', '/backups/download-token' ],
			[ 'GET', '/backups/download' ],
			[ 'GET', '/settings' ],
			[ 'POST', '/settings' ],
			[ 'GET', '/schedules' ],
			[ 'POST', '/schedules' ],
			[ 'POST', '/schedules/0a1b2c3d' ],
			[ 'DELETE', '/schedules/0a1b2c3d' ],
			[ 'POST', '/schedules/0a1b2c3d/run' ],
			[ 'GET', '/schedules/0a1b2c3d/log' ],
			[ 'DELETE', '/schedules/0a1b2c3d/log' ],
			[ 'POST', '/schedules/continue' ],
			[ 'GET', '/network/sites' ],
		];
		foreach ( $routes as [ $method, $route ] ) {
			$answer = self::$rest->call( $method, $route, $auth, [], in_array( $method, [ 'POST', 'DELETE' ], true ) ? [] : null );
			$this->assertSame( 403, $answer['status'], $method . ' ' . $route . ': ' . $answer['body'] );
		}
	}

	/**
	 * The network the way bin/test-wp.sh builds n, then moved to the port address.
	 */
	private static function build(): void {
		self::shell( [ 'rm', '-rf', self::$dir ] );
		self::database( 'DROP DATABASE IF EXISTS `' . self::DB . '`' );
		self::database( 'CREATE DATABASE `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
		self::shell( [ 'cp', '-R', dirname( WpSites::path( 'a' ) ) . '/core', self::$dir ] );
		self::shell( [ 'cp', '-R', WpSites::path( 'n' ) . '/wp-content/plugins/lw-migration', self::$dir . '/wp-content/plugins/' ] );
		$wp = static fn( string ...$args ) => self::must( WpSites::wpAt( self::$dir, ...$args ) );
		$wp( 'config', 'create', '--dbname=' . self::DB, '--dbuser=root', '--dbpass=lwm', '--dbhost=127.0.0.1:' . getenv( 'LWM_E2E_DB_PORT' ), '--skip-check', '--quiet' );
		$wp( 'core', 'multisite-install', '--url=http://net-h.test', '--title=LWM h', '--admin_user=admin', '--admin_password=admin-pass', '--admin_email=admin@net-h.test', '--skip-email', '--skip-config', '--quiet' );
		foreach ( [ [ 'MULTISITE', 'true', '--raw' ], [ 'SUBDOMAIN_INSTALL', 'false', '--raw' ], [ 'DOMAIN_CURRENT_SITE', 'net-h.test' ], [ 'PATH_CURRENT_SITE', '/' ], [ 'SITE_ID_CURRENT_SITE', '1', '--raw' ], [ 'BLOG_ID_CURRENT_SITE', '1', '--raw' ] ] as $constant ) {
			$wp( 'config', 'set', ...array_merge( $constant, [ '--quiet' ] ) );
		}
		$wp( 'site', 'create', '--slug=b2', '--title=LWM h b2', '--email=admin@net-h.test', '--quiet' );
		$wp( 'site', 'create', '--slug=b3', '--title=LWM h b3', '--email=admin@net-h.test', '--quiet' );
		$wp( 'plugin', 'activate', 'lw-migration', '--network', '--quiet' );
		// Verified test bed: both steps, or WP-CLI no longer finds the main site.
		$wp( 'search-replace', 'net-h.test', self::HOST, '--network', '--all-tables', '--quiet' );
		$wp( 'config', 'set', 'DOMAIN_CURRENT_SITE', self::HOST, '--quiet' );
	}

	/**
	 * A plain GET of a page of the network (no login).
	 *
	 * @return array{status: int, body: string}
	 */
	private static function get( string $path ): array {
		$curl = curl_init( 'http://' . self::HOST . $path );
		curl_setopt_array(
			$curl,
			[
				CURLOPT_HTTPHEADER     => [ 'Host: ' . self::HOST ],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
			]
		);
		$body = curl_exec( $curl );

		return [
			'status' => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'body'   => is_string( $body ) ? $body : '',
		];
	}

	/** @return array<int, string> */
	private static function lines( string $out ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), static fn( string $l ): bool => '' !== $l ) );
	}

	private static function database( string $sql ): void {
		$link = mysqli_connect( '127.0.0.1', 'root', 'lwm', '', (int) getenv( 'LWM_E2E_DB_PORT' ) );
		mysqli_query( $link, $sql );
		mysqli_close( $link );
	}

	/**
	 * @param array<int, string> $cmd Command and arguments.
	 */
	private static function shell( array $cmd ): void {
		$proc = proc_open( $cmd, [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
		if ( ! is_resource( $proc ) || 0 !== proc_close( $proc ) ) {
			throw new \RuntimeException( implode( ' ', $cmd ) . ' failed.' );
		}
	}

	/**
	 * @param array{0: int, 1: string, 2: string} $result WpSites::wpAt() result.
	 */
	private static function must( array $result ): void {
		if ( 0 !== $result[0] ) {
			throw new \RuntimeException( "WP-CLI failed:\n" . $result[1] . $result[2] );
		}
	}
}
