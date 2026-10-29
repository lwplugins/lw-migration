<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Archive;

use LightweightPlugins\Migration\Archive\ChunkCodec;
use LightweightPlugins\Migration\Archive\Cipher;
use LightweightPlugins\Migration\Archive\Codec;
use LightweightPlugins\Migration\Archive\CorruptedEntryException;
use LightweightPlugins\Migration\Archive\Format;
use PHPUnit\Framework\TestCase;

final class ChunkCodecTest extends TestCase {

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function modes(): array {
		return [
			'plain'          => [ Codec::NONE, false ],
			'gzip'           => [ Codec::GZIP, false ],
			'encrypted'      => [ Codec::NONE, true ],
			'encrypted gzip' => [ Codec::GZIP, true ],
		];
	}

	/**
	 * @dataProvider modes
	 */
	public function test_stream_round_trip_over_several_chunks( string $compression, bool $encrypted ): void {
		$codec  = new ChunkCodec( $compression, $encrypted ? new Cipher( 'pw' ) : null );
		$data   = random_bytes( 2 * Format::CHUNK_SIZE + 12345 );
		$stored = '';
		foreach ( str_split( $data, Format::CHUNK_SIZE ) as $chunk ) {
			$stored .= $codec->encode( $chunk );
		}

		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, $stored . 'TRAILING' );
		rewind( $handle );

		$out       = '';
		$remaining = strlen( $stored );
		while ( $remaining > 0 ) {
			[ $decoded, $used ] = $codec->decodeNext( $handle, $remaining );
			$out               .= $decoded;
			$remaining         -= $used;
		}
		$this->assertSame( $data, $out );
		$this->assertSame( 'TRAILING', fread( $handle, 8 ) );
	}

	public function test_compressed_chunk_has_length_prefix(): void {
		$encoded = ( new ChunkCodec( Codec::GZIP ) )->encode( 'hello' );
		$this->assertSame( gzcompress( 'hello', 9 ), substr( $encoded, 4 ) );
		$this->assertSame( strlen( $encoded ) - 4, unpack( 'N', substr( $encoded, 0, 4 ) )[1] );
	}

	public function test_encrypted_only_has_no_prefix(): void {
		$this->assertSame( 32, strlen( ( new ChunkCodec( Codec::NONE, new Cipher( 'pw' ) ) )->encode( 'hello' ) ) );
	}

	public function test_length_prefix_beyond_entry_is_corrupted(): void {
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, pack( 'N', 999 ) . 'abc' );
		rewind( $handle );
		$this->expectException( CorruptedEntryException::class );
		( new ChunkCodec( Codec::GZIP ) )->decodeNext( $handle, 7 );
	}

	public function test_oversized_compressed_chunk_is_rejected(): void {
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, pack( 'N', Format::CHUNK_SIZE + 65537 ) . 'abc' );
		rewind( $handle );
		$this->expectException( CorruptedEntryException::class );
		$this->expectExceptionMessage( 'Chunk is larger than the format allows.' );
		( new ChunkCodec( Codec::GZIP ) )->decodeNext( $handle, Format::CHUNK_SIZE * 2 );
	}

	public function test_short_stream_is_corrupted(): void {
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, 'abc' );
		rewind( $handle );
		$this->expectException( CorruptedEntryException::class );
		ChunkCodec::plain()->decodeNext( $handle, 10 );
	}
}
