<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Crc32;
use LightweightPlugins\Migration\Archive\IncrementalCrc;
use PHPUnit\Framework\TestCase;

final class Crc32Test extends TestCase {

	public function test_of_string_matches_zlib_crc32(): void {
		$this->assertSame( '352441c2', Crc32::ofString( 'abc' ) );
		$this->assertSame( sprintf( '%08x', crc32( 'hello' ) ), Crc32::ofString( 'hello' ) );
		$this->assertSame( Crc32::EMPTY, Crc32::ofString( '' ) );
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function lengths(): array {
		return [
			'empty second'  => [ 10, 0 ],
			'empty first'   => [ 0, 10 ],
			'one byte'      => [ 5, 1 ],
			'odd'           => [ 7, 13 ],
			'chunk'         => [ 1000, 512000 ],
			'big both'      => [ 512000, 600001 ],
		];
	}

	/**
	 * @dataProvider lengths
	 */
	public function test_combine_equals_crc_of_concatenation( int $lenA, int $lenB ): void {
		$a = random_bytes( max( 1, $lenA ) );
		$a = substr( $a, 0, $lenA );
		$b = substr( random_bytes( max( 1, $lenB ) ), 0, $lenB );

		$this->assertSame(
			Crc32::ofString( $a . $b ),
			Crc32::combine( Crc32::ofString( $a ), Crc32::ofString( $b ), strlen( $b ) )
		);
	}

	public function test_combine_rejects_non_hex(): void {
		$this->expectException( ArchiveException::class );
		Crc32::combine( 'zzzzzzzz', Crc32::EMPTY, 0 );
	}

	public function test_incremental_crc_across_sessions(): void {
		$data  = random_bytes( 1300000 );
		$first = new IncrementalCrc();
		$first->update( substr( $data, 0, 700000 ) );
		$saved = $first->value();

		$second = new IncrementalCrc( $saved );
		$second->update( substr( $data, 700000, 300000 ) );
		$this->assertSame( Crc32::ofString( substr( $data, 0, 1000000 ) ), $second->value() );
		$second->update( substr( $data, 1000000 ) );

		$this->assertSame( Crc32::ofString( $data ), $second->value() );
	}
}
