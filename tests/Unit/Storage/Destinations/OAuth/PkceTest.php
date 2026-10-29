<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OAuth;

use LightweightPlugins\Migration\Storage\Destinations\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase {

	/** RFC 7636 Appendix B (https://www.rfc-editor.org/rfc/rfc7636.txt, lines 942-977). */
	public function test_the_challenge_matches_the_rfc_example(): void {
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_a_verifier_is_43_unreserved_characters_and_new_each_time(): void {
		$a = Pkce::verifier();
		$b = Pkce::verifier();

		$this->assertSame( 43, strlen( $a ) );
		$this->assertTrue( Pkce::isVerifier( $a ) );
		$this->assertNotSame( $a, $b );
	}

	public function test_is_verifier_refuses_short_long_and_foreign_characters(): void {
		$this->assertFalse( Pkce::isVerifier( str_repeat( 'a', 42 ) ) );
		$this->assertFalse( Pkce::isVerifier( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( Pkce::isVerifier( str_repeat( 'a', 42 ) . '+' ) );
		$this->assertTrue( Pkce::isVerifier( str_repeat( 'a', 40 ) . '-._~' ) );
	}

	public function test_decode_reverses_encode_and_refuses_garbage(): void {
		$bytes = random_bytes( 33 );

		$this->assertSame( $bytes, Pkce::decode( Pkce::encode( $bytes ) ) );
		$this->assertNull( Pkce::decode( 'not base64url!' ) );
	}
}
