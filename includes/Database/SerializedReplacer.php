<?php
/**
 * Replaces values inside PHP-serialized data without unserializing it.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A text parser over s/i/d/b/N/a/O/R/r tokens: string lengths are recomputed
 * after replacement, strings that are themselves serialized are processed
 * recursively, and anything unparsable falls back to strtr on the whole value.
 * unserialize() is never called (no object injection from foreign data).
 *
 * Nested strings are parsed in place (no copy per level), down to MAX_NESTED
 * levels and while the nested bytes searched stay within BUDGET times the
 * value; a string past either limit is kept exactly as it is (its length
 * still holds) and limited() tells so.
 */
final class SerializedReplacer {

	private const MAX_DEPTH = 4096;

	/** Serialized strings inside serialized strings searched at most. */
	private const MAX_NESTED = 32;

	/** Nested-string bytes searched at most, in multiples of the value. */
	private const BUDGET = 4;

	/** Integer, double, boolean and reference tokens in exactly the grammar unserialize() accepts. */
	private const SCALAR = '/\G(?:i:[+-]?\d+|d:(?:[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|NAN|-?INF)|b:[01]|[Rr]:\d+);/';

	/** What trim() strips by default, as maybe_unserialize() uses it. */
	private const PADDING = " \t\n\r\0\x0B";

	/** @var int End of the part being parsed (exclusive). */
	private int $end = 0;

	/** @var int Nested-string bytes that may still be searched. */
	private int $budget = 0;

	/** @var int Serialized strings the parser is inside of. */
	private int $nest = 0;

	/** @var bool Whether the last replace() kept a nested string unsearched. */
	private bool $limited = false;

	/**
	 * @param array<string, string> $pairs Old => new.
	 */
	public function __construct( private array $pairs ) {
	}

	public function replace( string $data ): string {
		$this->budget  = self::BUDGET * strlen( $data );
		$this->nest    = 0;
		$this->limited = false;
		$this->end     = strlen( $data );
		$out           = $this->whole( $data, 0, strlen( $data ), 0 );
		if ( null === $out ) {
			$this->limited = false;
			return strtr( $data, $this->pairs );
		}

		return $out;
	}

	/** Whether the last replace() kept a nested string as it was, past MAX_NESTED or BUDGET. */
	public function limited(): bool {
		return $this->limited;
	}

	/**
	 * The value as one serialized element with its padding kept, or null when
	 * it is not one. WordPress trims a value before it unserializes it
	 * (maybe_unserialize()), so a padded value is serialized data too.
	 *
	 * @param string $d     Data.
	 * @param int    $from  Start of the value.
	 * @param int    $to    End of the value (exclusive).
	 * @param int    $depth Nesting depth.
	 * @return string|null
	 */
	private function whole( string $d, int $from, int $to, int $depth ): ?string {
		$start = $from + strspn( $d, self::PADDING, $from, $to - $from );
		$end   = $to;
		while ( $end > $start && str_contains( self::PADDING, $d[ $end - 1 ] ) ) {
			--$end;
		}
		if ( $start >= $end ) {
			return null;
		}
		$outer     = $this->end;
		$this->end = $end;
		$pos       = $start;
		$out       = $this->element( $d, $pos, $depth );
		$this->end = $outer;
		if ( null === $out || $end !== $pos ) {
			return null;
		}

		return substr( $d, $from, $start - $from ) . $out . substr( $d, $end, $to - $end );
	}

	private function element( string $d, int &$pos, int $depth ): ?string {
		if ( $depth > self::MAX_DEPTH || $pos >= $this->end ) {
			return null;
		}
		switch ( $d[ $pos ] ) {
			case 's':
				return $this->str( $d, $pos, $depth );
			case 'a':
				return $this->arr( $d, $pos, $depth );
			case 'O':
				return $this->obj( $d, $pos, $depth );
			case 'E':
			case 'C':
				return $this->blob( $d, $pos );
			case 'N':
				if ( $pos + 2 <= $this->end && 'N;' === substr( $d, $pos, 2 ) ) {
					$pos += 2;
					return 'N;';
				}
				return null;
			case 'i':
			case 'd':
			case 'b':
			case 'R':
			case 'r':
				return $this->match( self::SCALAR, $d, $pos );
		}

		return null;
	}

	private function str( string $d, int &$pos, int $depth ): ?string {
		$at = $pos;
		if ( 1 !== preg_match( '/\Gs:(\d+):"/', $d, $m, 0, $pos ) ) {
			return null;
		}
		$len   = (int) $m[1];
		$start = $pos + strlen( $m[0] );
		if ( $start + $len + 2 > $this->end || '";' !== substr( $d, $start + $len, 2 ) ) {
			return null;
		}
		$pos = $start + $len + 2;
		if ( $len <= 1 ) {
			return substr( $d, $at, $pos - $at );
		}
		if ( $this->nest > self::MAX_NESTED || $len > $this->budget ) {
			$this->limited = true;
			return substr( $d, $at, $pos - $at ); // Kept exactly: its length still holds.
		}
		$this->budget -= $len;
		$limited       = $this->limited;
		++$this->nest;
		$new = $this->whole( $d, $start, $start + $len, $depth + 1 );
		--$this->nest;
		if ( null === $new ) {
			$this->limited = $limited; // Not serialized: replaced as a whole below.
			$new           = strtr( substr( $d, $start, $len ), $this->pairs );
		}

		return 's:' . strlen( $new ) . ':"' . $new . '";';
	}

	/**
	 * Copies enum (E:len:"v";) and Serializable (C:len:"class":n:{n bytes}) tokens verbatim by byte length.
	 *
	 * @param string $d   Data.
	 * @param int    $pos Position (by reference).
	 * @return string|null
	 */
	private function blob( string $d, int &$pos ): ?string {
		if ( 1 === preg_match( '/\GE:(\d+):"/', $d, $m, 0, $pos ) ) {
			$len = $this->fit( $d, $pos + strlen( $m[0] ), $m[1] );
			$end = null === $len ? 0 : $pos + strlen( $m[0] ) + $len;
			if ( null === $len || $end + 2 > $this->end || '";' !== substr( $d, $end, 2 ) ) {
				return null;
			}
			$end += 2;
		} elseif ( 1 === preg_match( '/\GC:(\d+):"/', $d, $m, 0, $pos ) ) {
			$len = $this->fit( $d, $pos + strlen( $m[0] ), $m[1] );
			$end = null === $len ? 0 : $pos + strlen( $m[0] ) + $len;
			if ( null === $len || 1 !== preg_match( '/\G":(\d+):\{/', $d, $n, 0, $end ) ) {
				return null;
			}
			$body = $this->fit( $d, $end + strlen( $n[0] ), $n[1] );
			if ( null === $body ) {
				return null;
			}
			$end += strlen( $n[0] ) + $body;
			if ( $end >= $this->end || '}' !== $d[ $end ] ) {
				return null;
			}
			++$end;
		} else {
			return null;
		}
		$out = substr( $d, $pos, $end - $pos );
		$pos = $end;

		return $out;
	}

	/**
	 * Parses a declared length/count; null when it cannot fit in the data after $from (no int overflow).
	 *
	 * @param string $d      Data.
	 * @param int    $from   Offset the length applies from.
	 * @param string $digits Decimal digits.
	 * @return int|null
	 */
	private function fit( string $d, int $from, string $digits ): ?int {
		if ( strlen( $digits ) > 18 || (int) $digits > $this->end - $from ) {
			return null;
		}

		return (int) $digits;
	}

	private function arr( string $d, int &$pos, int $depth ): ?string {
		if ( 1 !== preg_match( '/\Ga:(\d+):\{/', $d, $m, 0, $pos ) ) {
			return null;
		}
		$count = $this->fit( $d, $pos + strlen( $m[0] ), $m[1] );
		if ( null === $count ) {
			return null;
		}
		$pos += strlen( $m[0] );
		$body = $this->elements( $d, $pos, 2 * $count, $depth );

		return null === $body ? null : $m[0] . $body;
	}

	private function obj( string $d, int &$pos, int $depth ): ?string {
		if ( 1 !== preg_match( '/\GO:(\d+):"/', $d, $m, 0, $pos ) ) {
			return null;
		}
		$len = $this->fit( $d, $pos + strlen( $m[0] ), $m[1] );
		if ( null === $len ) {
			return null;
		}
		$start = $pos + strlen( $m[0] );
		$class = substr( $d, $start, $len );
		$pos   = $start + $len;
		if ( 1 !== preg_match( '/\G":(\d+):\{/', $d, $n, 0, $pos ) ) {
			return null;
		}
		$count = $this->fit( $d, $pos + strlen( $n[0] ), $n[1] );
		if ( null === $count ) {
			return null;
		}
		$pos += strlen( $n[0] );
		$body = $this->elements( $d, $pos, 2 * $count, $depth );

		return null === $body ? null : 'O:' . strlen( $class ) . ':"' . $class . '":' . $n[1] . ':{' . $body;
	}

	/**
	 * Parses $count elements followed by "}".
	 *
	 * @param string $d     Data.
	 * @param int    $pos   Position (by reference).
	 * @param int    $count Elements.
	 * @param int    $depth Nesting depth.
	 * @return string|null Elements and the closing brace.
	 */
	private function elements( string $d, int &$pos, int $count, int $depth ): ?string {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$element = $this->element( $d, $pos, $depth + 1 );
			if ( null === $element ) {
				return null;
			}
			$out .= $element;
		}
		if ( $pos >= $this->end || '}' !== $d[ $pos ] ) {
			return null;
		}
		++$pos;

		return $out . '}';
	}

	private function match( string $pattern, string $d, int &$pos ): ?string {
		if ( 1 !== preg_match( $pattern, $d, $m, 0, $pos ) || $pos + strlen( $m[0] ) > $this->end ) {
			return null;
		}
		$pos += strlen( $m[0] );

		return $m[0];
	}
}
