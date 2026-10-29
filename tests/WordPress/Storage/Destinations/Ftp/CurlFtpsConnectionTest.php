<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\CurlFtpsConnection;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The connection itself talks to a server through cURL (checked against
 * vsftpd with a self-signed and a CA-signed certificate, and in the cloud
 * E2E tests); what it builds and decides on its own is tested here.
 */
final class CurlFtpsConnectionTest extends MonkeyTestCase {

	/** Nothing listens on port 1: a request that went out would end as a TransientFailure, never as INVALID. */
	private const NOWHERE = [
		'host'    => '127.0.0.1',
		'name'    => '127.0.0.1',
		'port'    => 1,
		'passive' => true,
		'user'    => 'u',
		'folder'  => '',
		'pin'     => '',
	];

	/** @var array<int, int> Options the fake setopt was given, in order. */
	private array $set = [];

	protected function setUp(): void {
		parent::setUp();
		if ( ! CurlFtpsConnection::supported() ) {
			$this->markTestSkipped( 'This PHP has no cURL that can pin FTPS.' );
		}
	}

	/**
	 * A setopt that records every option and refuses one.
	 *
	 * @param int $refused The option cURL refuses.
	 */
	private function refusing( int $refused ): \Closure {
		return function ( $handle, int $option, $value ) use ( $refused ): bool {
			$this->set[] = $option;
			return $refused !== $option && curl_setopt( $handle, $option, $value );
		};
	}

	/**
	 * Review I1: an option cURL refuses stops the request before any network
	 * I/O, and nothing after it (the login, the pin) is set.
	 */
	public function test_a_refused_security_option_stops_before_any_request(): void {
		try {
			CurlFtpsConnection::open( self::NOWHERE, 'pw', 'ftps://127.0.0.1:1', 2, $this->refusing( CURLOPT_SSL_VERIFYPEER ) );
			$this->fail( 'Sent with an option missing.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, CurlFtpsConnection::unsupported() ], [ $e->reason, $e->getMessage() ] );
		}
		$this->assertSame( CURLOPT_SSL_VERIFYPEER, end( $this->set ), 'The first refusal ends the setup.' );
		$this->assertNotContains( CURLOPT_PASSWORD, $this->set, 'The certificate options go first; the login never follows a refused one.' );
	}

	public function test_a_refused_request_option_stops_before_any_request(): void {
		try {
			CurlFtpsConnection::open( self::NOWHERE, 'pw', 'ftps://127.0.0.1:1', 2, $this->refusing( CURLOPT_HEADERFUNCTION ) );
			$this->fail( 'Sent with an option missing.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
		}
	}

	/**
	 * Review I1: a TLS backend that cannot pin refuses the pin at setopt
	 * time; FTPS is then unsupported, before the certificate is even read.
	 */
	public function test_a_curl_that_cannot_pin_does_not_offer_ftps(): void {
		$this->assertFalse( CurlFtpsConnection::supported( $this->refusing( CURLOPT_PINNEDPUBLICKEY ) ) );
		try {
			CurlFtpsConnection::open( [ 'pin' => str_repeat( 'AB:', 31 ) . 'AB' ] + self::NOWHERE, 'pw', 'ftps://127.0.0.1:1', 2, $this->refusing( CURLOPT_PINNEDPUBLICKEY ) );
			$this->fail( 'Opened without a pin.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::INVALID, CurlFtpsConnection::unsupported() ], [ $e->reason, $e->getMessage() ] );
		}
	}

	public function test_the_folder_url_is_relative_to_the_login_folder_or_from_the_root(): void {
		$this->assertSame( 'ftp://ftp.example.com:21/', CurlFtpsConnection::url( 'ftp.example.com', 21, '' ) );
		$this->assertSame( 'ftp://ftp.example.com:21/backups/my%20site/', CurlFtpsConnection::url( 'ftp.example.com', 21, 'backups/my site' ) );
		$this->assertSame( 'ftp://ftp.example.com:2121/%2Fsrv/b%23k/', CurlFtpsConnection::url( 'ftp.example.com', 2121, '/srv/b#k' ), 'An absolute folder starts at the root (%2F); every segment is encoded.' );
		$this->assertSame( 'ftp://[fd00::5]:21/a/', CurlFtpsConnection::url( 'fd00::5', 21, 'a' ) );
	}

	public function test_the_unsupported_reason_recommends_sftp(): void {
		$this->assertStringContainsString( 'Choose SFTP', CurlFtpsConnection::unsupported() );
	}
}
