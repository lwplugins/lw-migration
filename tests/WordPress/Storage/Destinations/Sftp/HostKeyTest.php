<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Sftp;

use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Storage\Destinations\Sftp\HostKey;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class HostKeyTest extends MonkeyTestCase {

	/** OpenSSH 10.3 in linuxserver/openssh-server; `ssh-keyscan | ssh-keygen -lf -` printed the fingerprint (2026-10-12). */
	private const SERVER_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEeYqvUuZQShB2y3F23bERBADpvDwXkOx7PbFWLqgZkG';

	private const FINGERPRINT = 'SHA256:4U+iyVJ67eRjXO5juHU2wtdIAKv3vfEN0q53GZZr/a4';

	public function test_the_fingerprint_is_the_one_ssh_keygen_prints(): void {
		$this->assertSame( self::FINGERPRINT, HostKey::fingerprint( self::SERVER_KEY ) );
		$this->assertSame( 'ssh-ed25519', HostKey::type( self::SERVER_KEY ) );
		$this->assertTrue( HostKey::isFingerprint( self::FINGERPRINT ) );
		$this->assertFalse( HostKey::isFingerprint( 'MD5:aa:bb' ) );
		$this->assertNull( HostKey::fingerprint( 'ssh-ed25519 !!!' ) );
	}

	public function test_the_confirmed_key_passes(): void {
		HostKey::check( self::FINGERPRINT, self::SERVER_KEY, 'sftp://example.com:22' );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_unconfirmed_key_stops_with_the_fingerprint_to_confirm(): void {
		try {
			HostKey::check( '', self::SERVER_KEY, 'sftp://example.com:22' );
			$this->fail( 'Not confirmed yet.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::HOST_KEY_UNKNOWN, $e->reason );
			$this->assertSame( [ 'hostKey' => self::FINGERPRINT, 'hostKeyType' => 'ssh-ed25519' ], $e->data );
			$this->assertStringContainsString( self::FINGERPRINT, $e->getMessage() );
		}
	}

	/**
	 * Review Focus 3.
	 */
	public function test_a_changed_key_stops_with_both_fingerprints(): void {
		$old = 'SHA256:' . str_repeat( 'A', 43 );
		try {
			HostKey::check( $old, self::SERVER_KEY, 'sftp://example.com:22' );
			$this->fail( 'The key changed.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::HOST_KEY_CHANGED, $e->reason );
			$this->assertSame( self::FINGERPRINT, $e->data['hostKey'] );
			$this->assertSame( $old, $e->data['expected'] );
		}
	}

	public function test_an_unreadable_server_key_is_invalid(): void {
		try {
			HostKey::check( self::FINGERPRINT, 'ssh-ed25519', 'sftp://example.com:22' );
			$this->fail( 'Unreadable.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( PermanentFailure::INVALID, $e->reason );
			$this->assertSame( [], $e->data );
		}
	}

	/** The PHP twin of hostKeyCommand() in src/destinations/destinations.js. */
	public function test_the_command_names_the_key_file_of_the_shown_type(): void {
		$this->assertSame( 'ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub', HostKey::command( 'ssh-ed25519' ) );
		$this->assertSame( 'ssh-keygen -lf /etc/ssh/ssh_host_ecdsa_key.pub', HostKey::command( 'ecdsa-sha2-nistp256' ) );
		$this->assertSame( 'ssh-keygen -lf /etc/ssh/ssh_host_rsa_key.pub', HostKey::command( 'rsa-sha2-512' ) );
		$this->assertSame( 'ssh-keygen -lf /etc/ssh/ssh_host_rsa_key.pub', HostKey::command( 'ssh-rsa' ) );
		$this->assertSame( 'ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub', HostKey::command( '' ) );
	}
}
