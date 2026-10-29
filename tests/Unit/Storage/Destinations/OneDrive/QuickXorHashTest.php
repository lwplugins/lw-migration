<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\OneDrive;

use LightweightPlugins\Migration\Storage\Destinations\OneDrive\QuickXorHash;
use PHPUnit\Framework\TestCase;

/**
 * OneDrive's quickXorHash (learn.microsoft.com/en-us/onedrive/developer/code-snippets/quickxorhash,
 * read 2026-10-14). The vectors are the published test table of an
 * independent open-source implementation of the same algorithm.
 */
final class QuickXorHashTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function vectors(): array {
		return [
			'empty'     => [ '', 'AAAAAAAAAAAAAAAAAAAAAAAAAAA=' ],
			'1 byte'    => [ 'Sg==', 'SgAAAAAAAAAAAAAAAQAAAAAAAAA=' ],
			'2 bytes'   => [ 'tbQ=', 'taAFAAAAAAAAAAAAAgAAAAAAAAA=' ],
			'3 bytes'   => [ '0pZP', '0rDEEwAAAAAAAAAAAwAAAAAAAAA=' ],
			'4 bytes'   => [ 'jRRDVA==', 'jaDAEKgAAAAAAAAABAAAAAAAAAA=' ],
			'5 bytes'   => [ 'eAV52qE=', 'eChAHrQRCgAAAAAABQAAAAAAAAA=' ],
			'222 bytes' => [ 'PfxMcUd0vIW6VbHG/uj/Y0W6qEoKmyBD0nYebEKazKaKG+UaDqBEcmQjbfQeVnVLuodMoPp7P7TR1htX5n2VnkHh22xDyoJ8C/ZQKiSNqQfXvh83judf4RVr9exJCud8Uvgip6aVZTaPrJHVjQhMCp/dEnGvqg0oN5OVkM2qqAXvA0teKUDhgNM71sDBVBCGXxNOR2bpbD1iM4dnuT0ey4L+loXEHTL0fqMeUcEi2asgImnlNakwenDzz0x57aBwyq3AspCFGB1ncX4yYCr/OaCcS5OKi/00WH+wNQU3', 'QX/YEpG0gDsmhEpCdWhsxDzsfVE=' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'vectors' )]
	public function test_the_hash_of_known_inputs( string $input, string $hash ): void {
		$bytes = (string) base64_decode( $input, true );

		$this->assertSame( $hash, QuickXorHash::digest( QuickXorHash::fold( $bytes, 0 ), strlen( $bytes ) ) );
	}

	/**
	 * Parts of any length at any offset fold to the hash of the whole, and
	 * the folding agrees with a byte-by-byte register past one piece.
	 */
	public function test_parts_fold_into_the_hash_of_the_whole(): void {
		mt_srand( 7 );
		$bytes = '';
		for ( $i = 0; $i < 3_000_001; $i += 4 ) {
			$bytes .= pack( 'V', mt_rand() );
		}
		$bytes = substr( $bytes, 0, 3_000_001 );
		$fold  = QuickXorHash::empty();
		foreach ( [ [ 0, 327680 ], [ 327680, 72320 ], [ 400000, 1_310_721 ], [ 1_710_721, 1_289_280 ] ] as [ $at, $length ] ) {
			$fold ^= QuickXorHash::fold( substr( $bytes, $at, $length ), $at );
		}

		$this->assertSame( self::reference( $bytes ), QuickXorHash::digest( $fold, strlen( $bytes ) ) );
		$this->assertSame( self::reference( $bytes ), QuickXorHash::digest( QuickXorHash::fold( $bytes, 0 ), strlen( $bytes ) ) );
	}

	/**
	 * The algorithm as the documentation states it: byte i rotated into
	 * the 160-bit register at bit i * 11.
	 */
	private static function reference( string $bytes ): string {
		$bits   = array_fill( 0, 160, 0 );
		$length = strlen( $bytes );
		$per    = array_fill( 0, 160, 0 );
		for ( $i = 0; $i < $length; $i++ ) {
			$per[ $i % 160 ] ^= ord( $bytes[ $i ] ); // Bytes 160 apart start on the same bit.
		}
		foreach ( $per as $k => $byte ) {
			for ( $b = 0; $b < 8; $b++ ) {
				$bits[ ( $k * 11 + $b ) % 160 ] ^= ( $byte >> $b ) & 1;
			}
		}
		$out = '';
		for ( $c = 0; $c < 20; $c++ ) {
			$value = 0;
			for ( $b = 0; $b < 8; $b++ ) {
				$value |= $bits[ 8 * $c + $b ] << $b;
			}
			$out .= chr( $c >= 12 ? $value ^ ( ( $length >> ( 8 * ( $c - 12 ) ) ) & 0xFF ) : $value );
		}

		return base64_encode( $out );
	}
}
