<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations\Dropbox;

use LightweightPlugins\Migration\Storage\Destinations\Dropbox\ContentHash;
use PHPUnit\Framework\TestCase;

/**
 * Dropbox's content_hash (docs.dropboxapi.com/dropbox-api/docs/technical-reference/content-hash,
 * read 2026-10-14): SHA-256 of every 4 MiB block, the binary digests
 * joined, SHA-256 of that in hex.
 */
final class ContentHashTest extends TestCase {

	private const BLOCK = 4194304;

	public function test_the_joined_block_digests_hash_to_the_documented_example(): void {
		$blocks = hex2bin(
			'2a846fa617c3361fc117e1c5c1e1838c336b6a5cef982c1a2d9bdf68f2f1992a'
			. 'c68469027410ea393eba6551b9fa1e26db775f00eae70a0c3c129a0011a39cf9'
			. '7376192de020925ce6c5ef5a8a0405e931b0a9a8c75517aacd9ca24a8a56818b'
		);

		$this->assertSame( '485291fa0ee50c016982abbfa943957bcd231aae0492ccbaa22c58e3997b35e0', ContentHash::of( (string) $blocks ) );
	}

	public function test_bytes_are_hashed_per_4_mib_block_and_the_last_block_may_be_short(): void {
		$bytes = str_repeat( 'a', self::BLOCK ) . 'b';

		$blocks = ContentHash::blocks( $bytes );

		$this->assertSame( hash( 'sha256', str_repeat( 'a', self::BLOCK ), true ) . hash( 'sha256', 'b', true ), $blocks );
		$this->assertSame( 2, ContentHash::count( strlen( $bytes ) ) );
		$this->assertSame( 64, strlen( $blocks ) );
	}

	public function test_an_empty_file_has_no_block(): void {
		$this->assertSame( '', ContentHash::blocks( '' ) );
		$this->assertSame( 0, ContentHash::count( 0 ) );
		$this->assertSame( hash( 'sha256', '' ), ContentHash::of( '' ) );
	}

	public function test_blocks_of_parts_on_block_boundaries_join_to_the_blocks_of_the_whole(): void {
		$whole = str_repeat( 'x', self::BLOCK ) . str_repeat( 'y', self::BLOCK ) . 'z';

		$this->assertSame( ContentHash::blocks( $whole ), ContentHash::blocks( substr( $whole, 0, self::BLOCK ) ) . ContentHash::blocks( substr( $whole, self::BLOCK ) ) );
	}
}
