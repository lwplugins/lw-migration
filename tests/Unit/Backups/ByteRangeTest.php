<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Backups;

use LightweightPlugins\Migration\Backups\ByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ByteRangeTest extends TestCase {

	/**
	 * @return array<string, array{0: string|null, 1: int, 2: array{0: int, 1: int}|false|null}>
	 */
	public static function cases(): array {
		return [
			'no header'         => [ null, 100, null ],
			'empty header'      => [ '', 100, null ],
			'closed range'      => [ 'bytes=0-9', 100, [ 0, 9 ] ],
			'open range'        => [ 'bytes=90-', 100, [ 90, 99 ] ],
			'suffix'            => [ 'bytes=-10', 100, [ 90, 99 ] ],
			'suffix too long'   => [ 'bytes=-200', 100, [ 0, 99 ] ],
			'end clipped'       => [ 'bytes=50-500', 100, [ 50, 99 ] ],
			'spaces and case'   => [ 'BYTES = 1 - 2', 100, [ 1, 2 ] ],
			'start past end'    => [ 'bytes=100-', 100, false ],
			'zero suffix'       => [ 'bytes=-0', 100, false ],
			'empty file'        => [ 'bytes=0-', 0, false ],
			'reversed'          => [ 'bytes=5-1', 100, null ],
			'several ranges'    => [ 'bytes=0-1,5-6', 100, null ],
			'other unit'        => [ 'items=0-1', 100, null ],
			'no numbers'        => [ 'bytes=-', 100, null ],
			'number too long'   => [ 'bytes=1234567890123456789-', 100, null ],
		];
	}

	/**
	 * @param string|null                   $header   Range header.
	 * @param int                           $size     File size.
	 * @param array{0: int, 1: int}|false|null $expected Parsed range.
	 */
	#[DataProvider( 'cases' )]
	public function test_parse( ?string $header, int $size, $expected ): void {
		$this->assertSame( $expected, ByteRange::parse( $header, $size ) );
	}
}
