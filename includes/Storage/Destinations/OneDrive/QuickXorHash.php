<?php
/**
 * OneDrive's quickXorHash, built up part by part.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Storage\Destinations\OneDrive;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. learn.microsoft.com/en-us/onedrive/developer/code-snippets/quickxorhash
 * (read 2026-10-14): a 160-bit register; byte i of the file is XORed in at
 * bit (i * 11) mod 160 (wrapping round to bit 0); the register is written
 * as 20 bytes, lowest bits first, and the file length (64 bits, little
 * endian) is XORed into its last 8 bytes; Graph shows it base64-encoded in
 * file.hashes.quickXorHash, the one hash both OneDrive for home and for
 * work or school are documented to give (graph/api/resources/hashes).
 *
 * Bytes 160 apart land on the same bit, so a file is kept as its "fold":
 * 160 bytes, byte k the XOR of every file byte at an index ≡ k (mod 160).
 * Folds of consecutive parts XOR together (fold() is told where a part
 * starts), and only digest() places the 160 bytes into the register. The
 * folding uses PHP's string XOR on halves, so a 30 MiB part costs a few
 * C-speed passes and at most one extra piece of PIECE bytes in memory.
 */
final class QuickXorHash {

	/** Bytes in a fold (the register's period). */
	public const WIDTH = 160;

	/** Bytes folded at once: 8 192 periods (a power of two, so halves fold evenly). */
	private const PIECE = 1310720;

	private const SHIFT = 11;

	/** The fold of no bytes. */
	public static function empty(): string {
		return str_repeat( "\0", self::WIDTH );
	}

	/**
	 * @param string $bytes Bytes of the file.
	 * @param int    $start Index of their first byte in the file.
	 * @return string Their fold (WIDTH bytes) at that position.
	 */
	public static function fold( string $bytes, int $start ): string {
		$fold   = self::empty();
		$length = strlen( $bytes );
		for ( $at = 0; $at < $length; $at += self::PIECE ) {
			$piece = substr( $bytes, $at, self::PIECE );
			if ( strlen( $piece ) < self::PIECE ) {
				$piece = str_pad( $piece, self::PIECE, "\0" );
			}
			for ( $half = self::PIECE >> 1; $half >= self::WIDTH; $half >>= 1 ) {
				$piece = substr( $piece, 0, $half ) ^ substr( $piece, $half, $half );
			}
			$fold ^= $piece;
		}
		$turn = $start % self::WIDTH;

		return 0 === $turn ? $fold : substr( $fold, self::WIDTH - $turn ) . substr( $fold, 0, self::WIDTH - $turn );
	}

	/**
	 * @param string $fold   The fold of the whole file (fold() XORed over its parts).
	 * @param int    $length The file's length.
	 * @return string The quickXorHash, base64 as Graph shows it.
	 */
	public static function digest( string $fold, int $length ): string {
		$register = array_fill( 0, 20, 0 );
		for ( $k = 0; $k < self::WIDTH; $k++ ) {
			$byte = ord( $fold[ $k ] );
			if ( 0 === $byte ) {
				continue;
			}
			$bit                             = ( $k * self::SHIFT ) % self::WIDTH;
			$cell                            = $bit >> 3;
			$register[ $cell ]              ^= ( $byte << ( $bit & 7 ) ) & 0xFF;
			$register[ ( $cell + 1 ) % 20 ] ^= $byte >> ( 8 - ( $bit & 7 ) ); // Bits past bit 159 wrap round to bit 0.
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$register[ 12 + $i ] ^= ( $length >> ( 8 * $i ) ) & 0xFF;
		}

		return base64_encode( pack( 'C*', ...$register ) );
	}
}
