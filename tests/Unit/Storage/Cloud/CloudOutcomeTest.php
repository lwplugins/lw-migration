<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use PHPUnit\Framework\TestCase;

final class CloudOutcomeTest extends TestCase {

	private static function job(): Job {
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = ( new CloudPlan(
			[
				[
					'id'        => 'aaaa1111',
					'retention' => null,
				],
				[
					'id'        => 'bbbb2222',
					'retention' => null,
				],
			],
			false
		) )->toArray();

		return $job;
	}

	public function test_all_ok_only_when_every_destination_succeeded(): void {
		$job  = self::job();
		$plan = CloudPlan::of( $job );
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3', '', 'k/a.wpress', 5 );

		$this->assertFalse( CloudOutcome::allOk( $job, $plan ), 'One destination has no result yet.' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::SKIPPED, 'Two', 's3', 'The destination was deleted.' );
		$this->assertFalse( CloudOutcome::allOk( $job, $plan ), 'Skipped is not ok.' );
		$this->assertTrue( CloudOutcome::partial( $job ) );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );
		$this->assertTrue( CloudOutcome::allOk( $job, $plan ) );
		$this->assertFalse( CloudOutcome::partial( $job ) );
	}

	public function test_the_view_and_the_log_lines_carry_names_status_and_clipped_messages(): void {
		$job = self::job();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::FAILED, 'One', 's3', str_repeat( 'x', 900 ) );
		CloudOutcome::addPruned( $job, 'aaaa1111', [ 'old.wpress' ] );

		$view = CloudOutcome::view( $job );
		$this->assertSame( [ 'aaaa1111', 'One', 's3', 'failed', 1 ], [ $view[0]['id'], $view[0]['name'], $view[0]['type'], $view[0]['status'], $view[0]['pruned'] ] );
		$log = CloudOutcome::forLog( $job );
		$this->assertSame( [ 'name', 'status', 'error' ], array_keys( $log[0] ) );
		$this->assertLessThanOrEqual( 500, strlen( $log[0]['error'] ) );
	}

	public function test_a_job_without_a_plan_is_never_partial(): void {
		$this->assertFalse( CloudOutcome::partial( new Job( '0123456789abcdef', 'export', [] ) ) );
		$this->assertSame( [], CloudOutcome::view( new Job( '0123456789abcdef', 'export', [] ) ) );
	}
}
