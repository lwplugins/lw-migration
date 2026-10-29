<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Http;

use LightweightPlugins\Migration\Http\DownloadException;
use LightweightPlugins\Migration\Http\RangeResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RangeResponseTest extends TestCase {

	public function test_total_from_start_reads_the_file_size(): void {
		$this->assertSame( 9000, RangeResponse::totalFromStart( 'bytes 0-4376/9000' ) );
		$this->assertNull( RangeResponse::totalFromStart( 'bytes 0-4376/*' ) );

		$this->expectException( DownloadException::class );
		RangeResponse::totalFromStart( 'bytes 100-4476/9000' );
	}

	/**
	 * @return array<string, array{0: string, 1: int|null, 2: int|null}>
	 */
	public static function ranges(): array {
		return [
			'exact'           => [ 'bytes 3-6/10', 10, 6 ],
			'end of the file' => [ 'bytes 3-5/6', null, 5 ],
			'unknown total'   => [ 'bytes 3-6/*', 10, 6 ],
			'other start'     => [ 'bytes 2-5/10', null, null ],
			'beyond the end'  => [ 'bytes 3-9/10', null, null ],
			'end before'      => [ 'bytes 3-2/10', null, null ],
			'end past total'  => [ 'bytes 3-6/5', null, null ],
			'other total'     => [ 'bytes 3-6/99', 10, null ],
			'not a range'     => [ '', null, null ],
		];
	}

	#[DataProvider( 'ranges' )]
	public function test_range_end_accepts_only_a_part_inside_the_request( string $contentRange, ?int $total, ?int $end ): void {
		$this->assertSame( $end, RangeResponse::rangeEnd( $contentRange, 3, 6, $total ) );
	}

	public function test_check_size_tells_a_broken_server_from_a_cut_transfer(): void {
		RangeResponse::checkSize( 4, 4 );
		try {
			RangeResponse::checkSize( 5, 4 );
			$this->fail( 'More bytes were accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertFalse( $e->isPassing() );
		}
		try {
			RangeResponse::checkSize( 3, 4 );
			$this->fail( 'Fewer bytes were accepted.' );
		} catch ( DownloadException $e ) {
			$this->assertTrue( $e->isPassing() );
		}
	}

	public function test_assert_status_refuses_an_unexpected_status(): void {
		RangeResponse::assertStatus( 206, [ 200, 206 ] );
		$this->expectException( DownloadException::class );
		$this->expectExceptionMessage( 'The server answered with HTTP 200.' );
		RangeResponse::assertStatus( 200, [ 206 ] );
	}

	public function test_only_a_busy_server_is_a_passing_status(): void {
		foreach ( RangeResponse::BUSY_STATUSES as $status ) {
			$this->assertTrue( RangeResponse::statusError( $status )->isPassing() );
		}
		$this->assertFalse( RangeResponse::statusError( 404 )->isPassing() );
		$this->assertSame( 'The server answered with HTTP 404.', RangeResponse::statusError( 404 )->getMessage() );
	}
}
