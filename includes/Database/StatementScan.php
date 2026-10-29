<?php
/**
 * State of one StatementReader::next() call, fed one line piece at a time.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks per physical line only its length, its first bytes after leading
 * whitespace (to recognise "--") and its last non-whitespace byte, so a line
 * split across any number of chunks is classified exactly as a whole line.
 */
final class StatementScan {

	private const WS        = " \t\n\r\0\x0B";
	private const UNDECIDED = 0;
	private const SKIP      = 1;
	private const TAKE      = 2;

	/**
	 * Statement bytes kept so far (the first line's leading whitespace is
	 * not kept: the SQL is trimmed anyway, and it is counted in $taken).
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Bytes of all lines that belong to the statement.
	 *
	 * @var int
	 */
	private int $taken = 0;

	/**
	 * Whether the statement passed the limit; nothing is kept after that.
	 *
	 * @var bool
	 */
	private bool $oversized = false;

	/**
	 * Bytes of the current physical line so far.
	 *
	 * @var int
	 */
	private int $lineLength = 0;

	/**
	 * How the current line is treated: undecided, skipped or taken.
	 *
	 * @var int
	 */
	private int $mode = self::UNDECIDED;

	/**
	 * A lone "-" seen after leading whitespace, waiting for the next byte.
	 *
	 * @var string
	 */
	private string $head = '';

	/**
	 * Last non-whitespace byte of the current line.
	 *
	 * @var string
	 */
	private string $last = '';

	/**
	 * @param int $start    Offset the scan begins at; moves past skipped lines.
	 * @param int $maxBytes Largest trimmed statement that is not oversized.
	 * @param int $limit    Bytes kept at most before the statement is dropped.
	 */
	public function __construct( private int $start, private int $maxBytes, private int $limit ) {
	}

	/**
	 * Consume the next bytes of the current line (at most one "\n", at the end).
	 *
	 * @param string $piece Non-empty bytes.
	 */
	public function feed( string $piece ): void {
		$length            = strlen( $piece );
		$this->lineLength += $length;
		$tail              = rtrim( $piece, self::WS );
		if ( '' !== $tail ) {
			$this->last = $tail[-1];
		}
		if ( self::UNDECIDED === $this->mode ) {
			$piece  = $this->decide( $piece );
			$length = $this->lineLength;
		}
		if ( self::TAKE === $this->mode ) {
			$this->append( $piece, $length );
		}
	}

	/**
	 * Close the current line (at "\n" or end of file).
	 *
	 * @return bool Whether the line completed the statement.
	 */
	public function endLine(): bool {
		if ( self::UNDECIDED === $this->mode ) {
			$this->mode = '' === $this->head ? self::SKIP : self::TAKE;
			if ( self::TAKE === $this->mode ) {
				$this->append( $this->head, $this->lineLength );
			}
		}
		if ( self::SKIP === $this->mode ) {
			$this->start += $this->lineLength;
		}
		$done = self::TAKE === $this->mode && ';' === $this->last;

		$this->lineLength = 0;
		$this->head       = '';
		$this->last       = '';
		$this->mode       = $this->taken > 0 ? self::TAKE : self::UNDECIDED;

		return $done;
	}

	/**
	 * Whether statement bytes were taken (a non-blank, non-comment line began).
	 */
	public function pending(): bool {
		return $this->taken > 0;
	}

	/**
	 * The statement ending at $end.
	 *
	 * @param int  $end       Offset just after the statement's last line.
	 * @param bool $truncated The file ended before the statement's ";".
	 */
	public function statement( int $end, bool $truncated = false ): Statement {
		$sql          = $this->oversized ? '' : trim( $this->buffer );
		$this->buffer = '';
		$oversized    = $this->oversized || strlen( $sql ) > $this->maxBytes;

		return new Statement( $oversized ? '' : $sql, $this->start, $end, $oversized, $truncated );
	}

	/**
	 * Classify a leading line once its first two bytes after whitespace are known.
	 *
	 * @param string $piece Bytes of the line.
	 * @return string Bytes to keep if the line is taken.
	 */
	private function decide( string $piece ): string {
		$skip = '' === $this->head ? strspn( $piece, self::WS ) : 0;
		$text = $this->head . substr( $piece, $skip );
		if ( '' === $text || '-' === $text ) {
			$this->head = $text;

			return '';
		}
		$this->mode = str_starts_with( $text, '--' ) ? self::SKIP : self::TAKE;

		return $text;
	}

	private function append( string $bytes, int $count ): void {
		$this->taken += $count;
		if ( $this->oversized ) {
			return;
		}
		$this->buffer .= $bytes;
		if ( $this->taken > $this->limit ) {
			$this->oversized = true;
			$this->buffer    = '';
		}
	}
}
