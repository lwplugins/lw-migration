<?php
/**
 * Transfer links on real WordPress sites: site A shares a backup kept outside
 * its web root, site B restores it from the link, refusals look alike.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Site A is served by PHP's built-in web server (RestClient); site B reaches
 * it over 127.0.0.1 (a test-only must-use plugin lets B's HTTP API call a
 * local address on that port). Every test starts and stops its own server,
 * moves A's backups back to the default folder and removes what it made.
 */
final class ShareE2ETest extends E2ETestCase {

	private const SMALL = [ '--exclude-media', '--exclude-plugins', '--exclude-themes' ];

	private const ALLOW_LOCAL = 'lwm-e2e-allow-local.php';

	private const NS = '\\LightweightPlugins\\Migration\\';

	private ?RestClient $client = null;

	/** @var array<int, string> Folders the test made beside the sites. */
	private array $made = [];

	/** @var array<int, string> Backups a network test made on n. */
	private array $networkBackups = [];

	protected function tearDown(): void {
		if ( null !== $this->client ) {
			$this->client->stop();
			$this->client = null;
		}
		if ( false !== getenv( 'LWM_E2E_A' ) ) {
			$mu = WpSites::path( 'b' ) . '/wp-content/mu-plugins/' . self::ALLOW_LOCAL;
			if ( is_file( $mu ) ) {
				unlink( $mu );
			}
			WpSites::wp( 'a', 'option', 'delete', 'lw_migration_shares' );
			$setting = json_decode( WpSites::ok( 'a', 'eval', 'echo ' . self::NS . 'Storage\BackupDir::read()->toJson();' ), true );
			if ( '' !== ( $setting['path'] ?? '' ) || null !== ( $setting['moving'] ?? null ) ) {
				WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', '--default', '--yes' );
			}
			foreach ( $this->made as $dir ) {
				TempDir::remove( $dir );
			}
		}
		foreach ( $this->networkBackups as $name ) {
			WpSites::wp( 'n', 'lw-migration', 'delete', $name, '--yes' );
		}
		if ( [] !== $this->networkBackups ) {
			WpSites::wp( 'n', 'option', 'delete', 'lw_migration_shares' );
		}
		$this->made           = [];
		$this->networkBackups = [];
		parent::tearDown();
	}

	private function serve( string $site, string $host = '' ): RestClient {
		$this->client = new RestClient( $site, $host );
		$this->client->start();

		return $this->client;
	}

	/** A new folder beside the site folders (outside every web root), removed after the test. */
	private function beside( string $name ): string {
		$dir          = dirname( (string) realpath( WpSites::path( 'a' ) ) ) . '/lwm-e2e-' . $name . '-' . bin2hex( random_bytes( 4 ) );
		$this->made[] = $dir;

		return $dir;
	}

	/** The token of a transfer link. */
	private static function token( string $url ): string {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query['share'] ?? '' );
	}

	/**
	 * @return array<int, array<string, mixed>> `backup share --list` of a site.
	 */
	private static function links( string $site = 'a' ): array {
		return (array) json_decode( WpSites::ok( $site, 'lw-migration', 'backup', 'share', '--list', '--format=json' ), true );
	}

	/** The link of `backup share <name>` (the second output line). */
	private static function cliShare( string $site, string $name, string ...$flags ): string {
		$out = WpSites::ok( $site, 'lw-migration', 'backup', 'share', $name, ...$flags );
		if ( 1 !== preg_match( '#^(http://\S+share=[a-f0-9]{32})$#m', $out, $m ) ) {
			throw new \RuntimeException( "Unexpected share output:\n" . $out );
		}

		return $m[1];
	}

	public function test_a_link_downloads_one_backup_without_login_and_site_b_restores_it(): void {
		$client  = $this->serve( 'a' );
		$outside = $this->beside( 'share-backups' );
		WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		$marker = 'Share E2E ' . bin2hex( random_bytes( 4 ) );
		WpSites::ok( 'a', 'post', 'create', '--post_title=' . $marker, '--post_status=publish' );
		[ $name, $path ] = self::export( 'a', ...self::SMALL );
		$this->assertSame( $outside . '/' . $name, $path, 'The backup is outside the web root.' );
		$bytes = (string) file_get_contents( $path );
		$auth  = $client->login( 'admin' );

		$created = $client->call( 'POST', '/backups/shares', $auth, [], [ 'folder' => 'own', 'name' => $name ] );
		$this->assertSame( 201, $created['status'], $created['body'] );
		$this->assertSame( [ 'id', 'url', 'expiresAt' ], array_keys( $created['json'] ) );
		$url   = (string) $created['json']['url'];
		$token = self::token( $url );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\z/', $token );
		$this->assertStringStartsWith( 'http://site-a.test/', $url );
		$listed = $client->call( 'GET', '/backups/shares', $auth );
		$this->assertSame( 200, $listed['status'] );
		$this->assertSame( $created['json']['id'], $listed['json']['items'][0]['id'] );
		$cli = WpSites::ok( 'a', 'lw-migration', 'backup', 'share', '--list', '--format=json' );
		foreach ( [ $listed['body'], $cli, WpSites::ok( 'a', 'option', 'get', 'lw_migration_shares' ) ] as $text ) {
			$this->assertStringNotContainsString( $token, $text );
		}
		foreach ( [ $listed['body'], $cli ] as $text ) {
			$this->assertStringNotContainsString( hash( 'sha256', $token ), $text );
			$this->assertStringNotContainsString( $outside, $text );
		}

		$query = [ 'share' => $token ];
		$head  = $client->call( 'HEAD', '/backups/shared', null, $query );
		$this->assertSame( 200, $head['status'] );
		$this->assertSame( (string) strlen( $bytes ), $head['headers']['content-length'] ?? null );
		$this->assertSame( 'bytes', $head['headers']['accept-ranges'] ?? null );
		$this->assertNotSame( '', $head['headers']['etag'] ?? '' );
		$this->assertSame( 'no-store, private', $head['headers']['cache-control'] ?? null );
		$this->assertSame( 'no-referrer', $head['headers']['referrer-policy'] ?? null );
		$this->assertSame( 'noindex, nofollow', $head['headers']['x-robots-tag'] ?? null );
		$this->assertSame( 'nosniff', $head['headers']['x-content-type-options'] ?? null );
		$this->assertSame( 0, self::links()[0]['uses'], 'HEAD is no use.' );

		$part = $client->call( 'GET', '/backups/shared', null, $query, null, [ 'Range: bytes=0-99' ] );
		$this->assertSame( 206, $part['status'] );
		$this->assertSame( substr( $bytes, 0, 100 ), $part['body'] );
		$this->assertSame( 0, self::links()[0]['uses'], 'A short range is no use.' );

		$whole = $client->call( 'GET', '/backups/shared', null, $query );
		$this->assertSame( 200, $whole['status'] );
		$this->assertSame( md5( $bytes ), md5( $whole['body'] ), 'The download is the exact file.' );
		$this->assertStringContainsString( $name, $whole['headers']['content-disposition'] ?? '' );

		$stale = $client->call( 'GET', '/backups/shared', null, $query, null, [ 'Range: bytes=100-199', 'If-Range: "not-this-file"' ] );
		$this->assertSame( 200, $stale['status'], 'A resume of another file gets the whole file.' );
		$this->assertSame( md5( $bytes ), md5( $stale['body'] ) );
		$this->assertSame( 2, self::links()[0]['uses'], 'What was sent counts: a stale If-Range sends the whole file, a download from the start.' );
		$resume = $client->call( 'GET', '/backups/shared', null, $query, null, [ 'Range: bytes=100-' ] );
		$this->assertSame( 206, $resume['status'] );
		$this->assertSame( [ 2, 'live' ], [ self::links()[0]['uses'], self::links()[0]['state'] ], 'A resume is no use.' );

		$this->restoreOnB( $url, $token, $marker );
		$this->assertSame( 3, self::links()[0]['uses'], 'The import on B is one use (its header probe is none).' );

		$this->assertSame( 200, $client->call( 'DELETE', '/backups/shares/' . $created['json']['id'], $auth )['status'] );
		$before = trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) );
		[ $code, , $err ] = WpSites::wp( 'b', 'lw-migration', 'import', self::local( $url ), '--yes', '--user=admin', '--no-safety-backup' );
		$this->assertNotSame( 0, $code, 'A revoked link restores nothing.' );
		$this->assertStringNotContainsString( $token, $err );
		$this->assertSame( $before, trim( WpSites::ok( 'b', 'option', 'get', 'blogname' ) ), "B's live site is untouched." );
		$this->assertNull( WpSites::lock( 'b' ) );
	}

	/** Site A's link as site B reaches it (A's server on 127.0.0.1). */
	private static function local( string $url ): string {
		return 'http://127.0.0.1:' . RestClient::PORT . substr( $url, strlen( 'http://site-a.test' ) );
	}

	private function restoreOnB( string $url, string $token, string $marker ): void {
		$mu = WpSites::path( 'b' ) . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) ) {
			mkdir( $mu, 0755, true );
		}
		file_put_contents(
			$mu . '/' . self::ALLOW_LOCAL,
			"<?php\n// E2E only: lets the URL import reach the local test server.\n"
			. "add_filter( 'http_request_host_is_external', static fn( \$external, \$host ) => '127.0.0.1' === \$host ? true : \$external, 10, 2 );\n"
			. "add_filter( 'http_allowed_safe_ports', static fn( \$ports ) => array_merge( (array) \$ports, [ " . RestClient::PORT . " ] ) );\n"
		);

		[ $code, $out, $err ] = WpSites::wp( 'b', 'lw-migration', 'import', self::local( $url ), '--yes', '--user=admin', '--no-safety-backup' );

		$this->assertSame( 0, $code, $out . $err );
		$this->assertStringNotContainsString( $token, $out . $err );
		$this->assertSame( 'http://site-b.test', trim( WpSites::ok( 'b', 'option', 'get', 'home' ) ) );
		$this->assertSame( $marker, trim( WpSites::ok( 'b', 'post', 'list', '--post_type=post', '--title=' . $marker, '--field=post_title' ) ), "B shows A's post." );
		$jobs = WpSites::storage( 'b' ) . '/jobs';
		$ids  = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $jobs, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$this->assertStringNotContainsString( $token, (string) file_get_contents( $file->getPathname() ), 'No job file of B keeps the token: ' . $file->getFilename() );
			$ids[] = basename( dirname( $file->getPathname() ) );
		}
		$php   = '$s = ' . self::NS . 'Services::get(); $api = ' . self::NS . 'Rest\JobsApi::fromServices( $s ); $v = []; foreach ( $s->jobs()->ids() as $id ) { $v[] = $api->view( $s->jobs()->load( $id ) ); } echo wp_json_encode( $v );';
		$views = WpSites::ok( 'b', 'eval', $php );
		$this->assertNotSame( '[]', trim( $views ), 'The import job is still listed.' );
		$this->assertNotSame( [], $ids );
		$this->assertStringNotContainsString( $token, $views );
	}

	public function test_every_refused_link_gets_the_same_404(): void {
		$client          = $this->serve( 'a' );
		[ $name ]        = self::export( 'a', ...self::SMALL );
		[ $doomed ]      = self::export( 'a', ...self::SMALL );
		$revoked         = self::cliShare( 'a', $name );
		$expired         = self::cliShare( 'a', $name, '--expires=5m' );
		$gone            = self::cliShare( 'a', $doomed );
		$live            = self::cliShare( 'a', $name );
		$ids             = array_column( self::links(), 'id' );
		[ $revokedId ]   = $ids;
		[ , $expiredId ] = $ids;
		WpSites::ok( 'a', 'lw-migration', 'backup', 'share', '--revoke=' . $revokedId );
		WpSites::ok( 'a', 'eval', '$r = new ' . self::NS . 'WordPress\OptionRow( "lw_migration_shares" ); $raw = (string) $r->read(); $d = json_decode( $raw, true ); foreach ( $d as $i => $row ) { if ( "' . $expiredId . '" === $row["id"] ) { $d[ $i ]["expiresAt"] = time() - 10; } } $r->swap( $raw, wp_json_encode( $d, JSON_UNESCAPED_SLASHES ) );' );
		WpSites::ok( 'a', 'lw-migration', 'delete', $doomed, '--yes' );
		$asRoot = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
		[ , $lockedPath ] = self::export( 'a', ...self::SMALL );
		$locked           = self::cliShare( 'a', basename( $lockedPath ) );
		chmod( $lockedPath, 0000 );
		$this->assertSame( 200, $client->call( 'HEAD', '/backups/shared', null, [ 'share' => self::token( $live ) ] )['status'], 'The live link still opens.' );
		$admin  = $client->login( 'admin' );
		$logins = [
			'anonymous'   => null,
			'cookie only' => [ 'cookie' => $admin['cookie'], 'nonce' => '' ],
			'admin'       => $admin,
		];
		$queries = [
			'no share'  => [],
			'31 hex'    => [ 'share' => substr( self::token( $live ), 1 ) ],
			'random'    => [ 'share' => bin2hex( random_bytes( 16 ) ) ],
			'revoked'   => [ 'share' => self::token( $revoked ) ],
			'expired'   => [ 'share' => self::token( $expired ) ],
			'gone'      => [ 'share' => self::token( $gone ) ],
			'two given' => [ 'share' => [ self::token( $live ) ] ],
		];
		if ( ! $asRoot ) { // Root reads a mode 000 file.
			$queries['unreadable'] = [ 'share' => self::token( $locked ) ];
		}
		$first = null;
		try {
			foreach ( $queries as $label => $query ) {
				foreach ( $logins as $who => $auth ) {
					$answer = $client->call( 'GET', '/backups/shared', $auth, $query );
					$this->assertSame( 404, $answer['status'], $label . ', ' . $who );
					$this->assertArrayNotHasKey( 'content-disposition', $answer['headers'], $label . ', ' . $who );
					$first = $first ?? $answer['body'];
					$this->assertSame( $first, $answer['body'], 'Byte for byte the same: ' . $label . ', ' . $who );
				}
			}
			if ( ! $asRoot ) {
				$head = $client->call( 'HEAD', '/backups/shared', null, [ 'share' => self::token( $locked ) ] );
				$this->assertSame( 404, $head['status'], 'HEAD of an unreadable backup is refused too.' );
				$this->assertArrayNotHasKey( 'content-disposition', $head['headers'] );
				$this->assertArrayNotHasKey( 'etag', $head['headers'] );
			}
		} finally {
			chmod( $lockedPath, 0644 );
		}
		$this->assertSame( 'lw_migration_share_unavailable', json_decode( (string) $first, true )['code'] ?? null );
		$this->assertSame( 1, preg_match_all( '/"code"/', (string) $first ), 'The answer says nothing more.' );
		$this->assertSame( 0, array_sum( array_column( self::links(), 'uses' ) ), 'No refusal counts as a use.' );
	}

	public function test_uninstall_removes_the_links_and_keeps_the_backups(): void {
		$outside = $this->beside( 'share-uninstall' );
		WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		[ $name, $path ] = self::export( 'a', ...self::SMALL );
		self::cliShare( 'a', $name );
		$keep  = [ 'lw_migration_storage_dir', 'lw_migration_backup_labels', 'lw_migration_retention', 'lw_migration_backup_dir' ];
		$saved = WpSites::ok( 'a', 'eval', 'echo wp_json_encode( array_map( static fn( $o ) => get_option( $o, null ), ' . var_export( $keep, true ) . ' ) );' );
		$md5   = md5_file( $path );
		try {
			WpSites::ok( 'a', 'eval', 'define( "WP_UNINSTALL_PLUGIN", "lw-migration/lw-migration.php" ); include WP_PLUGIN_DIR . "/lw-migration/uninstall.php";' );

			$this->assertSame( '', trim( WpSites::ok( 'a', 'eval', 'echo (string) get_option( "lw_migration_shares", "" );' ) ) );
			$this->assertSame( $md5, md5_file( $path ), 'The shared backup stays.' );
		} finally {
			WpSites::ok( 'a', 'eval', 'foreach ( array_combine( ' . var_export( $keep, true ) . ', json_decode( base64_decode( "' . base64_encode( $saved ) . '" ), true ) ) as $o => $v ) { if ( null !== $v ) { update_option( $o, $v, false ); } }' );
		}
		$this->assertContains( $name, self::backups( 'a' ) );
	}

	public function test_a_subsite_path_never_serves_a_link(): void {
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run LWM_E2E_NETWORK=1 bin/test-wp.sh to execute the network tests.' );
		}
		[ $name ]               = self::export( 'n', ...self::SMALL );
		$this->networkBackups[] = $name;
		$url                    = self::cliShare( 'n', $name );
		$this->assertStringStartsWith( 'http://net-n.test/', $url );
		$client = $this->serve( 'n', 'net-n.test' );
		$query  = [ 'share' => self::token( $url ) ];
		$this->assertSame( 200, $client->call( 'HEAD', '/backups/shared', null, $query )['status'], 'The main site serves the link.' );
		$admin = $client->login( 'admin' );

		foreach ( [ null, [ 'cookie' => $admin['cookie'], 'nonce' => '' ], $admin ] as $auth ) {
			$answer = $client->call( 'GET', '/backups/shared', $auth, $query, null, [], '/b2' );
			$this->assertSame( 404, $answer['status'] );
			$this->assertArrayNotHasKey( 'content-disposition', $answer['headers'] );
			$this->assertLessThan( 1024, strlen( $answer['body'] ), 'No file bytes.' );
			$this->assertStringNotContainsString( $name, $answer['body'] );
		}
		$this->assertSame( 0, self::links( 'n' )[0]['uses'] );
	}
}
