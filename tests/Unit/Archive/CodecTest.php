<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ArchiveException;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase {

	public function test_gzip_is_zlib_stream_level_9(): void {
		$data = str_repeat( 'abc', 1000 );
		$this->assertSame( gzcompress( $data, 9 ), Codec::compress( Codec::GZIP, $data ) );
		$this->assertSame( $data, Codec::decompress( Codec::GZIP, Codec::compress( Codec::GZIP, $data ), 512000 ) );
	}

	public function test_bzip2_round_trip(): void {
		if ( ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'ext-bz2 not installed' );
		}
		$data = random_bytes( 2000 );
		$this->assertSame( bzcompress( $data, 9 ), Codec::compress( Codec::BZIP2, $data ) );
		$this->assertSame( $data, Codec::decompress( Codec::BZIP2, Codec::compress( Codec::BZIP2, $data ), 512000 ) );
	}

	public function test_none_passes_through(): void {
		$this->assertSame( 'x', Codec::compress( Codec::NONE, 'x' ) );
		$this->assertSame( 'x', Codec::decompress( Codec::NONE, 'x', 512000 ) );
	}

	public function test_corrupted_gzip(): void {
		$this->expectException( CorruptedEntryException::class );
		Codec::decompress( Codec::GZIP, 'not zlib', 512000 );
	}

	public function test_corrupted_bzip2(): void {
		if ( ! function_exists( 'bzdecompress' ) ) {
			$this->markTestSkipped( 'ext-bz2 not installed' );
		}
		$this->expectException( CorruptedEntryException::class );
		Codec::decompress( Codec::BZIP2, 'not bzip2', 512000 );
	}

	public function test_full_chunk_round_trip_at_the_limit(): void {
		$data = random_bytes( 512000 );
		$this->assertSame( $data, Codec::decompress( Codec::GZIP, Codec::compress( Codec::GZIP, $data ), 512000 ) );
		if ( function_exists( 'bzcompress' ) ) {
			$this->assertSame( $data, Codec::decompress( Codec::BZIP2, Codec::compress( Codec::BZIP2, $data ), 512000 ) );
		}
	}

	public function test_gzip_bomb_is_rejected(): void {
		$bomb = Codec::compress( Codec::GZIP, str_repeat( "\0", 2000000 ) );
		$this->expectException( CorruptedEntryException::class );
		Codec::decompress( Codec::GZIP, $bomb, 512000 );
	}

	public function test_bzip2_bomb_is_rejected(): void {
		if ( ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'ext-bz2 not installed' );
		}
		$bomb = Codec::compress( Codec::BZIP2, str_repeat( "\0", 2000000 ) );
		$this->expectException( CorruptedEntryException::class );
		Codec::decompress( Codec::BZIP2, $bomb, 512000 );
	}

	public function test_large_bzip2_bomb_is_rejected_with_bounded_memory(): void {
		if ( ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'ext-bz2 not installed' );
		}
		$bomb = self::bzip2Zeros( 200 );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$base  = memory_get_usage();
		$threw = false;
		try {
			Codec::decompress( Codec::BZIP2, $bomb, 512000 );
		} catch ( CorruptedEntryException $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'The bomb must be rejected.' );
		$this->assertLessThan( 80 * 1024 * 1024, memory_get_peak_usage() - $base );
	}

	/**
	 * Bzip2 stream of $megabytes MiB of NUL bytes, built without holding the payload in memory.
	 */
	private static function bzip2Zeros( int $megabytes ): string {
		$handle = fopen( 'php://temp/maxmemory:0', 'w+b' );
		$filter = stream_filter_append( $handle, 'bzip2.compress', STREAM_FILTER_WRITE, [ 'blocks' => 9 ] );
		$zeros  = str_repeat( "\0", 1048576 );
		for ( $i = 0; $i < $megabytes; $i++ ) {
			fwrite( $handle, $zeros );
		}
		stream_filter_remove( $filter );
		rewind( $handle );
		$bomb = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $bomb;
	}

	public function test_unknown_type(): void {
		$this->expectException( ArchiveException::class );
		Codec::assertSupported( 'zstd' );
	}
}
