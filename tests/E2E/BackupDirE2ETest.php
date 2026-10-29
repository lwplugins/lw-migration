<?php
/**
 * The backups folder setting on a real WordPress site: REST, WP-CLI, moves
 * that stop half way, a missing folder, uninstall, and a network.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Site a; folders of its own are made beside the site folder (never under
 * it, never in a system tree). A test-only must-use plugin
 * (backup-dir-probe.php) forces copies and slows the browser ticks down on
 * request. Every test leaves the site with the default folder and removes
 * the folders it made; the class removes the probe.
 */
final class BackupDirE2ETest extends E2ETestCase {

	private const PROBE = 'lwm-e2e-backup-dir-probe.php';

	private const NS = '\\LightweightPlugins\\Migration\\';

	private const SMALL = [ '--exclude-media', '--exclude-plugins', '--exclude-themes' ];

	/** Every chosen folder gets the same deny files, robots.txt included. */
	private const DENY = [ '.htaccess', 'web.config', 'index.php', 'index.html', 'robots.txt' ];

	private static ?RestClient $client = null;

	private static ?CheckedRest $rest = null;

	/** @var array<int, string> Folders the current test made beside the site. */
	private array $made = [];

	public static function setUpBeforeClass(): void {
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$mu = WpSites::path( 'a' ) . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) ) {
			mkdir( $mu, 0755, true );
		}
		copy( __DIR__ . '/backup-dir-probe.php', $mu . '/' . self::PROBE );
		$storage      = WpSites::storage( 'a' );
		self::$client = new RestClient( 'a' );
		self::$rest   = new CheckedRest(
			self::$client,
			array_values( array_unique( [ $storage, (string) realpath( $storage ), basename( $storage ), 'admin-pass', trim( WpSites::ok( 'a', 'config', 'get', 'AUTH_KEY' ) ) ] ) )
		);
		self::$client->start();
	}

	public static function tearDownAfterClass(): void {
		if ( null !== self::$client ) {
			self::$client->stop();
			self::$client = null;
		}
		self::$rest = null;
		if ( false === getenv( 'LWM_E2E_A' ) ) {
			return;
		}
		$probe = WpSites::path( 'a' ) . '/wp-content/mu-plugins/' . self::PROBE;
		if ( is_file( $probe ) ) {
			unlink( $probe );
		}
	}

	protected function tearDown(): void {
		if ( false !== getenv( 'LWM_E2E_A' ) ) {
			WpSites::ok( 'a', 'option', 'delete', 'lwm_e2e_slow_move', 'lwm_e2e_move_copies' );
			$setting = self::setting();
			if ( '' !== $setting['path'] || null !== $setting['moving'] ) {
				WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', '--default', '--yes' );
			}
			foreach ( $this->made as $dir ) {
				TempDir::remove( $dir );
			}
		}
		$this->made = [];
		parent::tearDown();
	}

	private static function rest(): CheckedRest {
		if ( null === self::$rest || null === self::$client ) {
			throw new \RuntimeException( 'No web server.' );
		}

		return self::$rest;
	}

	/** @return array{cookie: string, nonce: string} */
	private static function admin(): array {
		if ( null === self::$client ) {
			throw new \RuntimeException( 'No web server.' );
		}

		return self::$client->login( 'admin' );
	}

	/** @return array{path: string, moving: string|null, marker: string} The stored setting. */
	private static function setting(): array {
		return (array) json_decode( WpSites::ok( 'a', 'eval', 'echo ' . self::NS . 'Storage\BackupDir::read()->toJson();' ), true );
	}

	/**
	 * @param array<int, string> $reasons Reason codes.
	 * @return array<string, string> Reason => the user's text.
	 */
	private static function texts( array $reasons ): array {
		$php = 'echo wp_json_encode( array_combine( $r = ' . var_export( $reasons, true ) . ', array_map( [ "' . self::NS . 'Storage\FolderMessages", "of" ], $r ) ) );';

		return (array) json_decode( WpSites::ok( 'a', 'eval', $php ), true );
	}

	/** A new folder name beside the site folder, removed after the test. */
	private function beside( string $name ): string {
		$dir          = dirname( (string) realpath( WpSites::path( 'a' ) ) ) . '/lwm-e2e-' . $name . '-' . bin2hex( random_bytes( 4 ) );
		$this->made[] = $dir;

		return $dir;
	}

	private static function defaultDir(): string {
		return WpSites::storage( 'a' ) . '/backups';
	}

	/** @return array<string, string> Backup name => md5 and mtime. */
	private static function fingerprints( string $dir ): array {
		$all = [];
		foreach ( (array) glob( $dir . '/*.wpress' ) as $file ) {
			clearstatcache( true, (string) $file );
			$all[ basename( (string) $file ) ] = md5_file( (string) $file ) . '@' . filemtime( (string) $file );
		}
		ksort( $all );

		return $all;
	}

	/** @return array<int, string> Our temps left in a folder. */
	private static function temps( string $dir ): array {
		return array_values( array_filter( (array) scandir( $dir ), static fn( $item ): bool => 1 === preg_match( '/^\.lwm-[0-9a-f]{16}\.part\z/', (string) $item ) ) );
	}

	/** @return array<int, string> Backup ids GET /backups lists. */
	private static function listed( array $auth ): array {
		$list = self::rest()->call( 'GET', '/backups', $auth );
		self::assertSame( 200, $list['status'], $list['body'] );

		return array_column( (array) $list['json']['items'], 'id' );
	}

	/** @param array<string, mixed> $body */
	private static function startMove( array $auth, array $body ): string {
		$created = self::rest()->call( 'POST', '/backup-dir', $auth, [], $body );
		self::assertSame( 201, $created['status'], $created['body'] );
		self::assertSame( 'move', $created['json']['type'] );

		return (string) $created['json']['id'];
	}

	private static function assertOurFolder( string $dir ): void {
		self::assertFileExists( $dir . '/.lw-migration-backups' );
		foreach ( self::DENY as $file ) {
			self::assertFileExists( $dir . '/' . $file );
		}
		clearstatcache();
		self::assertSame( 0750, fileperms( $dir ) & 0777 );
	}

	public function test_refused_folders_change_nothing_over_rest_and_cli(): void {
		$site    = (string) realpath( WpSites::path( 'a' ) );
		$foreign = $this->beside( 'foreign' );
		mkdir( $foreign );
		file_put_contents( $foreign . '/notes.txt', 'not a backup' );
		$theirs = $this->beside( 'theirs' );
		mkdir( $theirs, 0750 );
		file_put_contents( $theirs . '/.lw-migration-backups', "lw-migration-backups\nfedcba9876543210\n" );
		$loose = $this->beside( 'loose' );
		mkdir( $loose );
		chmod( $loose, 0770 );
		$texts = self::texts( [ 'site_folder', 'system', 'not_absolute', 'not_empty', 'foreign', 'world_writable' ] );
		$cases = [
			$site . '/wp-content/plugins' => $texts['site_folder'],
			$site . '/wp-content/uploads' => $texts['site_folder'],
			$site . '/wp-admin'           => $texts['site_folder'],
			'/etc/lwm'                    => $texts['system'],
			'relative'                    => $texts['not_absolute'],
			$foreign                      => $texts['not_empty'],
			$theirs                       => $texts['foreign'],
			$loose                        => $texts['world_writable'],
		];
		$auth   = self::admin();
		$before = self::rest()->call( 'GET', '/backup-dir', $auth );
		$this->assertSame( 200, $before['status'], $before['body'] );
		$this->assertSame( [ '', false, null, null ], [ $before['json']['custom'], $before['json']['hidden'], $before['json']['moving'], $before['json']['problem'] ] );

		foreach ( $cases as $path => $text ) {
			$refused = self::rest()->call( 'POST', '/backup-dir', $auth, [], [ 'path' => $path ] );
			$this->assertSame( [ 400, 'lw_migration_invalid', 'path', $text ], [ $refused['status'], $refused['json']['code'] ?? '', $refused['json']['data']['field'] ?? '', $refused['json']['message'] ?? '' ], $path );
			[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'backup-dir', 'move', $path, '--yes' );
			$this->assertSame( 1, $code, $path );
			$this->assertStringContainsString( $text, $err, $path );
		}
		$this->assertDirectoryDoesNotExist( '/etc/lwm' );
		$this->assertSame( [ 'notes.txt' ], array_values( array_diff( (array) scandir( $foreign ), [ '.', '..' ] ) ) );
		$this->assertSame( [ '.lw-migration-backups' ], array_values( array_diff( (array) scandir( $theirs ), [ '.', '..' ] ) ) );
		$this->assertSame( [], array_values( array_diff( (array) scandir( $loose ), [ '.', '..' ] ) ) );
		$this->assertSame( [], glob( $site . '/wp-content/plugins/lw-migration-backups-*' ) );
		$this->assertSame( $before['json'], self::rest()->call( 'GET', '/backup-dir', $auth )['json'] );
		$this->assertSame( [ '', null ], [ self::setting()['path'], self::setting()['moving'] ] );
		$this->assertNull( WpSites::lock( 'a' ), 'No move job holds the site.' );
	}

	public function test_a_parent_inside_wp_content_gets_a_folder_nobody_can_guess(): void {
		$site     = (string) realpath( WpSites::path( 'a' ) );
		[ $name ] = self::export( 'a', ...self::SMALL );
		$auth     = self::admin();

		$id  = self::startMove( $auth, [ 'path' => $site . '/wp-content' ] );
		$job = self::rest()->drive( $auth, $id );
		$this->assertSame( 'done', $job['status'], (string) ( $job['error'] ?? '' ) );
		$hidden = glob( $site . '/wp-content/lw-migration-backups-*' );
		$this->assertCount( 1, $hidden );
		$this->assertSame( 1, preg_match( '/lw-migration-backups-([a-f0-9]{12})\z/', (string) $hidden[0], $m ) );
		$hex = $m[1];
		self::assertOurFolder( (string) $hidden[0] );
		$this->assertFileExists( $hidden[0] . '/' . $name );
		$this->assertSame( [], glob( self::defaultDir() . '/*.wpress' ) );

		$stored = self::setting()['path'];
		$this->assertSame( realpath( $site . '/wp-content' ), realpath( dirname( $stored ) ), 'The parent is wp-content.' );
		$view = self::rest()->call( 'GET', '/backup-dir', $auth );
		$this->assertSame( [ dirname( $stored ) . '/lw-migration-backups-…', true, null ], [ $view['json']['custom'], $view['json']['hidden'], $view['json']['problem'] ] );
		$bodies = [ json_encode( $job ), $view['body'], self::rest()->call( 'GET', '/backups', $auth )['body'], self::rest()->call( 'GET', '/jobs/' . $id, $auth )['body'] ];
		foreach ( $bodies as $body ) {
			$this->assertStringNotContainsString( $hex, (string) $body );
		}
		$this->assertContains( 'own:' . $name, self::listed( $auth ) );

		$guess = curl_init( 'http://127.0.0.1:' . RestClient::PORT . '/wp-content/' . rawurlencode( $name ) );
		curl_setopt_array( $guess, [ CURLOPT_HTTPHEADER => [ 'Host: site-a.test' ], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ] );
		$body = (string) curl_exec( $guess );
		$this->assertSame( 404, curl_getinfo( $guess, CURLINFO_RESPONSE_CODE ), 'A URL built from the parent and the backup name finds nothing.' );
		$this->assertNotSame( file_get_contents( $hidden[0] . '/' . $name ), $body );

		[ $next, $path ] = self::export( 'a', ...self::SMALL );
		$this->assertSame( realpath( $hidden[0] . '/' . $next ), realpath( $path ), 'A new backup lands in the chosen folder.' );
		foreach ( self::names( new Reader( $path ) ) as $entry ) {
			$this->assertStringNotContainsString( 'lw-migration-backups-', $entry, 'The backups folder is never archived.' );
		}

		$back = self::rest()->drive( $auth, self::startMove( $auth, [ 'path' => '' ] ) );
		$this->assertSame( 'done', $back['status'], (string) ( $back['error'] ?? '' ) );
		$this->assertSame( [], glob( $site . '/wp-content/lw-migration-backups-*' ), 'The emptied folder of our own is removed.' );
		$this->assertFileExists( self::defaultDir() . '/' . $name );
		$this->assertFileExists( self::defaultDir() . '/' . $next );
		$this->assertSame( [ '', null ], [ self::setting()['path'], self::setting()['moving'] ] );
	}

	public function test_an_outside_folder_keeps_the_backups_for_listing_download_export_and_import(): void {
		[ $first ]  = self::export( 'a', ...self::SMALL );
		[ $second ] = self::export( 'a', ...self::SMALL );
		$before     = self::fingerprints( self::defaultDir() );
		$outside    = $this->beside( 'backups' );

		$out = WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		$this->assertMatchesRegularExpression( '/Success: \d+ backups? moved\./', $out );
		self::assertOurFolder( $outside );
		$this->assertSame( $before, self::fingerprints( $outside ), 'Same bytes, same times.' );
		$this->assertSame( [], glob( self::defaultDir() . '/*.wpress' ) );
		$this->assertStringContainsString( 'Backups folder: ' . $outside, WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'show' ) );
		$this->assertContains( $first, self::backups( 'a' ) );
		$this->assertContains( $second, self::backups( 'a' ) );
		$auth   = self::admin();
		$listed = self::listed( $auth );
		$this->assertContains( 'own:' . $first, $listed );
		$this->assertContains( 'own:' . $second, $listed );
		$this->assertSame( $listed, array_values( array_unique( $listed ) ) );
		$view = self::rest()->call( 'GET', '/backup-dir', $auth );
		$this->assertSame( [ $outside, false, null ], [ $view['json']['custom'], $view['json']['hidden'], $view['json']['problem'] ], 'A folder outside the web root is shown as typed.' );

		[ $third, $path ] = self::export( 'a', ...self::SMALL );
		$this->assertSame( $outside . '/' . $third, $path );

		$link = self::rest()->call( 'POST', '/backups/download-token', $auth, [], [ 'folder' => 'own', 'name' => $first ] );
		$this->assertSame( 200, $link['status'], $link['body'] );
		parse_str( (string) parse_url( (string) $link['json']['url'], PHP_URL_QUERY ), $query );
		$whole = self::rest()->call( 'GET', '/backups/download', [ 'cookie' => $auth['cookie'], 'nonce' => '' ], [ 'token' => (string) $query['token'] ] );
		$this->assertSame( 200, $whole['status'] );
		$this->assertSame( md5_file( $outside . '/' . $first ), md5( $whole['body'] ), 'The download is the exact file.' );

		$import = WpSites::ok( 'a', 'lw-migration', 'import', $second, '--yes' );
		$this->assertStringContainsString( 'Success', $import );
		$this->assertSame( $outside, self::setting()['path'], 'An import keeps the backups folder setting.' );
		$this->assertFileExists( $outside . '/' . $second );
	}

	public function test_a_move_stopped_half_way_lists_everything_once_and_finishes(): void {
		$outside = $this->beside( 'backups' );
		WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		$big = WpSites::path( 'a' ) . '/wp-content/uploads/lwm-e2e-big.bin';
		if ( ! is_dir( dirname( $big ) ) ) {
			mkdir( dirname( $big ), 0755, true );
		}
		file_put_contents( $big, random_bytes( 41943040 ) );
		try {
			[ $large ] = self::export( 'a', '--exclude-plugins', '--exclude-themes' );
		} finally {
			unlink( $big );
		}
		self::export( 'a', ...self::SMALL );
		self::export( 'a', ...self::SMALL );
		$this->assertGreaterThanOrEqual( 41943040, filesize( $outside . '/' . $large ) );
		$moved = self::fingerprints( $outside );
		$total = count( $moved ) + count( self::fingerprints( self::defaultDir() ) );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_move_copies', '1' );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_slow_move', '1' );
		$auth = self::admin();

		$id = self::startMove( $auth, [ 'path' => '' ] );
		for ( $i = 0; $i < 2; $i++ ) {
			$tick = self::rest()->call( 'POST', '/jobs/' . $id . '/tick', $auth );
			$this->assertSame( [ 200, 'running' ], [ $tick['status'], $tick['json']['status'] ], $tick['body'] );
		}
		$view = self::rest()->call( 'GET', '/backup-dir', $auth )['json'];
		$this->assertSame( '', $view['moving']['to'] ?? null );
		$this->assertGreaterThan( 0, $view['moving']['left'] ?? 0 );
		$this->assertTrue( $view['running'] );
		$listed = self::listed( $auth );
		$this->assertCount( $total, $listed, 'Every backup is listed once while the move is half way.' );
		$this->assertSame( $listed, array_values( array_unique( $listed ) ) );

		$this->assertSame( 2, $this->dueScheduleRun(), 'A due schedule waits while the backups are moved.' );

		$cancel = self::rest()->call( 'DELETE', '/jobs/' . $id, $auth );
		$this->assertSame( [ 200, 'cancelled' ], [ $cancel['status'], $cancel['json']['job']['status'] ], $cancel['body'] );
		$this->assertSame( [], self::temps( $outside ) );
		$this->assertSame( [], self::temps( self::defaultDir() ) );
		$this->assertCount( $total, self::listed( $auth ), 'Both folders stay listed after the cancel.' );
		$this->assertSame( [ $outside, '' ], [ self::setting()['path'], self::setting()['moving'] ] );

		WpSites::ok( 'a', 'option', 'delete', 'lwm_e2e_slow_move' );
		$done = self::rest()->drive( $auth, self::startMove( $auth, [ 'path' => '' ] ) );
		$this->assertSame( 'done', $done['status'], (string) ( $done['error'] ?? '' ) );
		$this->assertDirectoryDoesNotExist( $outside, 'The emptied folder of our own is removed.' );
		$home = self::fingerprints( self::defaultDir() );
		foreach ( $moved as $name => $print ) {
			$this->assertSame( $print, $home[ $name ] ?? null, $name . ' keeps its bytes and time.' );
		}
		$this->assertSame( [ '', null ], [ self::setting()['path'], self::setting()['moving'] ] );
	}

	public function test_an_undo_brings_the_backups_back_and_keeps_a_folder_with_other_files(): void {
		self::export( 'a', ...self::SMALL );
		$home    = self::fingerprints( self::defaultDir() );
		$outside = $this->beside( 'backups' );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_move_copies', '1' );
		WpSites::ok( 'a', 'option', 'update', 'lwm_e2e_slow_move', '1' );
		$auth = self::admin();

		$id   = self::startMove( $auth, [ 'path' => $outside ] );
		$tick = self::rest()->call( 'POST', '/jobs/' . $id . '/tick', $auth );
		$this->assertSame( 200, $tick['status'], $tick['body'] );
		$this->assertSame( 200, self::rest()->call( 'DELETE', '/jobs/' . $id, $auth )['status'] );
		$this->assertSame( [ '', $outside ], [ self::setting()['path'], self::setting()['moving'] ] );
		file_put_contents( $outside . '/notes.txt', 'mine' );

		WpSites::ok( 'a', 'option', 'delete', 'lwm_e2e_slow_move' );
		$undo = self::rest()->drive( $auth, self::startMove( $auth, [ 'action' => 'undo' ] ) );
		$this->assertSame( 'done', $undo['status'], (string) ( $undo['error'] ?? '' ) );
		$this->assertSame( $home, self::fingerprints( self::defaultDir() ) );
		$this->assertSame( [], glob( $outside . '/*.wpress' ) );
		$this->assertFileExists( $outside . '/notes.txt', 'A file that is not ours stays, and so does its folder.' );
		$this->assertNotSame( [], (array) ( $undo['warnings'] ?? [] ) );
		$this->assertSame( [ '', null ], [ self::setting()['path'], self::setting()['moving'] ] );
	}

	public function test_a_missing_folder_stops_backups_until_it_is_back(): void {
		$outside = $this->beside( 'backups' );
		WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		$away         = $outside . '-away';
		$this->made[] = $away;
		rename( $outside, $away );
		$missing = self::texts( [ 'missing' ] )['missing'];
		$auth    = self::admin();
		try {
			[ $code, , $err ] = WpSites::wp( 'a', 'lw-migration', 'export', ...self::SMALL );
			$this->assertSame( 1, $code );
			$this->assertStringContainsString( $missing, $err );
			$this->assertSame( [], glob( self::defaultDir() . '/*.wpress' ), 'Nothing is written into the default folder instead.' );
			$refused = self::rest()->call( 'POST', '/jobs', $auth, [], [ 'type' => 'export', 'options' => [ 'noMedia' => true, 'noPlugins' => true, 'noThemes' => true ] ] );
			$this->assertSame( [ 409, 'lw_migration_backup_dir_unusable', $missing ], [ $refused['status'], $refused['json']['code'] ?? '', $refused['json']['message'] ?? '' ] );
			$view = self::rest()->call( 'GET', '/backup-dir', $auth )['json'];
			$this->assertSame( [ 'missing', $missing ], [ $view['problem'], $view['problemText'] ] );
			[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'backup-dir', 'show' );
			$this->assertSame( 0, $code );
			$this->assertStringContainsString( 'Backups folder: ' . $outside, $out );
			$this->assertStringContainsString( 'Warning: ' . $missing, $err );
		} finally {
			rename( $away, $outside );
		}
		[ , $path ] = self::export( 'a', ...self::SMALL );
		$this->assertSame( $outside, dirname( $path ) );
	}

	public function test_uninstall_leaves_a_chosen_folder_as_it_is(): void {
		self::export( 'a', ...self::SMALL );
		$outside = $this->beside( 'backups' );
		WpSites::ok( 'a', 'lw-migration', 'backup-dir', 'move', $outside, '--yes' );
		$files = array_values( array_diff( (array) scandir( $outside ), [ '.', '..' ] ) );
		$sums  = array_map( static fn( string $file ): string => (string) md5_file( $outside . '/' . $file ), $files );

		try {
			WpSites::ok( 'a', 'plugin', 'uninstall', 'lw-migration', '--deactivate', '--skip-delete' );
			$this->assertSame( $files, array_values( array_diff( (array) scandir( $outside ), [ '.', '..' ] ) ) );
			$this->assertSame( $sums, array_map( static fn( string $file ): string => (string) md5_file( $outside . '/' . $file ), $files ) );
		} finally {
			WpSites::ok( 'a', 'plugin', 'activate', 'lw-migration' );
		}
		$this->assertSame( [ '', null ], [ self::setting()['path'], self::setting()['moving'] ], 'A reinstall does not adopt the old folder.' );
	}

	public function test_the_folder_command_runs_on_a_networks_main_site_only(): void {
		if ( ! WpSites::hasNetworks() ) {
			$this->markTestSkipped( 'Run with LWM_E2E_NETWORK=1.' );
		}
		[ $code, $out ] = WpSites::wp( 'n', 'lw-migration', 'backup-dir', 'show' );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Backups folder: default (', $out );

		[ $code, , $err ] = WpSites::wpUrl( 'n', 'http://net-n.test/b2/', 'lw-migration', 'backup-dir', 'move', '--default', '--yes' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Run this command on the network\'s main site', $err );
		[ $code, , $err ] = WpSites::wpUrl( 'n', 'http://net-n.test/b2/', 'lw-migration', 'backup-dir', 'show' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Run this command on the network\'s main site', $err );
	}

	/** Exit code of `schedule run --due` with one schedule due now; the schedule is removed after. */
	private function dueScheduleRun(): int {
		$php = '$s = ' . self::NS . 'Schedules\Schedule::fromArray( [ "id" => ' . self::NS . 'Schedules\Schedule::newId(), "anchor" => time(), "title" => "E2E move wait", "enabled" => true, "export" => [ "noMedia" => true, "noPlugins" => true, "noThemes" => true ], "notify" => [ "when" => "off" ] ] );'
			. ' ( new ' . self::NS . 'Schedules\Repository() )->save( $s ); ' . self::NS . 'Schedules\Events::sync( $s, time() );'
			. ' wp_clear_scheduled_hook( "lw_migration_schedule", [ $s->id ] ); wp_schedule_single_event( time() - 60, "lw_migration_schedule", [ $s->id ] );';
		WpSites::ok( 'a', 'eval', $php );
		try {
			[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'schedule', 'run', '--due' );
			$this->assertStringContainsString( 'Due schedules are waiting', $err, $out );

			return $code;
		} finally {
			WpSites::ok( 'a', 'eval', 'delete_option( "lw_migration_schedules" ); delete_option( "lw_migration_schedule_queue" ); wp_unschedule_hook( "lw_migration_schedule" ); wp_unschedule_hook( "lw_migration_schedule_continue" );' );
		}
	}
}
