<?php
/**
 * The edges of the new destination types against real servers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\E2E;

use LightweightPlugins\Migration\Archive\Reader;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Run by bin/test-cloud.sh (Review Focus 1, 3, 5 and the secrets rule):
 * Nextcloud upload folders that expire or vanish, a MOVE whose request
 * died, one-request WebDAV, SFTP host keys (unknown, confirmed over REST,
 * changed), a part file cut short at the server, FTP passive addresses and
 * FTPS without a fallback.
 */
final class CloudProtocolEdgesE2ETest extends E2ETestCase {

	private bool $prepared = false;

	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'sftp', 'ftp', 'nextcloud' ] as $kind ) {
			if ( CloudServers::available( $kind ) ) {
				CloudServers::prepare();
				$this->prepared = true;
				return;
			}
		}
		$this->markTestSkipped( 'Run bin/test-cloud.sh to start the SFTP, FTP or Nextcloud servers.' );
	}

	protected function tearDown(): void {
		if ( $this->prepared ) {
			CloudServers::tidy();
		}
		parent::tearDown();
	}

	private static function need( string $kind ): void {
		if ( ! CloudServers::available( $kind ) ) {
			self::markTestSkipped( 'Run bin/test-cloud.sh to start the ' . $kind . ' server.' );
		}
	}

	private static function folder( string $what ): string {
		return 'm7b-' . $what . '-' . bin2hex( random_bytes( 3 ) );
	}

	/**
	 * @return array<int, int> Offsets announced for a destination (cloud-probe.php's log).
	 */
	private static function parts( string $id ): array {
		$log = (string) @file_get_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-parts.log' ); // phpcs:ignore -- no log yet is an empty one.
		preg_match_all( '/^' . $id . ' (\d+)$/m', $log, $m );

		return array_map( 'intval', $m[1] );
	}

	/**
	 * @return array<string, mixed> A job's saved state on site a.
	 */
	private static function job( string $job ): array {
		return (array) json_decode( (string) file_get_contents( WpSites::storage( 'a' ) . '/jobs/' . $job . '/job.json' ), true );
	}

	/**
	 * Nextcloud's own upload folder of a job, as the server's disk holds it.
	 */
	private static function uploadFolderOnDisk( string $folder ): bool {
		[ $code ] = CloudServers::docker( 'exec', 'lwm-e2e-nextcloud', 'test', '-d', '/var/www/html/data/lwm/uploads/' . $folder );

		return 0 === $code;
	}

	/**
	 * @return array<int, string> The lines of an OpenSSH container's log.
	 */
	private static function sshLog( string $container ): array {
		[ , $out ] = CloudServers::docker( 'exec', $container, 'cat', '/config/logs/openssh/current' );

		return explode( "\n", trim( $out ) );
	}

	/**
	 * Waits until an OpenSSH container logged the end of a connection after line $from.
	 *
	 * @return array<int, string> The new lines.
	 */
	private static function sshLogAfter( string $container, int $from ): array {
		for ( $i = 0; $i < 20; $i++ ) {
			$new = array_slice( self::sshLog( $container ), $from );
			if ( [] !== preg_grep( '/\[preauth\]|Accepted|Failed|session opened/', $new ) ) {
				return $new;
			}
			usleep( 250000 );
		}

		return array_slice( self::sshLog( $container ), $from );
	}

	/**
	 * No user name, password or key reached the server in these log lines.
	 *
	 * @param array<int, string> $lines New log lines.
	 */
	private static function assertNoLogin( array $lines ): void {
		self::assertNotSame( [], preg_grep( '/\[preauth\]/', $lines ), 'The connection reached the server: ' . implode( "\n", $lines ) );
		foreach ( $lines as $line ) {
			self::assertDoesNotMatchRegularExpression( '/\blwm\b|Accepted|Failed|Postponed|Invalid user/', $line, 'No login was tried.' );
		}
	}

	/**
	 * After a death, the parts went out from the first one again up to the end.
	 *
	 * @param array<int, int> $parts  Offsets announced for the destination.
	 * @param int             $before How many of them came before the death.
	 * @param int             $size   Backup size.
	 */
	private static function assertStartedOver( array $parts, int $before, int $size ): void {
		$after = array_slice( $parts, $before );
		self::assertNotSame( [], $after, 'Parts after the death: ' . json_encode( $parts ) );
		self::assertSame( $parts[0], $after[0], 'The upload started over from its first part: ' . json_encode( $parts ) );
		self::assertSame( $size, end( $after ), 'And went on to the end.' );
	}

	/**
	 * Review Focus 1: Nextcloud loses the upload folder between two ticks.
	 */
	public function test_nextcloud_upload_folder_removed_mid_upload_starts_over(): void {
		self::need( 'nextcloud' );
		$folder = self::folder( 'gone' );
		$id     = CloudServers::add( 'a', 'nextcloud', 'Gone', $folder );
		[ $name, $path ] = self::export( 'a' );
		file_put_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-kill-after-cloud-part', $id );
		$before = 0;

		[ $last, $died ] = CloudServers::uploadOverRest(
			$name,
			$id,
			static function ( string $job ) use ( &$before, $id ): void {
				$before = count( self::parts( $id ) );
				$remote = (string) ( self::job( $job )['cursor']['session']['remote'] ?? '' );
				self::assertMatchesRegularExpression( '/^lw-migration-[0-9a-f]{16}$/', $remote );
				[ $host, $port ] = CloudServers::address( 'nextcloud' );
				$curl            = curl_init( 'http://' . $host . ':' . $port . '/remote.php/dav/uploads/' . rawurlencode( (string) getenv( 'LWM_E2E_NEXTCLOUD_USER' ) ) . '/' . $remote );
				curl_setopt_array(
					$curl,
					[
						CURLOPT_RESOLVE        => [ $host . ':' . $port . ':127.0.0.1' ],
						CURLOPT_CUSTOMREQUEST  => 'DELETE',
						CURLOPT_USERPWD        => getenv( 'LWM_E2E_NEXTCLOUD_USER' ) . ':' . getenv( 'LWM_E2E_NEXTCLOUD_SECRET' ),
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_TIMEOUT        => 30,
					]
				);
				curl_exec( $curl );
				self::assertSame( 204, curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), 'Nextcloud removed the upload folder.' );
			}
		);

		$this->assertSame( 1, $died );
		$this->assertSame( 'done', $last['status'], (string) json_encode( $last ) );
		$this->assertSame( 'ok', $last['result']['cloud'][0]['status'], 'Started over in a new upload folder and finished.' );
		self::assertStartedOver( self::parts( $id ), $before, filesize( $path ) );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( 'nextcloud', $folder, $name ) );
	}

	/**
	 * Review Focus 1: an upload folder idle for 23 hours is taken for gone without a request.
	 */
	public function test_nextcloud_upload_folder_idle_for_a_day_starts_over_in_a_new_one(): void {
		self::need( 'nextcloud' );
		$folder = self::folder( 'idle' );
		$id     = CloudServers::add( 'a', 'nextcloud', 'Idle', $folder );
		[ $name, $path ] = self::export( 'a' );
		file_put_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-kill-after-cloud-part', $id );
		$before = 0;
		$old    = '';

		[ $last, $died ] = CloudServers::uploadOverRest(
			$name,
			$id,
			static function ( string $job ) use ( &$before, &$old, $id ): void {
				$before = count( self::parts( $id ) );
				$file   = WpSites::storage( 'a' ) . '/jobs/' . $job . '/job.json';
				$state  = self::job( $job );
				$old    = (string) $state['cursor']['session']['remote'];
				$state['cursor']['session']['extra']['touched'] -= 90000; // A day ago.
				file_put_contents( $file, (string) json_encode( $state ) );
			}
		);

		$this->assertSame( 1, $died );
		$this->assertSame( 'done', $last['status'], (string) json_encode( $last ) );
		$this->assertSame( 'ok', $last['result']['cloud'][0]['status'] );
		self::assertStartedOver( self::parts( $id ), $before, filesize( $path ) );
		$this->assertTrue( self::uploadFolderOnDisk( $old ), 'The old upload folder got no request (no chunk, no MOVE, no DELETE).' );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( 'nextcloud', $folder, $name ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function davKinds(): array {
		return [
			'Nextcloud (MOVE .file)'     => [ 'nextcloud' ],
			'one-request WebDAV (MOVE)' => [ 'webdav' ],
		];
	}

	/**
	 * A MOVE that arrived before its request died is recognised by the target's size.
	 */
	#[DataProvider( 'davKinds' )]
	public function test_a_move_whose_request_died_is_found_in_place( string $kind ): void {
		self::need( $kind );
		$folder          = self::folder( 'move-' . $kind );
		$id              = CloudServers::add( 'a', $kind, 'Move ' . $kind, $folder );
		[ $name, $path ] = self::export( 'a' );
		touch( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-kill-after-move' );

		[ $last, $died ] = CloudServers::uploadOverRest( $name, $id );

		$this->assertSame( 1, $died, 'The tick that moved the file died.' );
		$this->assertSame( 'done', $last['status'], (string) json_encode( $last ) );
		$this->assertSame( 'ok', $last['result']['cloud'][0]['status'], (string) json_encode( $last ) );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( $kind, $folder, $name ) );
		$this->assertSame( [ $name ], CloudServers::filesOnDisk( $kind, $folder ), 'No part file is left.' );
	}

	/**
	 * Review Focus 5.
	 */
	public function test_generic_webdav_streams_a_backup_and_refuses_an_oversized_one_on_the_web(): void {
		self::need( 'webdav' );
		$folder = self::folder( 'plain' );
		$id     = CloudServers::add( 'a', 'webdav', 'One request', $folder );

		[ $name, $path ] = self::export( 'a', '--to=' . $id ); // WP-CLI: one streamed PUT of the whole backup.

		$this->assertSame( [ $id . ' ' . filesize( $path ) ], array_map( static fn( int $o ): string => $id . ' ' . $o, self::parts( $id ) ), 'One part: the whole backup.' );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( 'webdav', $folder, $name ) );

		file_put_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-single-put-bytes', '1048576' );
		[ $last ] = CloudServers::uploadOverRest( $name, $id );
		$this->assertSame( 'failed', $last['result']['cloud'][0]['status'], (string) json_encode( $last ) );
		$this->assertStringContainsString( 'wp lw-migration upload', $last['result']['cloud'][0]['message'] );
	}

	/**
	 * The host key notice flow over REST: unknown key (nothing sent), another key refused, the shown key trusted.
	 */
	public function test_an_unknown_sftp_host_key_is_confirmed_over_rest_before_any_login(): void {
		self::need( 'sftp' );
		$id     = CloudServers::addUnchecked( 'a', 'sftp-password', 'Notice', self::folder( 'notice' ) );
		$client = new RestClient( 'a' );
		$rest   = new CheckedRest( $client, CloudServers::secrets() );
		$client->start();
		try {
			$auth = $client->login( 'admin' );
			$from = count( self::sshLog( 'lwm-e2e-sftp' ) );

			$test = $rest->call( 'POST', '/destinations/' . $id . '/test', $auth );

			$this->assertSame( 422, $test['status'], $test['body'] );
			$this->assertSame( 'host_key_unknown', $test['json']['data']['reason'] ?? null, $test['body'] );
			$this->assertSame( getenv( 'LWM_E2E_SFTP_FP' ), $test['json']['data']['hostKey'] ?? null, 'The key ssh-keygen -lf prints.' );
			$this->assertSame( 'ssh-ed25519', $test['json']['data']['hostKeyType'] ?? null );
			self::assertNoLogin( self::sshLogAfter( 'lwm-e2e-sftp', $from ) );

			$other = $rest->call( 'POST', '/destinations/' . $id . '/trust', $auth, [], [ 'hostKey' => 'SHA256:' . str_repeat( 'A', 43 ) ] );
			$this->assertSame( 409, $other['status'], $other['body'] );
			$this->assertSame( 'lw_migration_host_key_differs', $other['json']['code'] ?? null );
			$this->assertSame( getenv( 'LWM_E2E_SFTP_FP' ), $other['json']['data']['hostKey'] ?? null );

			$trust = $rest->call( 'POST', '/destinations/' . $id . '/trust', $auth, [], [ 'hostKey' => (string) getenv( 'LWM_E2E_SFTP_FP' ) ] );
			$this->assertSame( 200, $trust['status'], $trust['body'] );
			$this->assertSame( 200, $rest->call( 'POST', '/destinations/' . $id . '/test', $auth )['status'] );
		} finally {
			$client->stop();
		}
		$settings = json_decode( WpSites::ok( 'a', 'eval', 'echo wp_json_encode( ( new \LightweightPlugins\Migration\Storage\Destinations\ProfileRepository() )->find( ' . var_export( $id, true ) . ' )->settings );' ), true );
		$this->assertSame( getenv( 'LWM_E2E_SFTP_FP' ), $settings['hostKey'] );
		$this->assertSame( 'ssh-ed25519', $settings['hostKeyType'] );
	}

	/**
	 * Review Focus 3: the same name now answered by another server (another host key).
	 */
	public function test_sftp_changed_host_key_stops_the_destination_before_any_login(): void {
		self::need( 'sftp' );
		self::need( 'sftp2' );
		$id         = CloudServers::add( 'a', 'sftp-password', 'Moved', self::folder( 'moved' ) );
		[ , $port2 ] = CloudServers::address( 'sftp2' );
		WpSites::ok( 'a', 'eval', '$r = new \LightweightPlugins\Migration\Storage\Destinations\ProfileRepository(); $p = $r->find( ' . var_export( $id, true ) . ' ); $p->settings["port"] = ' . (int) $port2 . '; $r->save( $p );' );
		$from = count( self::sshLog( 'lwm-e2e-sftp2' ) );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'export', '--to=' . $id );

		$this->assertSame( 0, $code, 'The backup itself is made; only the upload fails.' );
		$this->assertStringContainsString( 'changed', $out . $err );
		self::assertNoLogin( self::sshLogAfter( 'lwm-e2e-sftp2', $from ) );

		$client = new RestClient( 'a' );
		$client->start();
		try {
			$test = ( new CheckedRest( $client, CloudServers::secrets() ) )->call( 'POST', '/destinations/' . $id . '/test', $client->login( 'admin' ) );
		} finally {
			$client->stop();
		}
		$this->assertSame( 422, $test['status'], $test['body'] );
		$this->assertSame( 'host_key_changed', $test['json']['data']['reason'] ?? null );
		$this->assertSame( getenv( 'LWM_E2E_SFTP_FP' ), $test['json']['data']['expected'] ?? null, 'The confirmed key is shown next to the new one.' );
		$this->assertNotSame( getenv( 'LWM_E2E_SFTP_FP' ), $test['json']['data']['hostKey'] ?? null, 'Another server, another key.' );
		foreach ( self::sshLog( 'lwm-e2e-sftp2' ) as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/\blwm\b|Accepted|Failed/', $line, 'No credential ever reached the other server.' );
		}
	}

	/**
	 * A part file cut short at the server: the next part is never written past its end.
	 */
	public function test_an_sftp_part_file_cut_short_at_the_server_starts_the_upload_over(): void {
		self::need( 'sftp' );
		$folder          = self::folder( 'cut' );
		$id              = CloudServers::add( 'a', 'sftp', 'Cut', $folder );
		[ $name, $path ] = self::export( 'a' );
		file_put_contents( WpSites::path( 'a' ) . '/wp-content/lwm-e2e-kill-after-cloud-part', $id );
		$before = [];

		[ $last, $died ] = CloudServers::uploadOverRest(
			$name,
			$id,
			static function () use ( &$before, $id, $folder, $name ): void {
				$before = self::parts( $id );
				[ $code, , $err ] = CloudServers::docker( 'exec', 'lwm-e2e-sftp', 'truncate', '-s', '1000', '/config/' . $folder . '/' . $name . '.part' );
				self::assertSame( 0, $code, $err );
			}
		);

		$this->assertSame( 1, $died );
		$this->assertSame( 'done', $last['status'], (string) json_encode( $last ) );
		$this->assertSame( 'ok', $last['result']['cloud'][0]['status'], (string) json_encode( $last ) );
		$this->assertNotSame( [], $before );
		self::assertStartedOver( self::parts( $id ), count( $before ), filesize( $path ) );
		$this->assertSame( CloudServers::local( $path ), CloudServers::onDisk( 'sftp', $folder, $name ), 'No hole, no stray bytes.' );
		$this->assertSame( [ $name ], CloudServers::filesOnDisk( 'sftp', $folder ) );
	}

	/**
	 * Every vsftpd announces 10.255.255.1 for passive transfers; only a client that ignores it gets data.
	 */
	public function test_ftp_data_connections_ignore_the_announced_passive_address(): void {
		self::need( 'ftp' );
		[ , $port ] = CloudServers::address( 'ftp' );
		$ftp        = ftp_connect( '127.0.0.1', (int) $port, 3 );
		$this->assertNotFalse( $ftp );
		$this->assertTrue( ftp_login( $ftp, (string) getenv( 'LWM_E2E_FTP_USER' ), (string) getenv( 'LWM_E2E_FTP_SECRET' ) ) );
		ftp_pasv( $ftp, true );
		$this->assertFalse( @ftp_nlist( $ftp, '.' ), 'Following the announced address goes nowhere.' ); // phpcs:ignore -- the warning is the expected failure.
		ftp_close( $ftp );

		$id = CloudServers::add( 'a', 'ftp', 'Passive', self::folder( 'pasv' ) );

		$this->assertStringContainsString( 'works', WpSites::ok( 'a', 'lw-migration', 'destination', 'test', $id ) );
		$this->assertFalse( gethostbynamel( 'lwm-ftp.invalid' ), 'Only the plugin\'s checked address reaches the server.' );
	}

	/**
	 * The vsftpd that refuses REST takes uploads by appending (CloudProtocolsE2ETest),
	 * but a backup could never be read back from it: the connection test says so.
	 */
	public function test_an_ftp_server_refusing_rest_fails_the_connection_test(): void {
		self::need( 'ftp-appe' );
		$folder = self::folder( 'norest' );
		$id     = CloudServers::addUnchecked( 'a', 'ftp-appe', 'No REST', $folder );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertNotSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'cannot send part of a file (it refuses REST), so backups there cannot be restored', $out . $err );
		$this->assertSame( [], CloudServers::filesOnDisk( 'ftp-appe', $folder ), 'The test file is deleted all the same.' );
	}

	public function test_plain_ftp_to_a_server_that_wants_tls_says_so(): void {
		self::need( 'ftps' );
		$id = CloudServers::addUnchecked( 'a', 'ftps', 'Plain', self::folder( 'plain' ), [ '--security=ftp' ] );

		[ $code, $tested, $testErr ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'choose FTPS', $tested . $testErr );
	}

	/**
	 * Issue #5: the FTPS server's self-signed certificate stops the first
	 * check before any login with the fingerprint openssl prints; once
	 * trusted, uploads work, and a certificate other than the confirmed one
	 * stops the destination again before any login.
	 */
	public function test_an_unchecked_ftps_certificate_is_confirmed_before_any_login(): void {
		self::need( 'ftps' );
		$id        = CloudServers::addUnchecked( 'a', 'ftps', 'Cert', self::folder( 'cert' ) );
		[ , $old ] = CloudServers::docker( 'logs', 'lwm-e2e-ftps' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertNotSame( 0, $code, $out . $err );
		$this->assertStringContainsString( '--trust-certificate=' . getenv( 'LWM_E2E_FTPS_FP' ), $out . $err, 'The fingerprint openssl x509 -fingerprint -sha256 prints.' );
		[ , $now ] = CloudServers::docker( 'logs', 'lwm-e2e-ftps' );
		$this->assertStringNotContainsString( 'LOGIN', substr( $now, strlen( $old ) ), 'No user name or password reached it.' );

		$this->assertSame( getenv( 'LWM_E2E_FTPS_FP' ), CloudServers::trust( 'a', $id ) );
		WpSites::ok( 'a', 'lw-migration', 'destination', 'test', $id );

		WpSites::ok( 'a', 'eval', '$r = new \\LightweightPlugins\\Migration\\Storage\\Destinations\\ProfileRepository(); $p = $r->find( ' . var_export( $id, true ) . ' ); $p->settings["certFingerprint"] = str_repeat( "AB:", 31 ) . "AB"; $r->save( $p );' ); // Another certificate than the server's.
		[ , $old ]            = CloudServers::docker( 'logs', 'lwm-e2e-ftps' );
		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );
		$this->assertNotSame( 0, $code, $out . $err );
		$this->assertStringContainsString( 'changed', $out . $err );
		[ , $now ] = CloudServers::docker( 'logs', 'lwm-e2e-ftps' );
		$this->assertStringNotContainsString( 'LOGIN', substr( $now, strlen( $old ) ), 'Another certificate than the confirmed one gets no login.' );
	}

	/**
	 * FTPS chosen, a server without TLS: refused before the user name or password is sent.
	 */
	public function test_ftps_never_falls_back_to_a_plain_login(): void {
		self::need( 'ftp' );
		$id       = CloudServers::addUnchecked( 'a', 'ftp', 'No TLS', self::folder( 'notls' ), [ '--security=ftps' ] );
		[ , $old ] = CloudServers::docker( 'logs', 'lwm-e2e-ftp' );

		[ $code, $out, $err ] = WpSites::wp( 'a', 'lw-migration', 'destination', 'test', $id );

		$this->assertNotSame( 0, $code, $out . $err );
		[ , $now ] = CloudServers::docker( 'logs', 'lwm-e2e-ftp' );
		$new       = substr( $now, strlen( $old ) );
		$this->assertStringContainsString( 'CONNECT', $new, 'The server was reached.' );
		$this->assertStringNotContainsString( 'LOGIN', $new, 'No user name or password reached it.' );
		foreach ( CloudServers::secrets() as $secret ) {
			$this->assertStringNotContainsString( $secret, $out . $err );
		}
	}

	/**
	 * The secrets rule (plan GC): password, passphrase and key never leave the vault.
	 */
	public function test_secrets_stay_out_of_answers_jobs_and_the_archive(): void {
		self::need( 'sftp' );
		$ids = [ CloudServers::add( 'a', 'sftp', 'Secret key', self::folder( 'secret' ) ) ];
		foreach ( [ 'ftps', 'nextcloud' ] as $kind ) {
			if ( CloudServers::available( $kind ) ) {
				$ids[] = CloudServers::add( 'a', $kind, 'Secret ' . $kind, self::folder( 'secret' ) );
			}
		}
		$client = new RestClient( 'a' );
		$rest   = new CheckedRest( $client, CloudServers::secrets() );
		$client->start();
		try {
			$auth = $client->login( 'admin' );
			$this->assertSame( 200, $rest->call( 'GET', '/destinations', $auth )['status'] );
			foreach ( $ids as $id ) {
				foreach ( [ [ 'POST', '/test' ], [ 'GET', '/files' ] ] as [ $method, $route ] ) {
					$answer = $rest->call( $method, '/destinations/' . $id . $route, $auth );
					$this->assertSame( 200, $answer['status'], $method . ' ' . $route . ': ' . $answer['body'] );
				}
			}
		} finally {
			$client->stop();
		}
		[ , $path ] = self::export( 'a', '--to=' . implode( ',', $ids ) );

		foreach ( glob( WpSites::storage( 'a' ) . '/jobs/*/{job.json,job.log}', GLOB_BRACE ) ?: [] as $file ) {
			foreach ( CloudServers::secrets() as $secret ) {
				$this->assertStringNotContainsString( $secret, (string) file_get_contents( $file ), $file );
			}
		}
		$reader = new Reader( $path );
		$entry  = $reader->find( 'database.sql' );
		$this->assertNotNull( $entry );
		$sql = $reader->readContents( $entry, 64 * 1048576 );
		$reader->close();
		$this->assertStringNotContainsString( 'lw_migration_destination', $sql );
		foreach ( CloudServers::secrets() as $secret ) {
			$this->assertStringNotContainsString( $secret, $sql );
		}
	}
}
