<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\GrowingParts;
use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use PHPUnit\Framework\TestCase;

final class GrowingPartsTest extends TestCase {

	public function test_starts_at_one_mebibyte_and_stays_under_a_quarter_of_free_memory(): void {
		$this->assertSame( 1048576, GrowingParts::policy( null )->first() );
		$tight = GrowingParts::policy( 8 * 1048576 );
		$this->assertSame( 2097152, $tight->next( 2097152, 0.1 ), 'A quarter of 8 MiB.' );
		$this->assertSame( 65536, GrowingParts::policy( 1000 )->first(), 'Never below one multiple.' );
		$this->assertSame( 65536, GrowingParts::policy( -5 )->first(), 'Over the limit already: one multiple.' );
		$this->assertSame( GrowingParts::MAX, GrowingParts::policy( null )->next( GrowingParts::MAX, 0.1 ) );
		$this->assertSame( 0, GrowingParts::policy( 5000000 )->next( 1048576, 0.1 ) % GrowingParts::MULTIPLE, 'Always whole 64 KiB.' );
	}

	public function test_the_next_part_doubles_when_fast_halves_when_slow_and_keeps_the_rest_of_the_session(): void {
		$s      = new UploadSession( 'ftp', 'a.wpress', 100000000, 0, 4194304, 'a.wpress.part', [ 'x' => 1 ] );
		$policy = GrowingParts::policy( null );

		$fast = GrowingParts::after( $s, 4194304, 0.5, $policy );
		$slow = GrowingParts::after( $s, 4194304, 30.0, $policy );

		$this->assertSame( [ 4194304, 8388608 ], [ $fast->offset, $fast->chunk ] );
		$this->assertSame( 2097152, $slow->chunk );
		$this->assertSame( [ 'ftp', 'a.wpress', 100000000, 'a.wpress.part', [ 'x' => 1 ] ], [ $fast->type, $fast->name, $fast->size, $fast->remote, $fast->extra ] );
	}
}
