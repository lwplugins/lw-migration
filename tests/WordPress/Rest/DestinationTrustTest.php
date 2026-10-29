<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\DestinationsApi;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\DestinationRegistry;
use LightweightPlugins\Migration\Storage\Destinations\Destinations;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificatePin;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpConnection;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Storage\Destinations\ProfileRepository;
use LightweightPlugins\Migration\Storage\Destinations\SecretVault;
use LightweightPlugins\Migration\Tests\Support\FakeFtp;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #5: an FTPS certificate no certificate authority vouches for goes
 * through the same confirm-once flow as an SFTP host key (plan D31). The
 * fake server checks the confirmed fingerprint the way CurlFtpsConnection
 * does (CertificatePin::check() before the login) and counts the logins.
 */
final class DestinationTrustTest extends MonkeyTestCase {

	private const SERVER = '14:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';

	private const OTHER = 'AA:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';

	/** The certificate the server shows now. */
	private string $shows = self::SERVER;

	/** Connections that got past the certificate to the login. */
	private int $logins = 0;

	private FakeFtp $ftp;

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install();
		FakeWpdb::install();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		AddressGuard::useResolver( static fn( string $host ): array => [ 'ftp.example.com' => [ '93.184.216.34' ], 'ftp2.example.com' => [ '93.184.216.35' ] ][ $host ] ?? [] );
		$this->ftp = new FakeFtp();
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	/**
	 * A stored FTPS profile aaaa1111 (no confirmed certificate unless given) and the API over it.
	 *
	 * @param array<string, mixed> $settings Settings over the defaults.
	 */
	private function api( array $settings = [] ): DestinationsApi {
		$type         = new FtpType(
			function ( array $at, string $password, string $where ): FtpConnection {
				CertificatePin::check( $at['pin'], $this->shows, $where );
				++$this->logins;
				return $this->ftp;
			},
			static fn( string $what ): bool => true
		);
		$destinations = new Destinations( new ProfileRepository( static fn(): int => 1000 ), new SecretVault(), new DestinationRegistry( [ $type ] ) );
		$destinations->profiles()->save( new Profile( 'aaaa1111', FtpType::KEY, 'Box', $type->settings( $settings + [ 'host' => 'ftp.example.com', 'user' => 'u' ] ) ) );
		$destinations->vault()->put( 'aaaa1111', [ 'password' => 'the-password' ] );

		return new DestinationsApi( $destinations, static fn(): array => [] );
	}

	private static function stored(): Profile {
		$profile = ( new ProfileRepository() )->find( 'aaaa1111' );
		self::assertNotNull( $profile );

		return $profile;
	}

	/**
	 * @return RestException What the call threw.
	 */
	private function refusal( \Closure $call ): RestException {
		try {
			$call();
		} catch ( RestException $e ) {
			return $e;
		}
		$this->fail( 'Accepted.' );
	}

	public function test_an_unchecked_certificate_stops_the_test_before_the_login_with_its_fingerprint(): void {
		$e = $this->refusal( fn() => $this->api()->test( 'aaaa1111' ) );

		$this->assertSame( [ 422, 'cert_unknown', self::SERVER ], [ $e->status, $e->data['reason'], $e->data['certFingerprint'] ] );
		$this->assertStringContainsString( self::SERVER, $e->getMessage() );
		$this->assertSame( 0, $this->logins, 'The password never reaches an unconfirmed server.' );
	}

	public function test_trust_stores_the_certificate_the_server_shows_and_tests_again(): void {
		$view = $this->api()->trust( 'aaaa1111', [ 'certFingerprint' => self::SERVER ] );

		$this->assertSame( [ self::SERVER, 'ok' ], [ $view['settings']['certFingerprint'], $view['status'] ] );
		$this->assertSame( self::SERVER, self::stored()->settings['certFingerprint'] );
		$this->assertArrayNotHasKey( 'hostKeyType', self::stored()->settings );
		$this->assertGreaterThan( 0, $this->logins, 'The test after the trust logged in (one connection per call).' );
	}

	/** Final review T4: a hand-typed or openssl-printed lower-case fingerprint is the same fingerprint. */
	public function test_trust_takes_a_lower_case_certificate_fingerprint_as_the_stored_upper_case_one(): void {
		$view = $this->api()->trust( 'aaaa1111', [ 'certFingerprint' => ' ' . strtolower( self::SERVER ) . ' ' ] );

		$this->assertSame( [ self::SERVER, 'ok' ], [ $view['settings']['certFingerprint'], $view['status'] ] );
		$this->assertSame( self::SERVER, self::stored()->settings['certFingerprint'] );
	}

	/**
	 * A fingerprint the server does not show now is never stored; the one it
	 * shows comes back so the page can show it as a changed certificate.
	 */
	public function test_trust_refuses_another_certificate_and_stores_nothing(): void {
		$api = $this->api();
		$e   = $this->refusal( fn() => $api->trust( 'aaaa1111', [ 'certFingerprint' => self::OTHER ] ) );

		$this->assertSame( [ 409, 'lw_migration_host_key_differs', [ 'certFingerprint' => self::SERVER ] ], [ $e->status, $e->errorCode, $e->data ] );
		$this->assertStringContainsString( 'certificate', $e->getMessage() );
		$this->assertSame( '', self::stored()->settings['certFingerprint'] );
		$this->assertSame( 0, $this->logins );
	}

	public function test_trust_refuses_a_malformed_fingerprint_or_the_host_key_field(): void {
		$api = $this->api();

		foreach ( [ 'SHA256:' . str_repeat( 'B', 43 ), 'ab', str_replace( ':', '', self::SERVER ) ] as $value ) {
			$e = $this->refusal( fn() => $api->trust( 'aaaa1111', [ 'certFingerprint' => $value ] ) );
			$this->assertSame( [ 400, 'certFingerprint' ], [ $e->status, $e->data['field'] ], $value );
		}
		$this->assertSame( 'hostKey', $this->refusal( fn() => $api->trust( 'aaaa1111', [ 'hostKey' => 'SHA256:' . str_repeat( 'B', 43 ) ] ) )->data['field'], 'An FTP destination has a certificate, not a host key.' );
		$this->assertSame( [], $this->ftp->calls, 'Refused before any connection.' );
	}

	public function test_a_changed_certificate_stops_the_pinned_destination_and_shows_both(): void {
		$api         = $this->api( [ 'certFingerprint' => self::SERVER ] );
		$this->shows = self::OTHER;
		$e           = $this->refusal( fn() => $api->test( 'aaaa1111' ) );

		$this->assertSame( [ 'cert_changed', self::OTHER, self::SERVER ], [ $e->data['reason'], $e->data['certFingerprint'], $e->data['expected'] ] );
		$this->assertSame( 0, $this->logins );

		$api->trust( 'aaaa1111', [ 'certFingerprint' => self::OTHER ] );
		$this->assertSame( self::OTHER, self::stored()->settings['certFingerprint'], 'The admin may confirm a renewed certificate.' );
	}

	/**
	 * Controller ruling (#5): a certificate confirmed for one server never
	 * vouches for another; the edit that moves the server forgets it.
	 */
	public function test_moving_the_server_forgets_the_confirmed_certificate(): void {
		$api = $this->api( [ 'certFingerprint' => self::SERVER ] );

		$api->update(
			'aaaa1111',
			[
				'settings' => [
					'host'            => 'ftp2.example.com',
					'user'            => 'u',
					'certFingerprint' => self::SERVER,
				],
				'secrets'  => [ 'password' => 'new-password' ],
			]
		);

		$this->assertArrayNotHasKey( 'certFingerprint', self::stored()->settings );
		$this->assertSame( 'cert_unknown', $this->refusal( fn() => $api->test( 'aaaa1111' ) )->data['reason'] );
	}
}
