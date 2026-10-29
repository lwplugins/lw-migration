<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Destinations;

use Brain\Monkey\Filters;
use LightweightPlugins\Migration\Storage\Destinations\CloudTimeout;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudTimeoutTest extends MonkeyTestCase {

	public function test_the_filtered_request_timeout_stays_between_ten_seconds_and_ten_minutes(): void {
		$this->assertSame( 120, CloudTimeout::seconds() );
		Filters\expectApplied( 'lw_migration_cloud_timeout' )->andReturn( 3 );
		$this->assertSame( 10, CloudTimeout::seconds() );
	}

	public function test_a_whole_file_gets_the_time_its_size_needs_at_the_given_rate_at_most_six_hours(): void {
		$this->assertSame( 120, CloudTimeout::forBytes( 1048576, 262144 ), 'Never below the request timeout.' );
		$this->assertSame( 4000, CloudTimeout::forBytes( 4000 * 262144, 262144 ) );
		$this->assertSame( 21600, CloudTimeout::forBytes( PHP_INT_MAX, 262144 ) );
	}
}
