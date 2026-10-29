<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Support;

use LightweightPlugins\Migration\Support\Memory;
use PHPUnit\Framework\TestCase;

final class MemoryTest extends TestCase {

	public function test_parses_memory_limit_values(): void {
		$this->assertSame( 268435456, Memory::bytes( '256M' ) );
		$this->assertSame( 268435456, Memory::bytes( '256m' ) );
		$this->assertSame( 1073741824, Memory::bytes( '1G' ) );
		$this->assertSame( 524288, Memory::bytes( '512K' ) );
		$this->assertSame( 134217728, Memory::bytes( '134217728' ) );
		$this->assertNull( Memory::bytes( '-1' ) );
		$this->assertNull( Memory::bytes( '' ) );
		$this->assertNull( Memory::bytes( 'lots' ) );
	}

	public function test_available_is_null_without_a_limit(): void {
		$old = (string) ini_get( 'memory_limit' );
		try {
			$this->assertNotFalse( ini_set( 'memory_limit', '-1' ) );
			$this->assertNull( Memory::available() );
			$this->assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 33554432 ) ) );
			$this->assertGreaterThan( 0, Memory::available() );
			$this->assertLessThanOrEqual( 33554432, Memory::available() );
		} finally {
			ini_set( 'memory_limit', $old );
		}
	}
}
