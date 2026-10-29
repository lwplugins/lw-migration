<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Rest\ResetResult;
use PHPUnit\Framework\TestCase;

final class ResetResultTest extends TestCase {

	public function test_a_database_reset_reports_its_tables(): void {
		$job = new Job( '0123456789abcdef', 'reset', [ 'reset_db_finish' ], 0, [], [ 'type' => 'database' ], [
			'removed' => [
				'database' => [
					'replaced' => 12,
					'retired'  => 3,
					'kept'     => 2,
					'views'    => [ 'wp_report' ],
				],
			],
		] );

		$this->assertSame(
			[
				'replaced' => 12,
				'retired'  => 3,
				'kept'     => 2,
				'views'    => [ 'wp_report' ],
			],
			ResetResult::of( $job )['database']
		);
	}

	public function test_a_reset_without_its_database_part_reports_none(): void {
		$job = new Job( '0123456789abcdef', 'reset', [ 'reset_media' ], 0, [], [ 'type' => 'media' ] );

		$this->assertNull( ResetResult::of( $job )['database'] );
	}
}
