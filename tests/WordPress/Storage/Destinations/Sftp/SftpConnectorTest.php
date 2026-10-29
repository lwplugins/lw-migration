<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpConnector;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\SftpSession;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeSftp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\Vendor\phpseclib3\Crypt\EC;

final class SftpConnectorTest extends MonkeyTestCase {

	private const KEY = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';

	/** @var array<string, array<int, string>> What the names resolve to now. */
	private array $dns = [];

	/** @var array<int, array<int, mixed>> host, port, where, host key type of every session opened. */
	private array $opened = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dns = [ 'sftp.example.com' => [ '93.184.216.34' ] ];
		AddressGuard::useResolver( fn( string $host ): array => $this->dns[ $host ] ?? [] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed>  $settings Settings over the defaults.
	 * @param array<string, string> $secrets  Secrets.
	 */
	private function connector( FakeSftp $server, array $settings, array $secrets ): SftpConnector {
		$settings = array_merge(
			[
				'host'    => 'sftp.example.com',
				'port'    => 22,
				'user'    => 'me',
				'auth'    => 'password',
				'folder'  => 'backups',
				'hostKey' => self::KEY,
			],
			$settings
		);

		return new SftpConnector(
			$settings,
			$secrets,
			function ( string $host, int $port, int $timeout, string $where, string $hostKeyType ) use ( $server ): SftpSession {
				$this->opened[] = [ $host, $port, $where, $hostKeyType ];
				return $server;
			},
			'sftp://sftp.example.com:22'
		);
	}

	public function test_the_confirmed_host_key_and_a_password_log_in_and_open_the_folder(): void {
		$server = new FakeSftp();

		$this->connector( $server, [], [ 'password' => 'pw' ] )->connect();

		$this->assertSame( [ 'hostKey', 'login:me', 'hostKey', 'folder:backups' ], $server->calls );
		$this->assertFalse( $server->closed );
	}

	/**
	 * P-C2 / H1–H2: the session is opened to the checked address, never the
	 * name, and with the confirmed key's type preferred (P-M8).
	 */
	public function test_the_session_is_opened_to_the_checked_address_with_the_stored_key_type(): void {
		$this->connector( new FakeSftp(), [ 'hostKeyType' => 'ecdsa-sha2-nistp256' ], [ 'password' => 'pw' ] )->connect();

		$this->assertSame( [ [ '93.184.216.34', 22, 'sftp://sftp.example.com:22', 'ecdsa-sha2-nistp256' ] ], $this->opened );
	}

	public function test_a_name_that_resolves_to_a_refused_address_or_nowhere_is_never_opened(): void {
		$this->dns['sftp.example.com'] = [ '169.254.169.254' ];
		try {
			$this->connector( new FakeSftp(), [], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'Connected to a refused address.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, 'The address sftp://sftp.example.com:22 points to a network address that is not allowed for storage.' ], [ $e->reason, $e->getMessage() ] );
		}
		$this->dns['sftp.example.com'] = [];
		try {
			$this->connector( new FakeSftp(), [], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'Connected without an address.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
		$this->assertSame( [], $this->opened );
	}

	/**
	 * Review Focus 3: no credential reaches a server with another key.
	 */
	public function test_a_changed_host_key_stops_before_any_login(): void {
		$server = new FakeSftp();

		try {
			$this->connector( $server, [ 'hostKey' => 'SHA256:' . str_repeat( 'A', 43 ) ], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'Another server key.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::HOST_KEY_CHANGED, $e->reason );
			$this->assertSame( [ self::KEY, 'SHA256:' . str_repeat( 'A', 43 ) ], [ $e->data['hostKey'], $e->data['expected'] ] );
			$this->assertStringNotContainsString( 'pw', $e->getMessage() );
		}
		$this->assertSame( [ 'hostKey' ], $server->calls );
		$this->assertTrue( $server->closed );
	}

	/**
	 * Review C1: the key is compared again once logged in; a connection
	 * that shows another key by then is closed before any file is touched.
	 */
	public function test_a_host_key_that_changed_during_the_login_closes_the_session(): void {
		$server                = new FakeSftp();
		$server->keyAfterLogin = 'ssh-ed25519 ' . base64_encode( 'another key' );

		try {
			$this->connector( $server, [], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'Another key after the login.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::HOST_KEY_CHANGED, $e->reason );
		}
		$this->assertSame( [ 'hostKey', 'login:me', 'hostKey' ], $server->calls );
		$this->assertTrue( $server->closed );
	}

	public function test_an_unconfirmed_host_key_stops_before_any_login(): void {
		$server = new FakeSftp();

		try {
			$this->connector( $server, [ 'hostKey' => '' ], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'Not confirmed.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::HOST_KEY_UNKNOWN, $e->reason );
			$this->assertSame( [ self::KEY, 'ssh-ed25519' ], [ $e->data['hostKey'], $e->data['hostKeyType'] ] );
		}
		$this->assertSame( [ 'hostKey' ], $server->calls );
		$this->assertTrue( $server->closed );
	}

	public function test_a_key_with_its_passphrase_logs_in(): void {
		$key                      = EC::createKey( 'Ed25519' );
		$server                   = new FakeSftp();
		$server->authorizedKeys[] = $key->getPublicKey()->toString( 'OpenSSH' );

		$this->connector( $server, [ 'auth' => 'key' ], [ 'privateKey' => $key->withPassword( 'right' )->toString( 'OpenSSH', [ 'rounds' => 1 ] ), 'passphrase' => 'right' ] )->connect();

		$this->assertSame( [ 'hostKey', 'login:me', 'hostKey', 'folder:backups' ], $server->calls );
	}

	/**
	 * Review Focus 4: the passphrase is checked here, before the server sees anything but the key exchange.
	 */
	public function test_a_wrong_passphrase_is_reported_before_login(): void {
		$server = new FakeSftp();

		try {
			$this->connector( $server, [ 'auth' => 'key' ], [ 'privateKey' => EC::createKey( 'Ed25519' )->withPassword( 'right' )->toString( 'OpenSSH', [ 'rounds' => 1 ] ), 'passphrase' => 'wrong' ] )->connect();
			$this->fail( 'Wrong passphrase.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
		}
		$this->assertNotContains( 'login:me', $server->calls );
		$this->assertTrue( $server->closed );
	}

	public function test_a_refused_login_and_a_folder_that_cannot_be_made(): void {
		$refused = new FakeSftp();
		try {
			$this->connector( $refused, [], [ 'password' => 'nope' ] )->connect();
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
			$this->assertStringNotContainsString( 'nope', $e->getMessage() );
		}
		$this->assertTrue( $refused->closed );
		$server          = new FakeSftp();
		$server->refuses = [ 'ensureFolder' ];
		try {
			$this->connector( $server, [], [ 'password' => 'pw' ] )->connect();
			$this->fail( 'No folder.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::MISSING, $e->reason );
			$this->assertStringNotContainsString( 'backups', $e->getMessage() );
		}
		$this->assertTrue( $server->closed );
	}
}
