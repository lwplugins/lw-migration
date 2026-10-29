<?php
/**
 * DumpCursor serialisation tests.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\DumpCursor;
use LightweightPlugins\Migration\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DumpCursorTest extends TestCase {

	public function test_binary_last_key_survives_json_round_trip(): void {
		$cursor          = new DumpCursor( 10, 2, true, 5, [ "\x00\xff", 'a' ] );
		$json            = json_encode( $cursor->toArray() );
		$this->assertIsString( $json );
		$back = DumpCursor::fromArray( json_decode( $json, true ) );
		$this->assertSame( [ "\x00\xff", 'a' ], $back->lastKey );
		$this->assertSame( 10, $back->fileOffset );
		$this->assertSame( 2, $back->index );
		$this->assertTrue( $back->started );
		$this->assertSame( 5, $back->rows );
	}

	public function test_null_key_and_legacy_plain_key(): void {
		$this->assertNull( DumpCursor::fromArray( ( new DumpCursor() )->toArray() )->lastKey );
		$this->assertSame( [ '7' ], DumpCursor::fromArray( [ 'lastKey' => [ 7 ] ] )->lastKey );
	}

	public function test_next_table_resets(): void {
		$cursor = new DumpCursor( 1, 0, true, 3, [ '1' ] );
		$cursor->nextTable();
		$this->assertSame( 1, $cursor->index );
		$this->assertFalse( $cursor->started );
		$this->assertSame( 0, $cursor->rows );
		$this->assertNull( $cursor->lastKey );
	}

	public function test_invalid_base64_key_throws(): void {
		$this->expectException( DatabaseException::class );
		DumpCursor::fromArray( [ 'lastKey64' => [ '***' ] ] );
	}
}
