<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\CurlFtpsFailure;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #5: what a cURL error of an FTPS connection means (the codes are
 * cURL's own, as seen against vsftpd: 7 refused, 9 folder, 13 PASV, 21
 * quote, 31 REST, 60 certificate, 64 AUTH TLS, 67 login, 78 no file, 90
 * pinned key).
 */
final class CurlFtpsFailureTest extends MonkeyTestCase {

	private const WHERE = 'ftps://ftp.example.com:21';

	public function test_an_unreachable_server_or_a_broken_transport_passes(): void {
		foreach ( [ 7, 8, 12, 18, 28, 35, 52, 55, 56 ] as $errno ) {
			$failure = CurlFtpsFailure::connection( $errno, [], self::WHERE );
			$this->assertInstanceOf( TransientFailure::class, $failure, (string) $errno );
			$this->assertSame( TransientFailure::NETWORK, $failure->reason );
		}
		$this->assertSame( self::WHERE . ' cannot be reached.', CurlFtpsFailure::connection( 7, [], self::WHERE )->getMessage() );
	}

	public function test_a_refused_ftps_passive_mode_or_a_curl_without_ftp_stops_the_destination(): void {
		$this->assertSame( PermanentFailure::AUTH, CurlFtpsFailure::connection( 64, [ '530 Please login with USER and PASS.' ], self::WHERE )->reason );
		$this->assertStringContainsString( 'FTPS', CurlFtpsFailure::connection( 64, [], self::WHERE )->getMessage() );
		$this->assertSame( PermanentFailure::INVALID, CurlFtpsFailure::connection( 13, [], self::WHERE )->reason );
		foreach ( [ 1, 4, 48 ] as $errno ) {
			$this->assertStringContainsString( 'Choose SFTP', CurlFtpsFailure::connection( $errno, [], self::WHERE )->getMessage() );
		}
	}

	public function test_a_failure_of_one_file_or_an_unverified_certificate_is_not_answered_here(): void {
		foreach ( [ 9, 19, 21, 25, 31, 36, 78 ] as $errno ) {
			$this->assertNull( CurlFtpsFailure::connection( $errno, [], self::WHERE ), (string) $errno );
		}
		$this->assertSame( [ true, true, false ], [ CurlFtpsFailure::unverified( 60 ), CurlFtpsFailure::unverified( 90 ), CurlFtpsFailure::unverified( 35 ) ] );
	}

	public function test_a_login_turned_away_for_now_passes_and_a_refusal_is_auth_without_the_servers_text(): void {
		foreach ( [ '421 There are too many connections from your internet address.', '530 Sorry, the maximum number of clients (5) from your host are already connected.', '421 Service temporarily unavailable.' ] as $reply ) {
			$failure = CurlFtpsFailure::connection( 67, [ '220 Welcome', '234 Proceed with negotiation.', $reply ], self::WHERE );
			$this->assertInstanceOf( TransientFailure::class, $failure, $reply );
			$this->assertSame( TransientFailure::NETWORK, $failure->reason );
		}
		$refused = CurlFtpsFailure::connection( 67, [ '220 Welcome', '234 Proceed with negotiation.', '331 Please specify the password.', '530 Login incorrect.' ], self::WHERE );
		$this->assertInstanceOf( PermanentFailure::class, $refused );
		$this->assertSame( [ PermanentFailure::AUTH, self::WHERE . ' refused the user name or the password.' ], [ $refused->reason, $refused->getMessage() ] );
	}
}
