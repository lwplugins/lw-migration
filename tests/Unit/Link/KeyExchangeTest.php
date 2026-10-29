<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Link;

use LightweightPlugins\Migration\Link\KeyExchange;
use PHPUnit\Framework\TestCase;

final class KeyExchangeTest extends TestCase {

	private const ID = '0123456789abcdef';

	private const NONCE = '00112233445566778899aabbccddeeff';

	public function test_both_sides_derive_the_same_secret_and_proof(): void {
		$this->assertTrue( KeyExchange::available() );
		$a = KeyExchange::keypair();
		$b = KeyExchange::keypair();
		$this->assertSame( 32, strlen( $a['public'] ) );
		$onA = KeyExchange::secret( $a['secret'], $b['public'], self::ID, $a['public'], $b['public'], self::NONCE );
		$onB = KeyExchange::secret( $b['secret'], $a['public'], self::ID, $a['public'], $b['public'], self::NONCE );
		$this->assertSame( 32, strlen( $onA ) );
		$this->assertSame( $onA, $onB );
		$this->assertSame( KeyExchange::proof( $onA, self::ID, $a['public'], $b['public'] ), KeyExchange::proof( $onB, self::ID, $a['public'], $b['public'] ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}\z/', KeyExchange::proof( $onA, self::ID, $a['public'], $b['public'] ) );
	}

	public function test_the_secret_depends_on_the_nonce_the_link_and_the_keys(): void {
		$a    = KeyExchange::keypair();
		$b    = KeyExchange::keypair();
		$base = KeyExchange::secret( $a['secret'], $b['public'], self::ID, $a['public'], $b['public'], self::NONCE );
		$this->assertNotSame( $base, KeyExchange::secret( $a['secret'], $b['public'], self::ID, $a['public'], $b['public'], str_repeat( 'f', 32 ) ) );
		$this->assertNotSame( $base, KeyExchange::secret( $a['secret'], $b['public'], 'fedcba9876543210', $a['public'], $b['public'], self::NONCE ) );
		$this->assertNotSame( $base, KeyExchange::secret( $a['secret'], $b['public'], self::ID, $b['public'], $a['public'], self::NONCE ) );
		$c = KeyExchange::keypair();
		$this->assertNotSame( $base, KeyExchange::secret( $a['secret'], $c['public'], self::ID, $a['public'], $c['public'], self::NONCE ) );
	}

	public function test_the_code_is_found_and_proven_without_its_nonce(): void {
		$a      = KeyExchange::keypair();
		$lookup = KeyExchange::lookup( self::NONCE );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}\z/', $lookup );
		$this->assertStringNotContainsString( self::NONCE, $lookup );
		$this->assertNotSame( hash( 'sha256', self::NONCE ), $lookup, 'Domain-separated from a plain hash.' );
		$mac = KeyExchange::pairMac( self::NONCE, $a['public'], 'https://a.example', 'Site A' );
		$this->assertSame( $mac, KeyExchange::pairMac( self::NONCE, $a['public'], 'https://a.example', 'Site A' ) );
		$this->assertNotSame( $mac, KeyExchange::pairMac( str_repeat( 'f', 32 ), $a['public'], 'https://a.example', 'Site A' ) );
		$this->assertNotSame( $mac, KeyExchange::pairMac( self::NONCE, $a['public'], 'https://evil.example', 'Site A' ) );
		$this->assertNotSame( $mac, KeyExchange::pairMac( self::NONCE, KeyExchange::keypair()['public'], 'https://a.example', 'Site A' ) );
	}

	public function test_degenerate_or_malformed_keys_are_refused(): void {
		$a = KeyExchange::keypair();
		foreach ( [ str_repeat( "\0", 32 ), 'short' ] as $peer ) {
			try {
				KeyExchange::secret( $a['secret'], $peer, self::ID, $a['public'], $peer, self::NONCE );
				$this->fail( 'Refused.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'The key exchange failed.', $e->getMessage() );
			}
		}
	}

	public function test_the_secret_matches_a_known_answer_and_needs_the_private_key(): void {
		$a  = str_repeat( "\x01", 32 );
		$b  = str_repeat( "\x02", 32 );
		$pa = sodium_crypto_scalarmult_base( $a );
		$pb = sodium_crypto_scalarmult_base( $b );
		$s  = KeyExchange::secret( $a, $pb, self::ID, $pa, $pb, self::NONCE );
		$this->assertSame( 'e934a4ac25bd1099c191239cd00de92c49ab56b4ad6b31942154f8479adb4671', bin2hex( $s ) );
		$this->assertSame( $s, KeyExchange::secret( $b, $pa, self::ID, $pa, $pb, self::NONCE ) );
		$this->assertSame( '8c77df1fff3a3d67d8b3e685a3c0e2f3a7deca7af7bb034427098e4aebf54719', KeyExchange::proof( $s, self::ID, $pa, $pb ) );
		$other = KeyExchange::secret( str_repeat( "\x03", 32 ), $pb, self::ID, $pa, $pb, self::NONCE );
		$this->assertNotSame( $s, $other, 'Same public inputs, another private key.' );
	}

	public function test_the_proof_and_the_mac_cover_every_field(): void {
		$a = KeyExchange::keypair();
		$b = KeyExchange::keypair();
		$s = str_repeat( "\x07", 32 );
		$p = KeyExchange::proof( $s, self::ID, $a['public'], $b['public'] );
		$this->assertNotSame( $p, KeyExchange::proof( $s, 'fedcba9876543210', $a['public'], $b['public'] ) );
		$this->assertNotSame( $p, KeyExchange::proof( $s, self::ID, $b['public'], $a['public'] ) );
		$this->assertNotSame( $p, KeyExchange::proof( $s, self::ID, $a['public'], $a['public'] ) );
		$this->assertNotSame( $p, KeyExchange::proof( str_repeat( "\x08", 32 ), self::ID, $a['public'], $b['public'] ) );
		$m = KeyExchange::pairMac( self::NONCE, $a['public'], 'https://a.example', 'Site A' );
		$this->assertNotSame( $m, KeyExchange::pairMac( self::NONCE, $a['public'], 'https://a.example', 'Site B' ) );
		$this->assertNotSame(
			KeyExchange::pairMac( self::NONCE, $a['public'], "https://a.example\nx", 'y' ),
			KeyExchange::pairMac( self::NONCE, $a['public'], 'https://a.example', "x\ny" ),
			'Fields cannot be shifted across their boundary.'
		);
		$this->expectException( \InvalidArgumentException::class );
		KeyExchange::pairMac( self::NONCE, 'short', 'https://a.example', 'Site A' );
	}
}
