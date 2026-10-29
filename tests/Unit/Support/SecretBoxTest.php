<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase {

	public function test_round_trip_with_a_fresh_nonce_every_time(): void {
		$box = new SecretBox( str_repeat( 'k', SecretBox::KEY_BYTES ) );
		$one = $box->seal( 'hunter2-secret' );

		$this->assertStringStartsWith( 'lwm1:', $one );
		$this->assertStringNotContainsString( 'hunter2', $one );
		$this->assertNotSame( $one, $box->seal( 'hunter2-secret' ), 'Every seal uses a new nonce.' );
		$this->assertSame( 'hunter2-secret', $box->open( $one ) );
		$this->assertSame( '', $box->open( $box->seal( '' ) ) );
	}

	public function test_another_key_tampering_and_foreign_values_read_as_null(): void {
		$box    = new SecretBox( str_repeat( 'k', SecretBox::KEY_BYTES ) );
		$sealed = $box->seal( 'hunter2-secret' );
		$raw    = (string) base64_decode( substr( $sealed, 5 ), true );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );

		$this->assertNull( ( new SecretBox( str_repeat( 'x', SecretBox::KEY_BYTES ) ) )->open( $sealed ) );
		$this->assertNull( $box->open( 'lwm1:' . base64_encode( $raw ) ) );
		foreach ( [ '', 'hunter2-secret', 'lwm1:', 'lwm1:!!!', 'lwm1:' . base64_encode( 'too short' ) ] as $foreign ) {
			$this->assertNull( $box->open( $foreign ), $foreign );
		}
	}

	public function test_the_key_must_have_32_bytes(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SecretBox( 'short' );
	}
}
