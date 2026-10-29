<?php
/**
 * CRC-32 (crc32b) helpers.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * CRC-32b as 8 lowercase hex chars, plus combining two CRCs without re-reading data
 * (GF(2) matrix method, as in zlib's crc32_combine).
 */
final class Crc32 {

	public const EMPTY = '00000000';

	private const POLY = 0xEDB88320;

	public static function ofString( string $data ): string {
		return hash( 'crc32b', $data );
	}

	/**
	 * CRC of A.B from crc(A), crc(B) and the length of B.
	 *
	 * @param string $crc1 CRC of the first block.
	 * @param string $crc2 CRC of the second block.
	 * @param int    $len2 Length of the second block in bytes.
	 * @return string
	 */
	public static function combine( string $crc1, string $crc2, int $len2 ): string {
		$a = self::toInt( $crc1 );
		$b = self::toInt( $crc2 );

		if ( $len2 <= 0 ) {
			return sprintf( '%08x', $a );
		}

		$odd = [ self::POLY ];
		$row = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}

		$even = self::square( $odd );
		$odd  = self::square( $even );

		do {
			$even = self::square( $odd );
			if ( $len2 & 1 ) {
				$a = self::times( $even, $a );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::square( $even );
			if ( $len2 & 1 ) {
				$a = self::times( $odd, $a );
			}
			$len2 >>= 1;
		} while ( $len2 );

		return sprintf( '%08x', ( $a ^ $b ) & 0xFFFFFFFF );
	}

	private static function toInt( string $crc ): int {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}$/i', $crc ) ) {
			throw new ArchiveException( sprintf( 'Invalid CRC value: %s', $crc ) );
		}

		return (int) hexdec( $crc );
	}

	/**
	 * @param array<int, int> $mat Matrix.
	 * @param int             $vec Vector.
	 * @return int
	 */
	private static function times( array $mat, int $vec ): int {
		$sum = 0;
		$i   = 0;
		while ( 0 !== $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			++$i;
		}

		return $sum;
	}

	/**
	 * @param array<int, int> $mat Matrix.
	 * @return array<int, int>
	 */
	private static function square( array $mat ): array {
		$square = [];
		for ( $n = 0; $n < 32; $n++ ) {
			$square[ $n ] = self::times( $mat, $mat[ $n ] );
		}

		return $square;
	}
}
