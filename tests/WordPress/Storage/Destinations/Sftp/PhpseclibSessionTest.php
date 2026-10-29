<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\Sftp\PhpseclibSession;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\Vendor\phpseclib3\Net\SSH2;

/**
 * P-M8: the confirmed key's kind is asked for first. The session itself
 * talks to OpenSSH only in the Docker harness (Task 11).
 */
final class PhpseclibSessionTest extends MonkeyTestCase {

	public function test_the_confirmed_key_type_is_offered_first_and_nothing_is_left_out(): void {
		$all = SSH2::getSupportedHostKeyAlgorithms();

		$ecdsa = PhpseclibSession::preferredHostKeys( 'ecdsa-sha2-nistp256' );
		$this->assertSame( 'ecdsa-sha2-nistp256', $ecdsa[0] );
		$this->assertEqualsCanonicalizing( $all, $ecdsa );

		$this->assertSame( [ 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa' ], array_slice( PhpseclibSession::preferredHostKeys( 'rsa-sha2-256' ), 0, 3 ), 'Every signature of an RSA key comes first.' );
		$this->assertEqualsCanonicalizing( $all, PhpseclibSession::preferredHostKeys( 'ssh-rsa' ), 'A user RSA key can still sign with SHA-2.' );
	}

	public function test_no_or_an_unknown_key_type_keeps_the_library_order(): void {
		$this->assertSame( [], PhpseclibSession::preferredHostKeys( '' ) );
		$this->assertSame( [], PhpseclibSession::preferredHostKeys( 'ssh-ed448' ) );
	}
}
