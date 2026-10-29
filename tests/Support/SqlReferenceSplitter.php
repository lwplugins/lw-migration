<?php
/**
 * Whole-line reference splitter and random dumps for StatementReader tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Support;

/**
 * The obvious fgets()-based splitter that StatementReader must agree with.
 */
final class SqlReferenceSplitter {

	public static function randomDump(): string {
		$fragments = [ 'a', 'b', ';', '; ', ' ', 'x;y', '--c', '-', "\t", '(1)', "'", '  ;  ', 'zz' ];
		$sql       = '';
		$lines     = mt_rand( 1, 12 );
		for ( $i = 0; $i < $lines; $i++ ) {
			$kind = mt_rand( 0, 9 );
			if ( 0 === $kind ) {
				$line = str_repeat( ' ', mt_rand( 0, 25 ) );
			} elseif ( 1 === $kind ) {
				$line = str_repeat( ' ', mt_rand( 0, 25 ) ) . '-- comment ' . mt_rand();
			} elseif ( 2 === $kind ) {
				$line = str_repeat( 'w', mt_rand( 1, 40 ) ) . ';';
			} else {
				$line  = '';
				$parts = mt_rand( 1, 6 );
				for ( $j = 0; $j < $parts; $j++ ) {
					$line .= $fragments[ mt_rand( 0, count( $fragments ) - 1 ) ];
				}
			}
			$sql .= $line . ( mt_rand( 0, 3 ) ? "\n" : "\r\n" );
		}

		return 0 === mt_rand( 0, 2 ) ? rtrim( $sql, "\r\n" ) : $sql;
	}

	/**
	 * Whole-line reference: fgets() lines, the statement ends when the
	 * accumulated buffer ends in ";" plus whitespace.
	 *
	 * @return list<array{string,int,int,bool,bool}>
	 */
	public static function split( string $sql, int $max, int $slack ): array {
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, $sql );
		rewind( $handle );
		$out      = [];
		$buffer   = '';
		$position = 0;
		$start    = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$position += strlen( $line );
			if ( '' === $buffer && ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '--' ) ) ) {
				$start = $position;
				continue;
			}
			$buffer .= $line;
			if ( preg_match( '/;\s*$/S', $buffer ) ) {
				$trimmed   = trim( $buffer );
				$oversized = strlen( $trimmed ) > $max || strlen( $buffer ) > $max + $slack;
				$out[]     = [ $oversized ? '' : $trimmed, $start, $position, $oversized, false ];
				$buffer    = '';
				$start     = $position;
			}
		}
		fclose( $handle );
		if ( '' !== $buffer ) {
			$trimmed   = trim( $buffer );
			$oversized = strlen( $trimmed ) > $max || strlen( $buffer ) > $max + $slack;
			$out[]     = [ $oversized ? '' : $trimmed, $start, $position, $oversized, true ];
		}

		return $out;
	}
}
