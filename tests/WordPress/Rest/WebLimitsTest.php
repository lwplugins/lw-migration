<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\Steps\UrlDownloadStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Rest\WebLimits;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class WebLimitsTest extends MonkeyTestCase {

	private string $memoryLimit;

	protected function setUp(): void {
		parent::setUp();
		$this->memoryLimit = (string) ini_get( 'memory_limit' );
	}

	protected function tearDown(): void {
		ini_set( 'memory_limit', $this->memoryLimit );
		parent::tearDown();
	}

	public function test_tick_seconds_follow_the_filter_and_half_the_time_limit(): void {
		$old = (string) ini_get( 'max_execution_time' );
		try {
			ini_set( 'max_execution_time', '0' );
			$this->assertSame( 8.0, WebLimits::tickSeconds() );
			ini_set( 'max_execution_time', '30' );
			$this->assertSame( 8.0, WebLimits::tickSeconds() );
			ini_set( 'max_execution_time', '6' );
			$this->assertSame( 3.0, WebLimits::tickSeconds() );
			ini_set( 'max_execution_time', '0' );
			Filters\expectApplied( 'lw_migration_web_tick_seconds' )->once()->andReturn( 0.2 );
			$this->assertSame( 1.0, WebLimits::tickSeconds(), 'Never below one second.' );
		} finally {
			ini_set( 'max_execution_time', $old );
		}
	}

	public function test_a_chunk_fits_the_request_body_limit(): void {
		$this->assertSame( WebLimits::MAX_CHUNK, WebLimits::chunkFor( null, 0 ), 'No memory limit, post_max_size 0 = no limit.' );
		$this->assertSame( 2097152 - 65536, WebLimits::chunkFor( null, 2097152 ) );
		$this->assertSame( 100000 - 65536, WebLimits::chunkFor( null, 100000 ), 'The request body limit wins over the smallest chunk: a larger body is lost.' );
		$this->assertSame( WebLimits::MAX_CHUNK, WebLimits::chunkFor( null, 67108864 ) );
	}

	public function test_a_chunk_fits_upload_max_filesize_too(): void {
		$this->assertSame( 2097152, WebLimits::chunkFor( null, 67108864, 2097152 ), 'A slice is sent as a file part: upload_max_filesize limits it.' );
		$this->assertSame( 2097152 - 65536, WebLimits::chunkFor( null, 2097152, 8388608 ) );
		$this->assertSame( WebLimits::MAX_CHUNK, WebLimits::chunkFor( null, 0, 0 ) );
	}

	public function test_a_chunk_takes_a_quarter_of_the_memory_limit_above_a_fixed_baseline(): void {
		$mib = 1048576;
		$this->assertSame( WebLimits::MAX_CHUNK, WebLimits::chunkFor( 256 * $mib, 0 ) );
		$this->assertSame( 8 * $mib, WebLimits::chunkFor( 80 * $mib, 0 ) );
		$this->assertSame( 4 * $mib, WebLimits::chunkFor( 64 * $mib, 0 ) );
		$this->assertSame( WebLimits::MIN_CHUNK, WebLimits::chunkFor( 49 * $mib, 0 ) );
		$this->assertSame( WebLimits::MIN_CHUNK, WebLimits::chunkFor( 32 * $mib, 0 ), 'Never below the smallest chunk.' );
		$this->assertSame( 2097152 - 65536, WebLimits::chunkFor( 64 * $mib, 2097152 ) );
	}

	public function test_the_chunk_size_is_a_stable_contract_not_the_live_memory_use(): void {
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		$limit = memory_get_usage( true ) + 16777216;
		$this->assertTrue( false !== ini_set( 'memory_limit', (string) $limit ) );

		$before  = WebLimits::chunkBytes();
		$ballast = str_repeat( 'x', 8388608 );
		$after   = WebLimits::chunkBytes();

		$this->assertSame( WebLimits::chunkFor( $limit, 0 ), $before, 'From the limit, not from what is still free (a quarter of that would be 4 MiB at most).' );
		$this->assertSame( $before, $after, 'The same size on every request, whatever this one already holds.' );
		$this->assertSame( 8388608, strlen( $ballast ) );

		ini_set( 'memory_limit', '-1' );
		$this->assertSame( WebLimits::MAX_CHUNK, WebLimits::chunkBytes() );
	}

	public function test_a_tick_length_that_is_not_a_finite_number_falls_back(): void {
		$old = (string) ini_get( 'max_execution_time' );
		try {
			ini_set( 'max_execution_time', '0' );
			Filters\expectApplied( 'lw_migration_web_tick_seconds' )->twice()->andReturn( NAN, INF );
			$this->assertSame( WebLimits::TICK_SECONDS, WebLimits::tickSeconds() );
			$this->assertSame( WebLimits::TICK_SECONDS, WebLimits::tickSeconds() );
		} finally {
			ini_set( 'max_execution_time', $old );
		}
	}

	public function test_only_a_download_that_may_not_fit_one_request_is_refused(): void {
		$job = new Job( '0123456789abcdef', 'import', [ 'url_resolve', 'url_download' ], 1 );
		foreach ( [ [ false, null, true ], [ true, null, true ], [ false, WebLimits::WHOLE_DOWNLOAD + 1, true ], [ false, WebLimits::WHOLE_DOWNLOAD, false ], [ true, 10 * WebLimits::WHOLE_DOWNLOAD, false ] ] as [ $ranges, $size, $refused ] ) {
			$job->state = [
				'download' => [
					'ranges' => $ranges,
					'size'   => $size,
				],
			];
			$this->assertSame( $refused, null !== WebLimits::tooLargeForOneRequest( $job ) );
		}
		$job->stepIndex = 0;
		$this->assertNull( WebLimits::tooLargeForOneRequest( $job ), 'Only the download step is checked.' );
	}

	public function test_upload_room_and_whole_downloads(): void {
		$this->assertSame( 2147483648 - UrlDownloadStep::DISK_MARGIN, WebLimits::uploadRoom( 2147483648 ) );
		$this->assertSame( 0, WebLimits::uploadRoom( 100 ) );
		$this->assertSame( UrlDownloadStep::MAX_DOWNLOAD, WebLimits::uploadRoom( null ) );
		Filters\expectApplied( 'lw_migration_max_upload_bytes' )->once()->andReturn( 1000 );
		$this->assertSame( 1000, WebLimits::uploadRoom( null ) );
		$this->assertSame( WebLimits::WHOLE_DOWNLOAD, WebLimits::wholeDownloadBytes() );
	}
}
