<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\FileCursor;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase {

	public function test_file_cursor_round_trip(): void {
		$c = FileCursor::fromArray( ( new FileCursor( 10, 20, 30, 'abcdef01' ) )->toArray() );
		$this->assertSame( [ 10, 20, 30, 'abcdef01' ], [ $c->headerOffset, $c->sourceOffset, $c->written, $c->crc ] );
		$this->assertNull( FileCursor::fromArray( [] )->headerOffset );
	}

	public function test_crc_cursor_round_trip(): void {
		$c = CrcCursor::fromArray( ( new CrcCursor( 5, 'abcdef01', 99 ) )->toArray() );
		$this->assertSame( [ 5, 'abcdef01', 99 ], [ $c->offset, $c->crc, $c->size ] );
		$this->assertNull( CrcCursor::fromArray( [] )->size );
	}
}
