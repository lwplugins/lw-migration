<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Link;

use LightweightPlugins\Migration\Link\PairingMessage;
use PHPUnit\Framework\TestCase;

final class PairingMessageTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function request(): array {
		return [
			'protocol' => 1,
			'code'     => str_repeat( 'a', 64 ),
			'public'   => str_repeat( 'b', 64 ),
			'address'  => 'https://a.example',
			'name'     => 'Site A',
			'mac'      => str_repeat( 'c', 64 ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		return [
			'id'       => '0123456789abcdef',
			'public'   => str_repeat( 'b', 64 ),
			'name'     => 'Site B',
			'address'  => 'https://b.example',
			'allow'    => [ 'pull', 'push' ],
			'protocol' => 1,
			'proof'    => str_repeat( 'd', 64 ),
		];
	}

	public function test_a_well_formed_request_is_read_with_its_raw_key(): void {
		$read = PairingMessage::request( self::request() );
		$this->assertSame( str_repeat( "\xbb", 32 ), $read['public'] );
		$this->assertSame( [ 'code', 'public', 'address', 'name', 'mac' ], array_keys( $read ) );
		$this->assertNotNull( PairingMessage::request( array_merge( self::request(), [ 'name' => str_repeat( 'é', 512 ) ] ) ), '1024 bytes are fine.' );
	}

	public function test_any_other_request_is_refused(): void {
		$good = self::request();
		$bad  = [
			array_diff_key( $good, [ 'mac' => true ] ),
			$good + [ 'nonce' => str_repeat( 'e', 32 ) ],
			array_merge( $good, [ 'protocol' => 2 ] ),
			array_merge( $good, [ 'protocol' => '1' ] ),
			array_merge( $good, [ 'code' => str_repeat( 'a', 63 ) ] ),
			array_merge( $good, [ 'code' => str_repeat( 'A', 64 ) ] ),
			array_merge( $good, [ 'mac' => 5 ] ),
			array_merge( $good, [ 'public' => str_repeat( '0', 64 ) ] ),
			array_merge( $good, [ 'public' => str_repeat( 'b', 63 ) ] ),
			array_merge( $good, [ 'address' => 'ftp://a.example' ] ),
			array_merge( $good, [ 'address' => 'https://[1:2]' ] ),
			array_merge( $good, [ 'address' => [ 'https://a.example' ] ] ),
			array_merge( $good, [ 'name' => str_repeat( 'n', 1025 ) ] ),
			array_merge( $good, [ 'name' => "A\x7F" ] ),
			array_merge( $good, [ 'name' => "A\tB" ] ),
			array_merge( $good, [ 'name' => "x\xC3" ] ),
			array_merge( $good, [ 'name' => 1 ] ),
			array_values( $good ),
			'text',
			null,
		];
		foreach ( $bad as $i => $body ) {
			$this->assertNull( PairingMessage::request( $body ), (string) $i );
		}
	}

	public function test_a_well_formed_answer_is_read_and_any_other_refused(): void {
		$read = PairingMessage::answer( self::answer() );
		$this->assertSame( [ '0123456789abcdef', str_repeat( "\xbb", 32 ), 'Site B', [ 'pull', 'push' ], str_repeat( 'd', 64 ) ], [ $read['id'], $read['public'], $read['name'], $read['allow'], $read['proof'] ] );
		$good = self::answer();
		$bad  = [
			array_diff_key( $good, [ 'address' => true ] ),
			$good + [ 'key' => 'x' ],
			array_merge( $good, [ 'protocol' => 2 ] ),
			array_merge( $good, [ 'id' => '0123456789ABCDEF' ] ),
			array_merge( $good, [ 'id' => 5 ] ),
			array_merge( $good, [ 'public' => str_repeat( '0', 64 ) ] ),
			array_merge( $good, [ 'proof' => str_repeat( 'd', 65 ) ] ),
			array_merge( $good, [ 'allow' => [] ] ),
			array_merge( $good, [ 'allow' => [ 'delete' ] ] ),
			array_merge( $good, [ 'allow' => [ 'push', 'pull' ] ] ),
			array_merge( $good, [ 'allow' => 'pull' ] ),
			array_merge( $good, [ 'address' => null ] ),
			array_merge( $good, [ 'name' => "B\n" ] ),
			'text',
		];
		foreach ( $bad as $i => $answer ) {
			$this->assertNull( PairingMessage::answer( $answer ), (string) $i );
		}
	}
}
