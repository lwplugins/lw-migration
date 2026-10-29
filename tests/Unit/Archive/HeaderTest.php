<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Header;
use LightweightPlugins\Migration\Archive\InvalidArchiveException;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase {

	public function test_pack_layout_is_byte_exact(): void {
		$block = Header::pack( 'uploads/2026/a.txt', 1234, 1767225600, 'abcdef01' );

		$this->assertSame( Format::HEADER_SIZE, strlen( $block ) );
		$this->assertSame( 'a.txt' . str_repeat( "\0", 250 ), substr( $block, 0, 255 ) );
		$this->assertSame( '1234' . str_repeat( "\0", 10 ), substr( $block, 255, 14 ) );
		$this->assertSame( '1767225600' . "\0\0", substr( $block, 269, 12 ) );
		$this->assertSame( 'uploads/2026' . str_repeat( "\0", 4076 ), substr( $block, 281, 4088 ) );
		$this->assertSame( 'abcdef01', substr( $block, 4369, 8 ) );
	}

	public function test_root_entry_uses_dot_path(): void {
		$block = Header::pack( 'package.json', 5, 1 );
		$this->assertSame( '.', rtrim( substr( $block, 281, 4088 ), "\0" ) );
		$this->assertSame( str_repeat( "\0", 8 ), substr( $block, 4369, 8 ) );
	}

	public function test_round_trip_keeps_unicode_and_spaces(): void {
		$name  = 'uploads/2026/10/ékezetes fájl.txt';
		$entry = Header::unpack( Header::pack( $name, 0, 7, '00000000' ), 4377 );

		$this->assertSame( $name, $entry->name );
		$this->assertSame( 0, $entry->size );
		$this->assertSame( 7, $entry->mtime );
		$this->assertSame( '00000000', $entry->crc );
		$this->assertSame( 4377, $entry->headerOffset );
		$this->assertSame( 4377 * 2, $entry->dataOffset() );
	}

	public function test_split(): void {
		$this->assertSame( [ '.', 'a.txt' ], Header::split( 'a.txt' ) );
		$this->assertSame( [ 'x/y', 'z.txt' ], Header::split( 'x/y/z.txt' ) );
		$this->assertSame( [ 'x', 'z.txt' ], Header::split( 'x\\z.txt' ) );
	}

	public function test_name_too_long_is_rejected(): void {
		$this->expectException( ArchiveException::class );
		Header::pack( str_repeat( 'a', 256 ), 1, 1 );
	}

	public function test_path_too_long_is_rejected(): void {
		$this->expectException( ArchiveException::class );
		Header::pack( str_repeat( 'd', 4089 ) . '/a', 1, 1 );
	}

	public function test_leading_slash_is_rejected(): void {
		try {
			Header::pack( '/a.txt', 1, 1 );
			$this->fail( 'An absolute entry name must be rejected.' );
		} catch ( ArchiveException $e ) {
			$this->assertSame( 'Entry names must be relative: /a.txt', $e->getMessage() );
		}
		$this->expectException( ArchiveException::class );
		Header::pack( '\\x\\a.txt', 1, 1 );
	}

	public function test_garbage_header_is_invalid(): void {
		$this->expectException( InvalidArchiveException::class );
		Header::unpack( str_repeat( 'x', Format::HEADER_SIZE ), 0 );
	}

	public function test_short_header_is_invalid(): void {
		$this->expectException( InvalidArchiveException::class );
		Header::unpack( 'short', 0 );
	}

	public function test_config_detection(): void {
		$this->assertTrue( Header::unpack( Header::pack( 'package.json', 1, 1 ), 0 )->isConfig() );
		$this->assertFalse( Header::unpack( Header::pack( 'x/package.json', 1, 1 ), 0 )->isConfig() );
	}
}
