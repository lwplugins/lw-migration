<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\CertificatePin;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #5: the fingerprint is what `openssl x509 -fingerprint -sha256`
 * prints; a certificate other than the confirmed one stops before the login.
 */
final class CertificatePinTest extends MonkeyTestCase {

	private const ABC = 'BA:78:16:BF:8F:01:CF:EA:41:41:40:DE:5D:AE:22:23:B0:03:61:A3:96:17:7A:9C:B4:10:FF:61:F2:00:15:AD';

	private const WHERE = 'ftps://ftp.example.com:21';

	public function test_the_fingerprint_is_the_sha256_of_the_der_in_upper_case_hex_pairs(): void {
		$this->assertSame( self::ABC, CertificatePin::fingerprint( 'abc' ) );
		$this->assertTrue( CertificatePin::isFingerprint( self::ABC ) );
	}

	public function test_a_typed_fingerprint_is_accepted_in_lower_case_and_nothing_else(): void {
		$this->assertSame( self::ABC, CertificatePin::normalize( ' ' . strtolower( self::ABC ) . "\n" ) );
		foreach ( [ '', 'SHA256:' . str_repeat( 'A', 43 ), str_replace( ':', '', self::ABC ), substr( self::ABC, 3 ), self::ABC . ':00', 'sha256 Fingerprint=' . self::ABC ] as $typed ) {
			$this->assertNull( CertificatePin::normalize( $typed ), $typed );
		}
	}

	public function test_the_confirmed_certificate_passes(): void {
		CertificatePin::check( self::ABC, self::ABC, self::WHERE );
		$this->addToAssertionCount( 1 );
	}

	public function test_an_unconfirmed_certificate_is_shown_to_confirm(): void {
		try {
			CertificatePin::check( '', self::ABC, self::WHERE );
			$this->fail( 'Not confirmed yet.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame( [ PermanentFailure::CERT_UNKNOWN, [ 'certFingerprint' => self::ABC ] ], [ $e->reason, $e->data ] );
			$this->assertStringContainsString( self::ABC, $e->getMessage() );
			$this->assertStringContainsString( self::WHERE, $e->getMessage() );
		}
	}

	public function test_another_certificate_than_the_confirmed_one_is_a_change_with_both(): void {
		$other = 'AA' . substr( self::ABC, 2 );
		try {
			CertificatePin::check( $other, self::ABC, self::WHERE );
			$this->fail( 'Another certificate.' );
		} catch ( PermanentFailure $e ) {
			$this->assertSame(
				[
					PermanentFailure::CERT_CHANGED,
					[
						'certFingerprint' => self::ABC,
						'expected'        => $other,
					],
				],
				[ $e->reason, $e->data ]
			);
			$this->assertTrue( in_array( $e->reason, PermanentFailure::UNCONFIRMED, true ) );
		}
	}
}
