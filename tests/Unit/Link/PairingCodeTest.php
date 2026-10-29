<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Link;

use LightweightPlugins\Migration\Link\PairingCode;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PairingCodeTest extends TestCase {

	private const NONCE = '00112233445566778899aabbccddeeff';

	public function test_a_code_round_trips_also_with_whitespace_from_copying(): void {
		foreach ( [ 'https://b.example', 'https://b.example/wp', 'http://127.0.0.1:8093', 'https://b.example:8443/wp/s%C5%B1-x_y~z', 'https://[::1]:8080', 'http://b-1.example.co/' ] as $address ) {
			$code = PairingCode::encode( $address, self::NONCE );
			$this->assertMatchesRegularExpression( '/^lwm1\.[A-Za-z0-9_-]+\.[a-f0-9]{32}\z/', $code );
			$this->assertSame( [ 'address' => $address, 'nonce' => self::NONCE ], PairingCode::decode( " \n" . chunk_split( $code, 20, "\n" ) . "\t" ) );
		}
	}

	public function test_anything_else_is_refused(): void {
		$good = PairingCode::encode( 'https://b.example', self::NONCE );
		$bad  = [
			'',
			'lwm2' . substr( $good, 4 ),
			substr( $good, 0, -1 ),
			$good . 'a',
			strtoupper( $good ),
			str_replace( '.', ':', $good ),
			$good . '.x',
			'lwm1..' . self::NONCE,
			'lwm1.' . rtrim( strtr( base64_encode( 'ftp://b.example' ), '+/', '-_' ), '=' ) . '.' . self::NONCE,
			'lwm1.' . rtrim( strtr( base64_encode( 'https://b.example/?x=1' ), '+/', '-_' ), '=' ) . '.' . self::NONCE,
			'lwm1.' . rtrim( strtr( base64_encode( "https://b.example/\0" ), '+/', '-_' ), '=' ) . '.' . self::NONCE,
			'lwm1.' . rtrim( strtr( base64_encode( 'https:///nohost' ), '+/', '-_' ), '=' ) . '.' . self::NONCE,
			'lwm1.' . rtrim( strtr( base64_encode( 'https://b.example/' . str_repeat( 'x', 2048 ) ), '+/', '-_' ), '=' ) . '.' . self::NONCE,
		];
		foreach ( $bad as $code ) {
			try {
				PairingCode::decode( $code );
				$this->fail( 'Refused: ' . $code );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		PairingCode::encode( 'https://b.example', 'ABC' );
	}

	public function test_addresses_are_allow_listed(): void {
		$bad = [
			'https://good.example@evil.example',
			'https://user:pw@b.example',
			'https://evil.example\\@good.example',
			'https://b.example\\evil',
			'https://b.example:0',
			'https://b.example:65536',
			'https://b.example:',
			'https://bé.example',
			'https://b.example/ű',
			'HTTPS://b.example',
			'https://-b.example',
			'https://b..example',
			'https://b_x.example',
			'https://[zz]',
			'https://b.example/%zz',
			'https://b.example/a"b',
			'https://b.example/a<b',
			'https://[1:2]',
			'https://' . self::host( 254 ),
		];
		foreach ( $bad as $address ) {
			$code = 'lwm1.' . rtrim( strtr( base64_encode( $address ), '+/', '-_' ), '=' ) . '.' . self::NONCE;
			try {
				PairingCode::decode( $code );
				$this->fail( 'Refused: ' . $address );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
			try {
				PairingCode::encode( $address, self::NONCE );
				$this->fail( 'Refused on encode: ' . $address );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_is_address_shares_the_allow_list(): void {
		$this->assertTrue( PairingCode::isAddress( 'https://b.example/wp' ) );
		$this->assertTrue( PairingCode::isAddress( 'http://[::1]:8080' ) );
		$this->assertTrue( PairingCode::isAddress( 'https://' . self::host( 253 ) ) );
		foreach ( [ 'https://[1:2]', 'https://' . self::host( 254 ), 'ftp://b.example', 'https://bé.example', "https://b.example/\n", '' ] as $address ) {
			$this->assertFalse( PairingCode::isAddress( $address ), $address );
		}
	}

	public function test_an_international_address_becomes_ascii(): void {
		$cases = [
			'https://bé.example/wű'         => 'https://xn--b-bga.example/w%C5%B1',
			'https://Bé.example:8443/x'     => 'https://xn--b-bga.example:8443/x',
			'http://b.example/sz%C3%A9p'    => 'http://b.example/sz%C3%A9p',
			'https://b.example'             => 'https://b.example',
			'https://b.example/a b'         => 'https://b.example/a b',
		];
		foreach ( $cases as $address => $ascii ) {
			$this->assertSame( $ascii, PairingCode::ascii( $address ), $address );
		}
		$this->assertTrue( PairingCode::isAddress( PairingCode::ascii( 'https://bé.example/wű' ) ) );
		$this->assertSame( 'not an address é', PairingCode::ascii( 'not an address é' ) );
	}

	#[RunInSeparateProcess]
	public function test_without_intl_the_bundled_encoder_converts_the_lower_case_host(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/idna-fallback.php';
		$this->assertSame( 'https://xn--b-bga.example:8443/w%C5%B1', PairingCode::ascii( 'https://BÉ.example:8443/wű' ) );
		$this->assertSame( [ 'bé.example' ], \WpOrg\Requests\IdnaEncoder::$asked, 'Lower-cased before the fallback.' );
		$this->assertSame( 'https://ü.example', PairingCode::ascii( 'https://ü.example' ), 'An encoder failure leaves the host as it is.' );
		$this->assertFalse( PairingCode::isAddress( PairingCode::ascii( 'https://ü.example' ) ) );
	}

	/**
	 * A host name of exactly $bytes bytes in labels of at most 63.
	 */
	private static function host( int $bytes ): string {
		$labels = [];
		while ( $bytes > 0 ) {
			$take     = min( 63, $bytes );
			$labels[] = str_repeat( 'a', $take );
			$bytes   -= $take + 1;
		}
		$host = implode( '.', $labels );

		return substr( $host, 0, -1 ) . 'b';
	}

	public function test_only_canonical_base64url_is_accepted(): void {
		$good = PairingCode::encode( 'https://b.example', self::NONCE );
		$this->assertStringContainsString( 'aHR0cHM6Ly9iLmV4YW1wbGU.', $good );
		$this->expectException( \InvalidArgumentException::class );
		PairingCode::decode( str_replace( 'aHR0cHM6Ly9iLmV4YW1wbGU.', 'aHR0cHM6Ly9iLmV4YW1wbGV.', $good ) );
	}
}
