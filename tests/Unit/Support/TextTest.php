<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	public function test_clip_keeps_valid_utf8_within_the_limit(): void {
		$this->assertSame( 'abc', Text::clip( 'abcdef', 3 ) );
		$this->assertSame( 'short', Text::clip( 'short', 100 ) );
		$this->assertSame( 'a', Text::clip( "a\xC3\xA9", 2 ), 'A character cut in half is dropped.' );
		$this->assertSame( "a\xC3\xA9", Text::clip( "a\xC3\xA9", 3 ) );
	}

	public function test_clip_replaces_invalid_bytes(): void {
		$out = Text::clip( "\xff\xfe ok \x80", 100 );
		$this->assertSame( 1, preg_match( '//u', $out ) );
		$this->assertSame( '?? ok ?', $out );
	}
}
