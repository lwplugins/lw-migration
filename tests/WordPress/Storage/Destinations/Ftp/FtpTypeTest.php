<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpConnection;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\FakeFtp;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FtpTypeTest extends MonkeyTestCase {

	private const NAME = 'example-com-20261012-030000-123.wpress';

	/** @var array<string, array<int, string>> What the names resolve to now. */
	private array $dns = [];

	/** @var array<int, array<int, mixed>> host, tls, password, where of every connection opened. */
	private array $opened = [];

	/** @var array<int, array<string, mixed>> The full $at of every connection opened. */
	private array $at = [];

	private const PIN = '14:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';

	protected function setUp(): void {
		parent::setUp();
		$this->dns = [
			'h'               => [ '93.184.216.34' ],
			'ftp.example.com' => [ '93.184.216.34' ],
		];
		AddressGuard::useResolver( fn( string $host ): array => $this->dns[ $host ] ?? [] );
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/** A server with ext-ftp and a cURL that can check FTPS, whatever the test machine has. */
	private static function type( ?\Closure $open = null ): FtpType {
		return new FtpType( $open, static fn( string $function ): bool => true );
	}

	/** A type whose connections go to $ftp, recording what each was opened with. */
	private function recording( FakeFtp $ftp ): FtpType {
		return self::type(
			function ( array $at, string $password, string $where ) use ( $ftp ): FtpConnection {
				$this->opened[] = [ $at['host'], $at['tls'], $password, $where ];
				$this->at[]     = $at;
				return $ftp;
			}
		);
	}

	private static function profile( FtpType $type, array $raw ): Profile {
		return new Profile( 'abcd1234', 'ftp', 'F', $type->settings( $raw ) );
	}

	public function test_the_form_asks_server_port_encryption_passive_user_password_and_folder(): void {
		$fields = self::type()->fields();

		$this->assertSame( [ 'host', 'port', 'security', 'passive', 'user', 'password', 'folder', 'certFingerprint' ], array_map( static fn( $f ): string => $f->key, $fields ) );
		$this->assertSame( [ 'ftps', 'ftp' ], array_keys( $fields[2]->options ) );
		$this->assertSame( [ 21, 'ftps', true ], [ $fields[1]->default, $fields[2]->default, $fields[3]->default ] );
		$this->assertTrue( $fields[5]->isSecret() );
		$this->assertStringContainsString( 'checks the server\'s certificate', $fields[2]->help, 'Issue #5: FTPS checks the certificate.' );
		$this->assertStringNotContainsString( 'does not check', $fields[2]->help );
		$this->assertFalse( $fields[7]->isSecret(), 'A fingerprint is not a secret.' );
		$this->assertSame( [ true, false ], [ $fields[7]->visible( [ 'security' => 'ftps' ] ), $fields[7]->visible( [ 'security' => 'ftp' ] ) ], 'Only FTPS has a certificate.' );
	}

	public function test_settings_default_to_ftps_passive_port_21_and_keep_an_absolute_folder(): void {
		$s = self::type()->settings( [ 'host' => ' ftp.example.com ', 'user' => 'me', 'folder' => '/backups//site/' ] );

		$this->assertSame(
			[
				'host'     => 'ftp.example.com',
				'port'     => 21,
				'security' => 'ftps',
				'passive'  => true,
				'user'            => 'me',
				'folder'          => '/backups/site',
				'certFingerprint' => '',
			],
			$s
		);
		$this->assertSame( 2121, self::type()->settings( [ 'host' => 'h', 'user' => 'u', 'port' => '2121' ] )['port'], 'The command line gives the port as text.' );
		$this->assertSame( 'fd00::5', self::type()->settings( [ 'host' => '[FD00::5]', 'user' => 'u' ] )['host'] );
	}

	public function test_refusals_name_their_field(): void {
		$cases = [
			[ 'host', [ 'host' => 'ftp://h', 'user' => 'u' ] ],
			[ 'host', [ 'host' => '2130706433', 'user' => 'u' ] ],
			[ 'host', [ 'host' => '169.254.169.254', 'user' => 'u' ] ],
			[ 'host', [ 'user' => 'u' ] ],
			[ 'port', [ 'host' => 'h', 'user' => 'u', 'port' => '70000' ] ],
			[ 'security', [ 'host' => 'h', 'user' => 'u', 'security' => 'sftp' ] ],
			[ 'passive', [ 'host' => 'h', 'user' => 'u', 'passive' => 'yes' ] ],
			[ 'user', [ 'host' => 'h', 'user' => '' ] ],
			[ 'user', [ 'host' => 'h', 'user' => "u\r\nPASS x" ] ],
			[ 'folder', [ 'host' => 'h', 'user' => 'u', 'folder' => '../up' ] ],
			[ 'certFingerprint', [ 'host' => 'h', 'user' => 'u', 'certFingerprint' => 'SHA256:abc' ] ],
			[ 'certFingerprint', [ 'host' => 'h', 'user' => 'u', 'certFingerprint' => substr( self::PIN, 0, -3 ) ] ],
		];
		foreach ( $cases as [ $field, $raw ] ) {
			try {
				self::type()->settings( $raw );
				$this->fail( $field . ' ' . var_export( $raw, true ) );
			} catch ( FieldException $e ) {
				$this->assertSame( $field, $e->field );
				$this->assertStringNotContainsString( '2130706433', $e->getMessage() );
			}
		}
	}

	public function test_without_ext_ftp_or_checked_ftps_the_type_is_unavailable_and_each_choice_needs_its_own(): void {
		$this->assertNotNull( ( new FtpType( null, static fn( string $f ): bool => false ) )->unavailable() );
		$this->assertNull( ( new FtpType( null, static fn( string $f ): bool => true ) )->unavailable() );
		$this->assertNull( ( new FtpType( null, static fn( string $f ): bool => 'ftps' === $f ) )->unavailable(), 'FTPS through cURL needs no ext-ftp.' );
		$noTls = new FtpType( null, static fn( string $f ): bool => 'ftps' !== $f );
		$this->assertSame( 'ftp', $noTls->settings( [ 'host' => 'h', 'user' => 'u', 'security' => 'ftp' ] )['security'], 'Plain FTP is the admin\'s choice, never a fallback.' );
		try {
			$noTls->settings( [ 'host' => 'h', 'user' => 'u' ] );
			$this->fail( 'FTPS without a cURL that checks it.' );
		} catch ( FieldException $e ) {
			$this->assertSame( 'security', $e->field );
			$this->assertStringContainsString( 'Choose SFTP', $e->getMessage() );
		}
		try {
			( new FtpType( null, static fn( string $f ): bool => 'ftps' === $f ) )->settings( [ 'host' => 'h', 'user' => 'u', 'security' => 'ftp' ] );
			$this->fail( 'Plain FTP without ext-ftp.' );
		} catch ( FieldException $e ) {
			$this->assertSame( 'security', $e->field );
		}
	}

	/**
	 * Issue #5: a confirmed certificate is stored upper-case, only for FTPS,
	 * and belongs to the server (a move forgets it with the password).
	 */
	public function test_a_certificate_fingerprint_is_kept_for_ftps_only_and_belongs_to_the_server(): void {
		$type = self::type();

		$this->assertSame( self::PIN, $type->settings( [ 'host' => 'h', 'user' => 'u', 'certFingerprint' => ' ' . strtolower( self::PIN ) . ' ' ] )['certFingerprint'] );
		$this->assertArrayNotHasKey( 'certFingerprint', $type->settings( [ 'host' => 'h', 'user' => 'u', 'security' => 'ftp', 'certFingerprint' => self::PIN ] ) );
		$this->assertSame( [ 'password', 'certFingerprint' ], $type->authorityFields() );
		$this->assertSame( 'certFingerprint', $type->fingerprintField() );
		$this->assertSame( [ true, false ], [ $type->isFingerprint( self::PIN ), $type->isFingerprint( strtolower( self::PIN ) ) ] );
	}

	/**
	 * Issue #5: the connection gets the stored name (for the certificate's
	 * host-name check and the address pin) and the confirmed fingerprint.
	 */
	public function test_an_ftps_connection_gets_the_name_and_the_confirmed_fingerprint(): void {
		$type = $this->recording( new FakeFtp() );
		$type->make( self::profile( $type, [ 'host' => 'ftp.example.com', 'user' => 'u', 'certFingerprint' => self::PIN ] ), [ 'password' => 'pw' ] )->list();
		$type->make( self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] ), [ 'password' => 'pw' ] )->list();

		$this->assertSame( [ '93.184.216.34', 'ftp.example.com', true, self::PIN ], [ $this->at[0]['host'], $this->at[0]['name'], $this->at[0]['tls'], $this->at[0]['pin'] ] );
		$this->assertSame( '', $this->at[1]['pin'], 'None confirmed: the certificate authorities decide.' );
	}

	/**
	 * D37: plain FTP is unencrypted, FTPS is not.
	 */
	public function test_plain_ftp_is_flagged_unencrypted(): void {
		$type  = self::type();
		$plain = self::profile( $type, [ 'host' => 'h', 'user' => 'u', 'security' => 'ftp' ] );
		$ftps  = self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] );

		$this->assertSame( [ true, false ], [ $type->insecure( $plain ), $type->insecure( $ftps ) ] );
		$this->assertSame( 'ftps://h', $type->describe( $ftps ) );
		$this->assertSame( '/b · ftp://[fd00::5]', $type->describe( self::profile( $type, [ 'host' => 'fd00::5', 'user' => 'u', 'security' => 'ftp', 'folder' => '/b' ] ) ) );
	}

	/**
	 * The whole path: type → FolderDestination → FtpFolder → a server without
	 * REST; the connection is opened with the checked address (P-C2).
	 */
	public function test_a_made_destination_uploads_through_a_connection_to_the_checked_address(): void {
		$ftp       = new FakeFtp();
		$ftp->rest = false;
		$type      = $this->recording( $ftp );
		$dest      = $type->make( self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] ), [ 'password' => 'pw' ] );

		$s = $dest->startUpload( self::NAME, 6 );
		$s = $dest->uploadChunk( $s, 'abcdef' );
		$dest->finishUpload( $s );

		$this->assertSame( 'abcdef', $ftp->files[ self::NAME ] );
		$this->assertSame( [ '93.184.216.34', true, 'pw', 'ftps://h:21' ], $this->opened[0] );
		$this->assertSame( [ '93.184.216.34' ], array_unique( array_column( $this->opened, 0 ) ), 'Never the name.' );
		$this->assertTrue( $ftp->closed );
	}

	public function test_the_connection_test_fails_on_a_server_that_cannot_send_part_of_a_file(): void {
		$ftp           = new FakeFtp();
		$ftp->restRead = false;
		$type          = $this->recording( $ftp );
		$dest          = $type->make( self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] ), [ 'password' => 'pw' ] );

		try {
			$dest->test();
			$this->fail( 'A server without REST cannot give a backup back.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertStringContainsString( 'cannot be restored', $e->getMessage() );
		}
		$this->assertSame( [], $ftp->files, 'The test file is deleted all the same.' );
	}

	public function test_an_ipv6_server_is_reached_by_its_address_and_shown_in_brackets(): void {
		$type = $this->recording( new FakeFtp() );
		$type->make( self::profile( $type, [ 'host' => '[fd00::5]', 'user' => 'u', 'port' => 990, 'security' => 'ftp' ] ), [ 'password' => 'pw' ] )->list();

		$this->assertSame( [ 'fd00::5', false, 'pw', 'ftp://[fd00::5]:990' ], $this->opened[0] );
	}

	/**
	 * P-C2 / H1: the name is checked again before every connection; a name
	 * that now points at a refused address is never connected to.
	 */
	public function test_a_name_that_now_resolves_to_a_refused_address_is_never_connected(): void {
		$type = $this->recording( new FakeFtp() );
		$dest = $type->make( self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] ), [ 'password' => 'pw' ] );

		$this->dns['h'] = [ '169.254.169.254' ];
		try {
			$dest->list();
			$this->fail( 'Connected to a refused address.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, 'The address ftps://h:21 points to a network address that is not allowed for storage.' ], [ $e->reason, $e->getMessage() ] );
		}
		$this->dns['h'] = [];
		try {
			$dest->startUpload( self::NAME, 6 );
			$this->fail( 'Connected without an address.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( TransientFailure::NETWORK, $e->reason );
		}
		$this->assertSame( [], $this->opened );
	}

	public function test_a_missing_password_or_broken_settings_stop_before_any_connection(): void {
		$type = $this->recording( new FakeFtp() );
		try {
			$type->make( self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] ), [] );
			$this->fail( 'No password.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::REAUTH, $e->reason );
		}
		try {
			$type->make( new Profile( 'abcd1234', 'ftp', 'F', [ 'host' => '0x7f.0.0.1', 'user' => 'u' ] ), [ 'password' => 'pw' ] )->list();
			$this->fail( 'A stored host the rules refuse.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
		$this->assertSame( [], $this->opened );
	}

	/**
	 * Final review m3: a stored name that now resolves to a refused range
	 * gets the address text, not the form's prompt.
	 */
	public function test_a_stored_name_now_resolving_to_a_refused_address_says_so_at_make(): void {
		$type    = $this->recording( new FakeFtp() );
		$profile = self::profile( $type, [ 'host' => 'h', 'user' => 'u' ] );

		$this->dns['h'] = [ '169.254.169.254' ];
		try {
			$type->make( $profile, [ 'password' => 'pw' ] );
			$this->fail( 'A refused address.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, 'The address ftps://h:21 points to a network address that is not allowed for storage.' ], [ $e->reason, $e->getMessage() ] );
		}
		$this->assertSame( [], $this->opened );
	}
}
