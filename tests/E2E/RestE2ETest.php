<?php
/**
 * The REST API on a real WordPress site, over HTTP.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

/**
 * Every request is a separate PHP request of the built-in web server (256M,
 * four workers): a job is driven tick by tick with its state reloaded each
 * time. Every JSON answer is scanned for the site's folders and secrets
 * (CheckedRest).
 *
 * A test-only must-use plugin on site b (rest-probe.php) reports the peak memory of each REST
 * request in a header and, while a pause file names a job's swap marker,
 * shortens the tick and pauses once right after the swap: the tick then ends
 * past the point of no return, so a cancel can arrive there.
 */
final class RestE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-rest-probe.php';

	private const PAUSE = 'lwm-e2e-pause-after-swap';

	/** Upload slice: small enough for several chunks of a plugins-only archive. */
	private const CHUNK = 131072;

	private static ?RestClient $client = null;

	private static ?CheckedRest $rest = null;

	public static function setUpBeforeClass(): void {
		if ( false === getenv( 'LWM_E2E_B' ) ) {
			return;
		}
		$b = WpSites::path( 'b' );
		WpSites::wp( 'b', 'user', 'create', 'eddie', 'eddie@site-b.test', '--role=editor', '--user_pass=eddie-pass' );
		if ( ! is_dir( $b . '/wp-content/mu-plugins' ) ) {
			mkdir( $b . '/wp-content/mu-plugins', 0755, true );
		}
		copy( __DIR__ . '/rest-probe.php', $b . '/wp-content/mu-plugins/' . self::PROBE );
		$secrets = array_values(
			array_unique(
				[
					basename( WpSites::storage( 'b' ) ),
					$b,
					(string) realpath( $b ),
					'wp-content',
					'admin-pass',
					'eddie-pass',
					'upload.wpress',
					'download.wpress',
					trim( WpSites::ok( 'b', 'config', 'get', 'AUTH_KEY' ) ),
					trim( WpSites::ok( 'b', 'config', 'get', 'LOGGED_IN_SALT' ) ),
				]
			)
		);
		self::$client = new RestClient( 'b' );
		self::$rest   = new CheckedRest( self::$client, $secrets );
		self::$client->start();
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$client ) {
			self::$client->stop();
			self::$client = null;
		}
		if ( false === getenv( 'LWM_E2E_B' ) ) {
			return;
		}
		foreach ( [ '/wp-content/mu-plugins/' . self::PROBE, '/wp-content/' . self::PAUSE ] as $file ) {
			if ( is_file( WpSites::path( 'b' ) . $file ) ) {
				unlink( WpSites::path( 'b' ) . $file );
			}
		}
		$log = getenv( 'LWM_E2E_PEAK_LOG' );
		if ( null !== self::$rest && is_string( $log ) && '' !== $log ) {
			file_put_contents( $log, (string) json_encode( self::$rest->peaks(), JSON_PRETTY_PRINT ) . "\n" );
		}
		self::$rest = null;
	}

	private static function client(): RestClient {
		if ( null === self::$client ) {
			throw new \RuntimeException( 'No web server.' );
		}

		return self::$client;
	}

	/** Every JSON answer scanned for anything private, every peak memory noted. */
	private static function rest(): CheckedRest {
		if ( null === self::$rest ) {
			throw new \RuntimeException( 'No web server.' );
		}

		return self::$rest;
	}

	/**
	 * The same login session, its logged_in cookie without the REST nonce.
	 *
	 * @param array{cookie: string, nonce: string} $auth Login.
	 * @return array{cookie: string, nonce: string}
	 */
	private static function cookieOnly( array $auth ): array {
		return [
			'cookie' => $auth['cookie'],
			'nonce'  => '',
		];
	}

	public function test_routes_need_a_logged_in_admin_and_the_rest_nonce(): void {
		$admin = self::client()->login( 'admin' );

		$this->assertSame( 401, self::rest()->call( 'GET', '/jobs/active', null )['status'] );
		$this->assertSame( 401, self::rest()->call( 'GET', '/jobs/active', self::cookieOnly( $admin ) )['status'], 'A cookie without the REST nonce is anonymous.' );
		$this->assertSame(
			403,
			self::rest()->call(
				'GET',
				'/jobs/active',
				[
					'cookie' => $admin['cookie'],
					'nonce'  => '0123456789',
				]
			)['status']
		);
		$this->assertSame( 403, self::rest()->call( 'GET', '/jobs/active', self::client()->login( 'eddie' ) )['status'], 'An editor is refused.' );
		$this->assertSame( 401, self::rest()->call( 'POST', '/jobs', self::cookieOnly( $admin ), [], [ 'type' => 'export' ] )['status'], 'No job starts without the nonce.' );
		$this->assertSame( 401, self::rest()->call( 'GET', '/backups/download', null, [ 'token' => str_repeat( 'a', 64 ) ] )['status'] );
		$this->assertSame( 404, self::rest()->call( 'GET', '/jobs/0123456789ABCDEF', $admin )['status'], 'The route matches without case, the job store does not.' );
		$this->assertSame( 404, self::rest()->call( 'GET', '/jobs/0123456789abcdef', $admin )['status'] );
		$ok = self::rest()->call( 'GET', '/jobs/active', $admin );
		$this->assertSame( 200, $ok['status'] );
		$this->assertArrayHasKey( 'job', $ok['json'] );
	}

	public function test_browser_export_runs_by_ticks_and_downloads_with_ranges(): void {
		$c       = self::client();
		$auth    = $c->login( 'admin' );
		$created = self::rest()->call( 'POST', '/jobs', $auth, [], [ 'type' => 'export', 'options' => [ 'noMedia' => true, 'noThemes' => true ] ] );
		$this->assertSame( 201, $created['status'], $created['body'] );

		$job = self::rest()->drive( $auth, (string) $created['json']['id'] );
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		$name = (string) $job['result']['backup']['name'];
		$size = (int) $job['result']['backup']['size'];
		$list = self::rest()->call( 'GET', '/backups', $auth );
		$this->assertContains( 'own:' . $name, array_column( $list['json']['items'], 'id' ) );

		$link = self::rest()->call( 'POST', '/backups/download-token', $auth, [], [ 'folder' => 'own', 'name' => $name ] );
		$this->assertSame( 200, $link['status'], $link['body'] );
		parse_str( (string) parse_url( (string) $link['json']['url'], PHP_URL_QUERY ), $linkQuery );
		$this->assertSame( [ 'rest_route', 'token' ], array_keys( $linkQuery ), 'The link carries the token only, never the REST nonce.' );
		$this->assertSame( '/lw-migration/v1/backups/download', $linkQuery['rest_route'] );
		$this->assertGreaterThan( time(), (int) $link['json']['expires'] );
		$token  = [ 'token' => (string) $linkQuery['token'] ];
		$cookie = self::cookieOnly( $auth );

		$whole = self::rest()->call( 'GET', '/backups/download', $cookie, $token );
		$this->assertSame( 200, $whole['status'] );
		$this->assertStringStartsWith( 'attachment; filename="' . $name . '"', $whole['headers']['content-disposition'] );
		$this->assertSame( (string) $size, $whole['headers']['content-length'] );
		$this->assertSame( 'bytes', $whole['headers']['accept-ranges'] );
		$this->assertSame( 'no-referrer', $whole['headers']['referrer-policy'] );
		$this->assertStringContainsString( 'no-store', $whole['headers']['cache-control'] );
		$this->assertSame( $size, strlen( $whole['body'] ) );
		$this->assertSame( 'package.json', rtrim( substr( $whole['body'], 0, 255 ), "\0" ) );

		$head = self::rest()->call( 'HEAD', '/backups/download', $cookie, $token );
		$this->assertSame( [ 200, (string) $size, '' ], [ $head['status'], $head['headers']['content-length'], $head['body'] ], 'HEAD: the headers of the GET, no body.' );

		$part = self::rest()->call( 'GET', '/backups/download', $cookie, $token, null, [ 'Range: bytes=0-99' ] );
		$this->assertSame( 206, $part['status'] );
		$this->assertSame( substr( $whole['body'], 0, 100 ), $part['body'] );
		$this->assertSame( 'bytes 0-99/' . $size, $part['headers']['content-range'] );
		$tail = self::rest()->call( 'GET', '/backups/download', $cookie, $token, null, [ 'Range: bytes=' . ( $size - 10 ) . '-', 'If-Range: ' . $whole['headers']['etag'] ] );
		$this->assertSame( 206, $tail['status'], 'A resume of the same file gets the rest.' );
		$this->assertSame( substr( $whole['body'], -10 ), $tail['body'] );
		$stale = self::rest()->call( 'GET', '/backups/download', $cookie, $token, null, [ 'Range: bytes=0-99', 'If-Range: "changed"' ] );
		$this->assertSame( 200, $stale['status'], 'A changed file is sent whole.' );
		$this->assertSame( 416, self::rest()->call( 'GET', '/backups/download', $cookie, $token, null, [ 'Range: bytes=999999999999-' ] )['status'] );

		$this->assertSame( 401, self::rest()->call( 'GET', '/backups/download', null, $token )['status'], 'No login cookie, no file.' );
		$this->assertSame( 401, self::rest()->call( 'GET', '/backups/download', $cookie )['status'], 'No token, no file.' );
		$this->assertSame( 401, self::rest()->call( 'GET', '/backups/download', self::cookieOnly( $c->login( 'admin' ) ), $token )['status'], 'The token belongs to its login session.' );
		$this->assertSame( 401, self::rest()->call( 'GET', '/backups/download', self::cookieOnly( $c->login( 'eddie' ) ), $token )['status'], 'Not for an editor.' );
		$this->assertSame( 206, self::rest()->call( 'GET', '/backups/download', $cookie, $token, null, [ 'Range: bytes=0-0' ] )['status'], 'The link works again until it expires.' );
	}

	public function test_backup_label_entries_and_settings_answer_as_json(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-themes', '--exclude-plugins' );
		$auth     = self::client()->login( 'admin' );
		$where    = [
			'folder' => 'own',
			'name'   => $name,
		];

		$label = self::rest()->call( 'POST', '/backups/label', $auth, [], $where + [ 'label' => 'Before <b>launch</b>' ] );
		$this->assertSame( 200, $label['status'], $label['body'] );
		$this->assertSame( 'Before launch', $label['json']['label'] );
		$listed = array_column( self::rest()->call( 'GET', '/backups', $auth )['json']['items'], 'label', 'id' );
		$this->assertSame( 'Before launch', $listed[ 'own:' . $name ] );

		$page = self::rest()->call( 'GET', '/backups/entries', $auth, $where + [ 'limit' => 1 ] );
		$this->assertSame( 200, $page['status'], $page['body'] );
		$this->assertSame( 'package.json', $page['json']['entries'][0]['name'] );
		$this->assertFalse( $page['json']['encrypted'] );
		$next = self::rest()->call( 'GET', '/backups/entries', $auth, $where + [ 'after' => (int) $page['json']['next'] ] );
		$this->assertSame( 200, $next['status'], $next['body'] );
		$this->assertNotContains( 'package.json', array_column( $next['json']['entries'], 'name' ) );

		$saved = self::rest()->call( 'POST', '/settings', $auth, [], [ 'retention' => [ 'count' => 7 ] ] );
		$this->assertSame( 200, $saved['status'], $saved['body'] );
		$this->assertSame( 7, self::rest()->call( 'GET', '/settings', $auth )['json']['retention']['count'] );
		$refused = self::rest()->call( 'POST', '/settings', $auth, [], [ 'retention' => [ 'count' => -1 ] ] );
		$this->assertSame( 400, $refused['status'] );
		$this->assertSame( 7, self::rest()->call( 'GET', '/settings', $auth )['json']['retention']['count'], 'Nothing half-valid is stored.' );
		$this->assertSame( 200, self::rest()->call( 'POST', '/settings', $auth, [], [ 'retention' => [ 'count' => 0 ] ] )['status'] );

		$this->assertSame( 404, self::rest()->call( 'GET', '/backups/entries', $auth, [ 'folder' => 'own', 'name' => '../' . $name ] )['status'] );
		$this->assertSame( 200, self::rest()->call( 'DELETE', '/backups', $auth, $where )['status'] );
		$this->assertSame( 404, self::rest()->call( 'DELETE', '/backups', $auth, $where )['status'] );
	}

	public function test_a_declined_restore_cancels_and_frees_the_site(): void {
		[ $name ] = self::export( 'b', '--exclude-media', '--exclude-themes', '--exclude-plugins' );
		$auth     = self::client()->login( 'admin' );
		$created  = self::rest()->call( 'POST', '/jobs', $auth, [], [ 'type' => 'import', 'source' => 'backup', 'name' => $name, 'folder' => 'own' ] );
		$this->assertSame( 201, $created['status'], $created['body'] );
		$id = (string) $created['json']['id'];

		$this->assertSame( 409, self::rest()->call( 'DELETE', '/backups', $auth, [ 'folder' => 'own', 'name' => $name ] )['status'], 'A restore reads it.' );
		$job = self::rest()->drive( $auth, $id );
		$this->assertSame( 'confirm', $job['input']['kind'] ?? '', (string) ( $job['error'] ?? '' ) );
		$declined = self::rest()->call( 'POST', '/jobs/' . $id . '/input', $auth, [], [ 'confirm' => false ] );
		$this->assertSame( 200, $declined['status'], $declined['body'] );
		$this->assertSame( 'cancelled', $declined['json']['status'] );
		$this->assertNull( WpSites::lock( 'b' ) );
		$this->assertNull( self::rest()->call( 'GET', '/jobs/active', $auth )['json']['job'] );
		$this->assertSame( 200, self::rest()->call( 'DELETE', '/backups', $auth, [ 'folder' => 'own', 'name' => $name ] )['status'] );
	}

	public function test_an_abandoned_job_resumes_from_a_new_tab_when_its_lock_expired(): void {
		$first   = self::client()->login( 'admin' );
		$created = self::rest()->call( 'POST', '/jobs', $first, [], [ 'type' => 'export', 'options' => [ 'noMedia' => true, 'noThemes' => true, 'noPlugins' => true ] ] );
		$this->assertSame( 201, $created['status'], $created['body'] );
		$id = (string) $created['json']['id'];
		$this->assertSame( 409, self::rest()->call( 'POST', '/jobs', $first, [], [ 'type' => 'export' ] )['status'], 'One job per site.' );

		// The tab was closed before its first tick and the lock ran out; a new tab knows nothing of the job.
		WpSites::ok( 'b', 'eval', 'update_option( "lw_migration_lock", wp_json_encode( [ "job" => "' . $id . '", "expires" => 1 ] ), false );' );
		$tab    = self::client()->login( 'admin' );
		$active = self::rest()->call( 'GET', '/jobs/active', $tab );
		$this->assertSame( $id, $active['json']['job']['id'], 'The job is offered again.' );
		$job = self::rest()->drive( $tab, (string) $active['json']['job']['id'] );
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		$this->assertNull( WpSites::lock( 'b' ) );
		$this->assertSame( 200, self::rest()->call( 'DELETE', '/backups', $tab, [ 'folder' => 'own', 'name' => (string) $job['result']['backup']['name'] ] )['status'] );
	}

	/** Last: it replaces site b's database. */
	public function test_an_uploaded_archive_imports_after_confirmation_and_defers_a_late_cancel(): void {
		$title      = trim( WpSites::ok( 'a', 'option', 'get', 'blogname' ) );
		[ , $path ] = self::export( 'a', '--exclude-media', '--exclude-themes' );
		$archive    = (string) file_get_contents( $path );
		$size       = strlen( $archive );
		$this->assertGreaterThan( 3 * self::CHUNK, $size, 'The upload needs several chunks.' );
		$auth    = self::client()->login( 'admin' );
		$before  = self::backups( 'b' );
		$created = self::rest()->call( 'POST', '/jobs', $auth, [], [ 'type' => 'import', 'source' => 'upload', 'name' => basename( $path ), 'size' => $size ] );
		$this->assertSame( 201, $created['status'], $created['body'] );
		$id = (string) $created['json']['id'];

		$waiting = self::rest()->drive( $auth, $id )['input'] ?? [];
		$this->assertSame( 'upload', $waiting['kind'] ?? '' );
		$this->assertGreaterThanOrEqual( 262144, $waiting['chunkBytes'] ?? 0, 'The page learns the chunk size the server takes.' );
		$this->assertLessThanOrEqual( 8388608, $waiting['chunkBytes'] ?? PHP_INT_MAX );
		$this->upload( $auth, $id, $archive );

		$job = self::rest()->drive( $auth, $id );
		$this->assertSame( 'confirm', $job['input']['kind'] ?? '', (string) ( $job['error'] ?? '' ) );
		$this->assertSame( 'http://site-a.test', $job['input']['summary']['from'] );
		$this->assertSame( 'http://site-b.test', $job['input']['summary']['to'] );
		$this->assertFalse( $job['pastNoReturn'] );

		file_put_contents( WpSites::path( 'b' ) . '/wp-content/' . self::PAUSE, WpSites::storage( 'b' ) . '/jobs/' . $id . '/swap-started' );
		$this->assertSame( 200, self::rest()->call( 'POST', '/jobs/' . $id . '/input', $auth, [], [ 'confirm' => true, 'safetyBackup' => false ] )['status'] );
		$job = $this->tickUntilPastNoReturn( $auth, $id );
		$this->assertSame( 'running', $job['status'], 'The tick ended right after the swap.' );

		$cancel = self::rest()->call( 'DELETE', '/jobs/' . $id, $auth );
		$this->assertSame( 200, $cancel['status'], $cancel['body'] );
		$this->assertTrue( $cancel['json']['cancelDeferred'], 'The database is already replaced: the cancel is only recorded.' );
		$this->assertSame( 'running', $cancel['json']['job']['status'] );
		$this->assertTrue( $cancel['json']['job']['cancelDeferred'] );
		$this->assertNotNull( WpSites::lock( 'b' ), 'The job keeps the site.' );

		$auth = $this->stillLoggedIn( $auth );
		$job  = self::rest()->drive( $auth, $id );
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		$this->assertSame( $title, trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ) );
		$this->assertSame( 'http://site-b.test', trim( WpSites::ok( 'b', 'option', 'get', 'home' ) ) );
		$this->assertSame( $before, self::backups( 'b' ), 'The switch at the confirmation skipped the safety backup.' );
		$this->assertNull( WpSites::lock( 'b' ) );
		$this->assertSame( [], glob( WpSites::storage( 'b' ) . '/jobs/' . $id . '/upload.wpress*' ) );
	}

	/**
	 * Sends the archive in slices like the browser: a resent slice and a slice
	 * whose answer got lost are told where to go on; a resent last slice gets
	 * the job.
	 *
	 * @param array{cookie: string, nonce: string} $auth    Login.
	 * @param string                               $id      Job id.
	 * @param string                               $archive Archive bytes.
	 */
	private function upload( array $auth, string $id, string $archive ): void {
		$size  = strlen( $archive );
		$slice = fn( int $offset ): array => self::rest()->call( 'POST', '/jobs/' . $id . '/upload', $auth, [ 'offset' => $offset ], substr( $archive, $offset, self::CHUNK ) );

		$first = $slice( 0 );
		$this->assertSame( 200, $first['status'], $first['body'] );
		$this->assertSame( self::CHUNK, $first['json']['input']['received'] );
		$again = $slice( 0 );
		$this->assertSame( 409, $again['status'], 'A resent slice is not written twice.' );
		$this->assertSame( 'lw_migration_upload_offset', $again['json']['code'] );
		$this->assertSame( self::CHUNK, $again['json']['data']['received'] );

		$slice( self::CHUNK ); // The answer to this slice is lost.
		$retry = $slice( self::CHUNK );
		$this->assertSame( 409, $retry['status'] );
		$offset = (int) $retry['json']['data']['received'];
		$this->assertSame( 2 * self::CHUNK, $offset, 'The server says where the upload goes on.' );

		$last = $offset;
		$sent = $retry;
		while ( $offset < $size ) {
			$sent = $slice( $offset );
			$this->assertSame( 200, $sent['status'], $sent['body'] );
			$last    = $offset;
			$offset += self::CHUNK;
		}
		$this->assertNotSame( 'needs_input', $sent['json']['status'] ?? '', 'The full upload was handed to the job.' );
		$resent = $slice( $last );
		$this->assertSame( 200, $resent['status'], 'A resent last slice (its answer got lost) gets the job.' );
		$this->assertSame( $id, $resent['json']['id'] );
	}

	/**
	 * @param array{cookie: string, nonce: string} $auth Login.
	 * @return array<string, mixed> The job view of the tick that crossed the point of no return.
	 */
	private function tickUntilPastNoReturn( array $auth, string $id ): array {
		for ( $i = 0; $i < 100; $i++ ) {
			$job = (array) self::rest()->call( 'POST', '/jobs/' . $id . '/tick', $auth )['json'];
			if ( ! empty( $job['pastNoReturn'] ) || in_array( $job['status'] ?? '', [ 'done', 'failed', 'cancelled', 'needs_input' ], true ) ) {
				$this->assertTrue( $job['pastNoReturn'] ?? false, (string) ( $job['error'] ?? '' ) );
				return $job;
			}
		}
		throw new \RuntimeException( 'The import did not reach its swap in 100 ticks.' );
	}

	/**
	 * The importing admin keeps the session the import started in.
	 *
	 * @param array{cookie: string, nonce: string} $auth Login before the swap.
	 * @return array{cookie: string, nonce: string}
	 */
	private function stillLoggedIn( array $auth ): array {
		$this->assertSame( 200, self::rest()->call( 'GET', '/jobs/active', $auth )['status'], 'The importing admin is still logged in after the swap.' );

		return $auth;
	}
}
