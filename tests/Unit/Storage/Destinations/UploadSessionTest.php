<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Destinations;

use LightweightPlugins\Migration\Storage\Destinations\UploadSession;
use PHPUnit\Framework\TestCase;

final class UploadSessionTest extends TestCase {

	public function test_round_trips_through_an_array_and_moves_forward_immutably(): void {
		$session = new UploadSession( 's3', 'site-20261012-030000-123.wpress', 12, 0, 5, 'upload-id', [ 'parts' => [] ] );
		$next    = $session->with( 5, [ 'parts' => [ 1 => '"e1"' ] ] );

		$this->assertSame( 0, $session->offset );
		$this->assertSame( 5, $next->offset );
		$this->assertSame( 5, $next->nextLength() );
		$this->assertSame( 2, $next->with( 10, [] )->nextLength() );
		$this->assertTrue( $next->with( 12, [] )->isComplete() );
		$this->assertEquals( $next, UploadSession::fromArray( json_decode( (string) json_encode( $next->toArray() ), true ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function broken(): array {
		$good = ( new UploadSession( 's3', 'a.wpress', 10, 0, 5, 'id' ) )->toArray();
		return [
			'no type'           => [ array_merge( $good, [ 'type' => '' ] ) ],
			'offset past size'  => [ array_merge( $good, [ 'offset' => 11 ] ) ],
			'negative offset'   => [ array_merge( $good, [ 'offset' => -1 ] ) ],
			'zero chunk'        => [ array_merge( $good, [ 'chunk' => 0 ] ) ],
			'size as string'    => [ array_merge( $good, [ 'size' => '10' ] ) ],
			'extra not a map'   => [ array_merge( $good, [ 'extra' => 'x' ] ) ],
			'name with a slash' => [ array_merge( $good, [ 'name' => '../a.wpress' ] ) ],
		];
	}

	/**
	 * @param array<string, mixed> $data Stored session.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'broken' )]
	public function test_a_damaged_stored_session_reads_as_none( array $data ): void {
		$this->assertNull( UploadSession::fromArray( $data ) );
	}
}
