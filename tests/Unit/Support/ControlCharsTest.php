<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\ControlChars;
use PHPUnit\Framework\TestCase;

final class ControlCharsTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function controls(): array {
		return [
			'escape'             => [ "a\x1bb", 'a\x1bb' ],
			'bell'               => [ "ring\x07", 'ring\x07' ],
			'csi erase and up'   => [ "\x1b[2K\x1b[1Aok", '\x1b[2K\x1b[1Aok' ],
			'osc 52 clipboard'   => [ "../\x1b]52;c;QUJD\x07ignored.txt", '../\x1b]52;c;QUJD\x07ignored.txt' ],
			'osc ended by st'    => [ "\x1b]0;title\x1b\\x", '\x1b]0;title\x1b\x' ],
			'nul'                => [ "a\0b", 'a\x00b' ],
			'carriage return'    => [ "real\rfake", 'real\x0dfake' ],
			'backspace'          => [ "ab\x08c", 'ab\x08c' ],
			'delete'             => [ "a\x7fb", 'a\x7fb' ],
			'c1 csi (u+009b)'    => [ "a\u{9b}2Kb", 'a\u009b2Kb' ],
			'c1 osc (u+009d)'    => [ "\u{9d}52;c;QUJD\u{9c}", '\u009d52;c;QUJD\u009c' ],
			'invalid lone bytes' => [ "a\x9b\xffb", 'a\x9b\xffb' ],
			'overlong esc (2)'   => [ "a\xC0\x9Bb", 'a\xc0\x9bb' ],
			'overlong esc (3)'   => [ "a\xE0\x80\x9Bb", 'a\xe0\x80\x9bb' ],
			'surrogate'          => [ "a\xED\xA0\x80b", 'a\xed\xa0\x80b' ],
			'truncated tail'     => [ "euro \xE2\x82", 'euro \xe2\x82' ],
			'lead byte then esc' => [ "a\xC2\x1B[2Kb", 'a\xc2\x1b[2Kb' ],
			'lone 0x9b'          => [ "\x9b", '\x9b' ],
		];
	}

	/**
	 * @dataProvider controls
	 */
	public function test_control_characters_become_visible_escapes( string $text, string $shown ): void {
		$this->assertSame( $shown, ControlChars::escape( $text ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function printable(): array {
		return [
			'ascii'               => [ 'Unsafe path in archive: ../x.php' ],
			'newline and tab'     => [ "line one\n\tline two" ],
			'hungarian accents'   => [ 'ékezetes fájl: ü ő Ű Ő' ],
			'c1 lookalike bytes'  => [ 'ő Ő' ], // C5 91 and C5 90: their second byte is in 0x80-0x9F.
			'non-breaking space'  => [ "a\u{a0}b" ],
			'four-byte character' => [ "clef \u{1D11E} plane 1" ],
			'backslash'           => [ 'C:\\path\\x1b' ],
		];
	}

	/**
	 * @dataProvider printable
	 */
	public function test_printable_text_passes_unchanged( string $text ): void {
		$this->assertSame( $text, ControlChars::escape( $text ) );
	}
}
