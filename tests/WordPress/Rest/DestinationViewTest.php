<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use LightweightPlugins\Migration\Rest\DestinationView;
use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpType;
use LightweightPlugins\Migration\Storage\Destinations\Profile;
use LightweightPlugins\Migration\Tests\Support\FakeKeyedType;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Preflight P-I14: FTPS encrypts without checking the server's certificate;
 * the view carries that as a flag the card compares, never as text.
 */
final class DestinationViewTest extends MonkeyTestCase {

	private static function ftp( string $security ): Profile {
		return new Profile( 'abcd1234', 'ftp', 'F', [ 'host' => '10.0.0.5', 'port' => 21, 'security' => $security, 'passive' => true, 'user' => 'u', 'folder' => '' ] );
	}

	/**
	 * Issue #5: FTPS checks or pins the certificate, so only plain FTP is
	 * flagged; the confirmed fingerprint is shown with the settings.
	 */
	public function test_plain_ftp_is_flagged_unencrypted_and_ftps_shows_its_confirmed_certificate(): void {
		$type    = new FtpType( null, static fn( string $function ): bool => true );
		$profile = self::ftp( 'ftps' );
		$pin     = '14:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';

		$profile->settings['certFingerprint'] = $pin;
		$ftps                                 = DestinationView::of( $profile, $type, [ 'password' ], 0 );
		$plain                                = DestinationView::of( self::ftp( 'ftp' ), $type, [ 'password' ], 0 );

		$this->assertSame( [ false, true ], [ $ftps['insecure'], $plain['insecure'] ] );
		$this->assertArrayNotHasKey( 'certificateUnchecked', $ftps );
		$this->assertSame( $pin, $ftps['settings']['certFingerprint'] );
		$this->assertSame( [ 'password' => [ 'set' => true ] ], $ftps['secrets'] );
	}

	public function test_other_types_and_an_unknown_type_are_not_flagged(): void {
		$profile = new Profile( 'abcd1234', 'keyed', 'K', [] );

		$this->assertFalse( DestinationView::of( $profile, new FakeKeyedType(), [], 0 )['insecure'] );
		$this->assertFalse( DestinationView::of( $profile, null, [], 0 )['insecure'] );
	}
}
