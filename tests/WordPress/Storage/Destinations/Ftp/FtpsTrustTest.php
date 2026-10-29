<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations\Ftp;

use LightweightPlugins\Migration\Storage\Destinations\Ftp\FtpsTrust;
use LightweightPlugins\Migration\Storage\Destinations\PermanentFailure;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * Issue #5, review I1: the certificate options never fail open.
 */
final class FtpsTrustTest extends MonkeyTestCase {

	private const PIN = '14:B2:20:50:8B:BB:3C:51:E0:6E:5C:20:59:B9:F0:51:AE:12:34:9E:EF:9E:B3:8F:F9:5F:B1:44:73:0D:F5:90';

	private static function probe( string $fingerprint ): \Closure {
		return static fn(): array => [
			'fingerprint' => $fingerprint,
			'publicKey'   => 'sha256//key=',
		];
	}

	public function test_without_a_confirmed_certificate_the_ca_list_and_the_name_are_checked(): void {
		$options = FtpsTrust::options( '', static fn(): array => [], 'w' );

		$this->assertSame( [ true, 2 ], [ $options[ CURLOPT_SSL_VERIFYPEER ], $options[ CURLOPT_SSL_VERIFYHOST ] ] );
		$this->assertArrayNotHasKey( CURLOPT_PINNEDPUBLICKEY, $options );
	}

	public function test_the_pin_is_set_before_the_ca_check_goes_off(): void {
		$options = FtpsTrust::options( self::PIN, self::probe( self::PIN ), 'w' );

		$this->assertSame( [ CURLOPT_PINNEDPUBLICKEY, CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST ], array_keys( $options ) );
		$this->assertSame( 'sha256//key=', $options[ CURLOPT_PINNEDPUBLICKEY ] );
	}

	public function test_another_certificate_than_the_confirmed_one_gives_no_options(): void {
		$this->expectException( PermanentFailure::class );
		FtpsTrust::options( self::PIN, self::probe( 'AA' . substr( self::PIN, 2 ) ), 'w' );
	}

	public function test_apply_stops_at_the_first_refused_option(): void {
		$set    = [];
		$setopt = static function ( $handle, int $option, $value ) use ( &$set ): bool {
			$set[] = $option;
			return 2 !== $option;
		};

		$this->assertFalse( FtpsTrust::apply( null, [ 1 => 'a', 2 => 'b', 3 => 'c' ], $setopt ) );
		$this->assertSame( [ 1, 2 ], $set, 'Nothing after the refused one is set.' );
		$this->assertTrue( FtpsTrust::apply( null, [ 1 => 'a', 3 => 'c' ], $setopt ) );
	}

	public function test_a_backend_that_refuses_the_pin_cannot_pin(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'No cURL.' );
		}
		$this->assertFalse( FtpsTrust::canPin( static fn( $handle, int $option, $value ): bool => false ) );
		$this->assertTrue( FtpsTrust::canPin( static fn( $handle, int $option, $value ): bool => true ) );
	}
}
