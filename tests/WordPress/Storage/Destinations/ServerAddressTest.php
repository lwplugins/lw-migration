<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\AddressGuard;
use LightweightPlugins\Migration\Storage\Destinations\FieldException;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\ServerAddress;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Preflight P-C2 / H5: the save-time host and port rules every destination
 * type shares (S3's custom endpoint, WebDAV, FTP, SFTP).
 */
final class ServerAddressTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		AddressGuard::useResolver(
			static fn( string $host ): array => 'metadata.google.internal' === $host ? [ '169.254.169.254' ] : ( 'nowhere.example.test' === $host ? [] : [ '93.184.216.34' ] )
		);
	}

	protected function tearDown(): void {
		AddressGuard::useResolver( null );
		parent::tearDown();
	}

	public function test_a_host_is_lower_case_and_an_ipv6_address_loses_its_brackets(): void {
		$this->assertSame( 'cloud.example', ServerAddress::host( ' Cloud.Example ', 'host' ) );
		$this->assertSame( '10.0.0.5', ServerAddress::host( '10.0.0.5', 'host' ) );
		$this->assertSame( 'fd00::5', ServerAddress::host( '[FD00::5]', 'host' ) );
		$this->assertSame( '::1', ServerAddress::host( '::1', 'host' ) );
		$this->assertSame( 'nowhere.example.test', ServerAddress::host( 'nowhere.example.test', 'host' ), 'A name that does not resolve now is checked on every connection.' );
	}

	public function test_refuses_a_bad_shape_a_disguised_address_or_a_refused_one_naming_the_field(): void {
		$bad = [
			'',
			'ftp://h',
			'a_b.example',
			'exämple.com',
			"cloud.example\r\nx",
			'cloud example',
			'-a.example',
			'[cloud.example]',
			'[1:2]',
			'fe80::1%25en0',
			str_repeat( 'a', 254 ),
			'2130706433',
			'0x7f.0.0.1',
			'0251.0376.0251.0376',
			'1.1',
			'127.000.0.1',
			'169.254.169.254',
			'[::ffff:a9fe:a9fe]',
			'fd00:ec2::254',
			'metadata.google.internal',
			null,
			42,
		];
		foreach ( $bad as $raw ) {
			try {
				ServerAddress::host( $raw, 'host' );
				$this->fail( 'Accepted: ' . var_export( $raw, true ) );
			} catch ( FieldException $e ) {
				$this->assertSame( 'host', $e->field );
			}
		}
	}

	public function test_a_refusal_carries_the_callers_message_and_never_the_value(): void {
		try {
			ServerAddress::host( '169.254.169.254', 'url', 'Enter the address.' );
			$this->fail( 'Refused.' );
		} catch ( FieldException $e ) {
			$this->assertSame( [ 'url', 'Enter the address.' ], [ $e->field, $e->getMessage() ] );
		}
		try {
			ServerAddress::host( '2130706433', 'host' );
			$this->fail( 'Refused.' );
		} catch ( FieldException $e ) {
			$this->assertStringNotContainsString( '2130706433', $e->getMessage() );
		}
	}

	public function test_a_port_is_a_whole_number_from_1_to_65535_or_the_default(): void {
		$this->assertSame( 21, ServerAddress::port( null, 21, 'port' ) );
		$this->assertSame( 21, ServerAddress::port( '', 21, 'port' ) );
		$this->assertSame( 2121, ServerAddress::port( ' 2121 ', 21, 'port' ), 'The command line gives the port as text.' );
		$this->assertSame( 65535, ServerAddress::port( 65535, 21, 'port' ) );
		foreach ( [ 0, -1, 65536, '70000', '21a', '2.5', 2.5, true, '0x15' ] as $raw ) {
			try {
				ServerAddress::port( $raw, 21, 'port' );
				$this->fail( 'Accepted: ' . var_export( $raw, true ) );
			} catch ( FieldException $e ) {
				$this->assertSame( 'port', $e->field );
			}
		}
	}

	public function test_addressable_refuses_disguised_and_refused_addresses_and_allows_private_ones(): void {
		foreach ( [ '127.0.0.1', '10.0.0.5', '[::1]', 'fd00::5', 'cloud.example', 'nowhere.example.test' ] as $ok ) {
			$this->assertTrue( ServerAddress::addressable( $ok ), $ok );
		}
		foreach ( [ '2852039166', '0xa9fea9fe', '0251.0376.0251.0376', '169.254.169.254', '[fd00:ec2::254]', '100.100.100.200', 'metadata.google.internal' ] as $bad ) {
			$this->assertFalse( ServerAddress::addressable( $bad ), $bad );
		}
	}

	/**
	 * Preflight P-C2 / H1–H2: a connection goes to the address that was
	 * checked, never to a name a second lookup could answer differently.
	 */
	public function test_connect_to_answers_the_first_checked_address_and_checks_written_out_ones(): void {
		$asked = [];
		$guard = new AddressGuard(
			static function ( string $host ) use ( &$asked ): array {
				$asked[] = $host;
				return [ '93.184.216.34', '2606:2800:220:1::1' ];
			}
		);

		$this->assertSame( '93.184.216.34', ServerAddress::connectTo( 'ftp.example.com', 'ftps://ftp.example.com:21', $guard ) );
		$this->assertSame( '10.0.0.5', ServerAddress::connectTo( '10.0.0.5', 'ftp://10.0.0.5:21', $guard ) );
		$this->assertSame( 'fd00::5', ServerAddress::connectTo( 'fd00::5', 'ftp://[fd00::5]:21', $guard ) );
		$this->assertSame( [ 'ftp.example.com' ], $asked, 'An address written out is not looked up.' );
		$this->assertSame( '93.184.216.34', ServerAddress::connectTo( 'cloud.example', 'sftp://cloud.example:22', null ), 'Without a guard, the process-wide one.' );
	}

	public function test_connect_to_refuses_a_refused_address_with_the_storage_text(): void {
		foreach ( [ 'metadata.google.internal', '169.254.169.254', '::ffff:a9fe:a9fe', 'fd00:ec2::254' ] as $host ) {
			try {
				ServerAddress::connectTo( $host, 'ftp://h:21', null );
				$this->fail( 'Connected to ' . $host );
			} catch ( PermanentFailure $e ) {
				$this->assertSame( PermanentFailure::INVALID, $e->reason );
				$this->assertSame( 'The address ftp://h:21 points to a network address that is not allowed for storage.', $e->getMessage() );
			}
		}
		$mixed = new AddressGuard( static fn(): array => [ '93.184.216.34', '169.254.169.254' ] );
		$this->expectException( PermanentFailure::class );
		ServerAddress::connectTo( 'mixed.example', 'ftp://mixed.example:21', $mixed );
	}

	public function test_connect_to_a_name_that_does_not_resolve_is_a_passing_failure(): void {
		try {
			ServerAddress::connectTo( 'nowhere.example.test', 'sftp://nowhere.example.test:22', null );
			$this->fail( 'No address.' );
		} catch ( TransientFailure $e ) {
			$this->assertSame( [ TransientFailure::NETWORK, 'The address sftp://nowhere.example.test:22 cannot be looked up right now.' ], [ $e->reason, $e->getMessage() ] );
		}
	}
}
