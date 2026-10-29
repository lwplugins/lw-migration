<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Link;

use LightweightPlugins\Migration\Link\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	private const LINK = '0123456789abcdef';

	private const NOW = 1790000000;

	private const ROUTE = '/lw-migration/v1/link/hello';

	private string $secret;

	protected function setUp(): void {
		$this->secret = str_repeat( "\x01", 32 );
	}

	/**
	 * @return array{link: string, time: int, nonce: string, signature: string}
	 */
	private function signed( int $at = self::NOW ): array {
		$parts = Signer::parse( Signer::headers( self::LINK, $this->secret, 'POST', self::ROUTE, '{}', $at ) );
		$this->assertNotNull( $parts );

		return $parts;
	}

	private function verifies( array $parts, string $method = 'POST', string $route = self::ROUTE, string $body = '{}', int $now = self::NOW, ?string $secret = null ): bool {
		return Signer::verify( $parts, $secret ?? $this->secret, $method, $route, $body, $now );
	}

	public function test_a_signed_request_verifies_whatever_the_case_of_its_method(): void {
		$this->assertTrue( $this->verifies( $this->signed() ) );
		$this->assertTrue( $this->verifies( $this->signed(), 'post' ) );
	}

	public function test_every_signed_part_counts(): void {
		$parts = $this->signed();
		$this->assertFalse( $this->verifies( $parts, 'GET' ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', '/lw-migration/v1/link/push' ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE, '{ }' ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW, str_repeat( "\x02", 32 ) ) );
		foreach ( [ 'link' => 'fedcba9876543210', 'nonce' => str_repeat( 'a', 32 ), 'time' => self::NOW + 1 ] as $key => $value ) {
			$this->assertFalse( $this->verifies( array_merge( $parts, [ $key => $value ] ) ), $key );
		}
	}

	public function test_the_window_is_five_minutes_both_ways(): void {
		$parts = $this->signed();
		$this->assertTrue( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW + 300 ) );
		$this->assertTrue( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW - 300 ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW + 301 ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW - 301 ) );
	}

	public function test_malformed_missing_or_repeated_headers_parse_to_null(): void {
		$good = Signer::headers( self::LINK, $this->secret, 'GET', '/x', '', self::NOW );
		$bad  = [
			Signer::HEADER_LINK      => [ 'ABCDEF0123456789', '0123', '', self::LINK . ', ' . self::LINK, self::LINK . ',' . self::LINK ],
			Signer::HEADER_TIME      => [ '0', '-5', '1e9', ' 1790000000', '17900000000000', '01790000000' ],
			Signer::HEADER_NONCE     => [ strtoupper( $good[ Signer::HEADER_NONCE ] ), 'abc', $good[ Signer::HEADER_NONCE ] . "\n" ],
			Signer::HEADER_SIGNATURE => [ substr( $good[ Signer::HEADER_SIGNATURE ], 1 ), $good[ Signer::HEADER_SIGNATURE ] . "\n" ],
		];
		$this->assertNotNull( Signer::parse( $good ) );
		foreach ( $bad as $name => $values ) {
			foreach ( $values as $value ) {
				$this->assertNull( Signer::parse( array_merge( $good, [ $name => $value ] ) ), $name . ' ' . $value );
			}
			$missing = $good;
			unset( $missing[ $name ] );
			$this->assertNull( Signer::parse( $missing ), $name . ' missing' );
			$this->assertNull( Signer::parse( array_merge( $good, [ $name => [ 'not', 'a string' ] ] ) ), $name . ' array' );
		}
	}

	public function test_nonces_are_fresh_and_a_bad_secret_or_link_is_refused(): void {
		$a = Signer::headers( self::LINK, $this->secret, 'GET', '/x', '', self::NOW );
		$b = Signer::headers( self::LINK, $this->secret, 'GET', '/x', '', self::NOW );
		$this->assertNotSame( $a[ Signer::HEADER_NONCE ], $b[ Signer::HEADER_NONCE ] );
		$this->assertFalse( Signer::verify( (array) Signer::parse( $a ), 'short', 'GET', '/x', '', self::NOW ) );
		foreach ( [ [ self::LINK, 'short' ], [ 'XYZ', $this->secret ] ] as [ $link, $secret ] ) {
			try {
				Signer::headers( $link, $secret, 'GET', '/x', '', self::NOW );
				$this->fail( 'Refused: ' . $link );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_the_canonical_form_is_fixed(): void {
		$this->assertSame(
			"LWM-HMAC-SHA256\n0123456789abcdef\nPOST\n/r\n1790000000\n" . str_repeat( 'a', 32 ) . "\n" . hash( 'sha256', 'x' ),
			Signer::canonical( self::LINK, 'post', '/r', self::NOW, str_repeat( 'a', 32 ), 'x' )
		);
		$this->assertSame(
			hash_hmac( 'sha256', Signer::canonical( self::LINK, 'GET', '/r', self::NOW, str_repeat( 'b', 32 ), '' ), $this->secret ),
			Signer::headers( self::LINK, $this->secret, 'GET', '/r', '', self::NOW, str_repeat( 'b', 32 ) )[ Signer::HEADER_SIGNATURE ]
		);
	}

	public function test_a_short_secret_never_verifies_even_with_a_matching_signature(): void {
		$parts = $this->signed();
		$short = 'short';
		$parts['signature'] = hash_hmac( 'sha256', Signer::canonical( self::LINK, 'POST', self::ROUTE, self::NOW, $parts['nonce'], '{}' ), $short );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE, '{}', self::NOW, $short ) );
	}

	public function test_control_characters_in_method_or_route_and_a_bad_nonce_are_refused(): void {
		$parts = $this->signed();
		$this->assertFalse( $this->verifies( $parts, "POST\n" ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', self::ROUTE . "\n/x" ) );
		$this->assertFalse( $this->verifies( $parts, 'POST', "/a\x00b" ) );
		foreach ( [ [ "GE\nT", '/x', null ], [ 'GET', "/x\n/y", null ], [ 'GET', '/x', 'ABC' ], [ 'GET', '/x', str_repeat( 'a', 31 ) ] ] as [ $method, $route, $nonce ] ) {
			try {
				Signer::headers( self::LINK, $this->secret, $method, $route, '', self::NOW, $nonce );
				$this->fail( 'Refused.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_an_answer_is_signed_over_its_link_request_nonce_status_and_body(): void {
		$nonce = str_repeat( 'c', 32 );
		$this->assertSame( 'X-LWM-Response-Signature', Signer::HEADER_RESPONSE );
		$this->assertSame(
			hash_hmac( 'sha256', "LWM-RESP\n" . self::LINK . "\n" . $nonce . "\n200\n" . hash( 'sha256', '{"ok":true}' ), $this->secret ),
			Signer::answer( self::LINK, $this->secret, $nonce, 200, '{"ok":true}' )
		);
	}

	public function test_an_answer_verifies_only_with_every_signed_part(): void {
		$nonce     = str_repeat( 'c', 32 );
		$signature = Signer::answer( self::LINK, $this->secret, $nonce, 200, '{}' );
		$this->assertTrue( Signer::verifyAnswer( self::LINK, $this->secret, $nonce, 200, '{}', $signature ) );
		$this->assertFalse( Signer::verifyAnswer( 'fedcba9876543210', $this->secret, $nonce, 200, '{}', $signature ), 'link' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, str_repeat( "\x02", 32 ), $nonce, 200, '{}', $signature ), 'secret' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, $this->secret, str_repeat( 'd', 32 ), 200, '{}', $signature ), 'nonce' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, $this->secret, $nonce, 201, '{}', $signature ), 'status' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, $this->secret, $nonce, 200, '{ }', $signature ), 'body' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, $this->secret, $nonce, 200, '{}', strtoupper( $signature ) ), 'form' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, $this->secret, $nonce, 200, '{}', '' ), 'missing' );
		$this->assertFalse( Signer::verifyAnswer( self::LINK, 'short', $nonce, 200, '{}', hash_hmac( 'sha256', "LWM-RESP\n" . self::LINK . "\n" . $nonce . "\n200\n" . hash( 'sha256', '{}' ), 'short' ) ), 'short secret' );
	}
}
