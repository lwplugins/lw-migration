<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificateProbe;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The probe never logs in, so a server that will not start TLS is not told
 * as a wrong password. A one-shot FTP greeter on the loopback (a child PHP
 * process) answers AUTH TLS with 500.
 */
final class CertificateProbeTest extends MonkeyTestCase {

	private const GREETER = '$s = stream_socket_server( "tcp://127.0.0.1:0" ); echo substr( strrchr( stream_socket_get_name( $s, false ), ":" ), 1 ), "\n"; $c = stream_socket_accept( $s, 10 ); fwrite( $c, "220 ready\r\n" ); fgets( $c ); fwrite( $c, "500 AUTH not understood\r\n" ); fgets( $c ); fclose( $c );';

	public function test_a_server_that_refuses_auth_tls_is_not_told_as_a_wrong_password(): void {
		$process = proc_open( [ PHP_BINARY, '-r', self::GREETER ], [ 1 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $process );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		try {
			CertificateProbe::fetch( '127.0.0.1', $port, 'ftp.example.com', 5, 'ftps://ftp.example.com:21' );
			$this->fail( 'Refused.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::AUTH, $e->reason );
			$this->assertStringContainsString( 'ftps://ftp.example.com:21', $e->getMessage() );
			$this->assertStringContainsString( 'FTPS', $e->getMessage() );
			$this->assertStringNotContainsString( 'password', $e->getMessage() );
			$this->assertStringNotContainsString( 'user name', $e->getMessage() );
		} finally {
			fclose( $pipes[1] );
			proc_close( $process );
		}
	}
}
