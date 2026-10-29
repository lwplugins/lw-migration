<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\ExtFtpConnection;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The connection itself only forwards to ext-ftp (run against vsftpd in
 * Task 11); what it decides from ext-ftp's warnings is tested here. The
 * warning texts are ext-ftp's own (ext/ftp/ftp.c, PHP 8.5) or a server's
 * reply line without its code.
 */
final class ExtFtpConnectionTest extends MonkeyTestCase {

	private const WHERE = 'ftp://ftp.example.com:21';

	/**
	 * P-M12: a connection that breaks during the login passes; only the
	 * server's refusal stops the destination.
	 */
	public function test_a_broken_connection_during_login_is_a_passing_failure(): void {
		foreach ( [ [ 'Operation timed out' ], [ 'Connection reset by peer' ], [ 'Broken pipe' ] ] as $warnings ) {
			$failure = ExtFtpConnection::loginFailure( $warnings, self::WHERE );
			$this->assertInstanceOf( TransientFailure::class, $failure, implode( ' | ', $warnings ) );
			$this->assertSame( TransientFailure::NETWORK, $failure->reason );
		}
	}

	/**
	 * Final review m2: a server that turns a login away for now (421 "too
	 * many connections", vsftpd's and Pure-FTPd's limits) is retried.
	 */
	public function test_a_login_turned_away_for_now_is_a_passing_failure(): void {
		foreach ( [ 'There are too many connections from your internet address.', 'Sorry, the maximum number of clients (5) from your host are already connected.', 'Too many users, try again later.', 'Service temporarily unavailable.' ] as $reply ) {
			$failure = ExtFtpConnection::loginFailure( [ $reply ], self::WHERE );
			$this->assertInstanceOf( TransientFailure::class, $failure, $reply );
			$this->assertSame( TransientFailure::NETWORK, $failure->reason );
			$this->assertSame( self::WHERE . ' turned the login away for now (too many connections). Trying again later.', $failure->getMessage() );
		}
	}

	public function test_a_refused_login_is_auth_and_says_which_way_without_the_servers_text(): void {
		$plain = ExtFtpConnection::loginFailure( [ 'Login incorrect.' ], 'ftp://ftp.example.com:21' );
		$this->assertSame( [ PermanentFailure::AUTH, 'ftp://ftp.example.com:21 refused the user name or the password.' ], [ $plain->reason, $plain->getMessage() ] );

		$wantsTls = ExtFtpConnection::loginFailure( [ 'Non-anonymous sessions must use encryption.' ], 'ftp://ftp.example.com:21' );
		$this->assertSame( PermanentFailure::AUTH, $wantsTls->reason );
		$this->assertStringContainsString( 'choose FTPS', $wantsTls->getMessage() );

		$silent = ExtFtpConnection::loginFailure( [], self::WHERE );
		$this->assertSame( PermanentFailure::AUTH, $silent->reason, 'A refusal ext-ftp repeats no text of.' );
	}

	public function test_broken_tells_the_transport_from_a_servers_answer(): void {
		foreach ( [ 'Operation timed out', 'Connection timed out', 'Broken pipe', 'php_connect_nonb() failed: Connection refused (61)', 'SSL write failed', 'data_accept: SSL/TLS handshake failed', 'No route to host' ] as $transport ) {
			$this->assertTrue( ExtFtpConnection::broken( [ 'Transfer starting.', $transport ] ), $transport );
		}
		foreach ( [ 'Permission denied.', 'Could not create file.', 'Failed to open file.', 'Login incorrect.', 'Rename failed.' ] as $answer ) {
			$this->assertFalse( ExtFtpConnection::broken( [ $answer ] ), $answer );
		}
		$this->assertFalse( ExtFtpConnection::broken( [] ) );
	}

	/**
	 * A download that starts inside the file needs REST. ext-ftp's warning
	 * is the failed command's reply without its code, and may come from
	 * RETR as well (php-src ext/ftp/ftp.c ftp_getresp() strips the code,
	 * ftp_nb_get() sends REST then RETR), so the REST is asked again with
	 * ftp_raw() and only its own permanent refusal (5xx) counts. A passing
	 * answer (4xx) or an accepted REST (350) is never "refuses REST".
	 */
	public function test_only_a_permanent_answer_to_rest_itself_is_a_refusal(): void {
		foreach ( [ '426 Failure writing network stream.', '450 Requested file action not taken.', '451 Local error in processing.', '421 Too many connections.', '350 Restart position accepted (5).' ] as $line ) {
			$this->assertFalse( ExtFtpConnection::partRefused( [ $line ], 5 ), $line );
		}
		foreach ( [ '550 Permission denied.', '502 Command not implemented.', '500 Unknown command.', '504 Command not implemented for that parameter.' ] as $line ) {
			$this->assertTrue( ExtFtpConnection::partRefused( [ $line ], 5 ), $line );
		}
		$this->assertFalse( ExtFtpConnection::partRefused( [ '550 Permission denied.' ], 0 ), 'From the start no REST is sent.' );
		$this->assertFalse( ExtFtpConnection::partRefused( [], 5 ), 'No answer to judge by.' );
		$this->assertFalse( ExtFtpConnection::partRefused( [ 'Permission denied.' ], 5 ), 'A warning without its code proves nothing.' );

		$failure = ExtFtpConnection::partsRefused( self::WHERE );
		$this->assertSame( PermanentFailure::INVALID, $failure->reason );
		$this->assertStringContainsString( 'cannot be restored', $failure->getMessage() );
	}
}
