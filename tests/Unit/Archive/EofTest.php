<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\Eof;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use PHPUnit\Framework\TestCase;

final class EofTest extends TestCase {

	public function test_v1_is_all_nul(): void {
		$this->assertSame( str_repeat( "\0", Format::HEADER_SIZE ), Eof::v1() );
		$info = Eof::parse( Eof::v1() );
		$this->assertNotNull( $info );
		$this->assertSame( 1, $info->version );
		$this->assertNull( $info->size );
	}

	public function test_v2_layout_and_parse(): void {
		$block = Eof::v2( 987654, '0a1b2c3d' );
		$this->assertSame( Format::HEADER_SIZE, strlen( $block ) );
		$this->assertSame( str_repeat( "\0", 255 ), substr( $block, 0, 255 ) );
		$this->assertSame( '987654' . str_repeat( "\0", 8 ), substr( $block, 255, 14 ) );
		$this->assertSame( '0a1b2c3d', substr( $block, 4369, 8 ) );

		$info = Eof::parse( $block );
		$this->assertNotNull( $info );
		$this->assertSame( 2, $info->version );
		$this->assertSame( 987654, $info->size );
		$this->assertSame( '0a1b2c3d', $info->crc );
	}

	public function test_file_header_is_not_eof(): void {
		$this->assertNull( Eof::parse( Header::pack( 'a.txt', 1, 1, '0a1b2c3d' ) ) );
	}

	public function test_wrong_length_is_not_eof(): void {
		$this->assertNull( Eof::parse( str_repeat( "\0", 10 ) ) );
	}

	public function test_v2_with_garbage_in_padding_is_not_eof(): void {
		$block        = Eof::v2( 5, '0a1b2c3d' );
		$block[ 300 ] = 'x';
		$this->assertNull( Eof::parse( $block ) );
	}
}
