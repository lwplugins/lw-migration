<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudPlanTest extends MonkeyTestCase {

	public function test_a_manual_plan_round_trips_through_the_job(): void {
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = CloudPlan::manual( [ 'aaaa1111', 'bbbb2222' ], false )->toArray();

		$plan = CloudPlan::of( $job );

		$this->assertSame( [ 'aaaa1111', 'bbbb2222' ], $plan->ids() );
		$this->assertSame( [ false, true ], [ $plan->keepLocal, $plan->prune ] );
		$this->assertNull( $plan->targets[0]['retention'] );
	}

	public function test_an_upload_of_an_existing_backup_never_prunes_or_deletes_it(): void {
		$plan = CloudPlan::upload( [ 'aaaa1111' ] );

		$this->assertSame( [ true, false ], [ $plan->keepLocal, $plan->prune ] );
	}

	public function test_ids_from_a_command_line_list_are_checked(): void {
		$this->assertSame( [ 'aaaa1111', 'bbbb2222' ], CloudPlan::idsFrom( ' aaaa1111, bbbb2222 ' ) );
		foreach ( [ '', 'aaaa1111,aaaa1111', 'AAAA1111', 'aaaa1111,x', implode( ',', array_map( static fn( int $i ): string => sprintf( '%08x', $i ), range( 1, 21 ) ) ) ] as $bad ) {
			try {
				CloudPlan::manual( CloudPlan::idsFrom( $bad ), true );
				$this->fail( 'Refused: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_a_damaged_stored_plan_reads_as_none(): void {
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = [ 'targets' => [ [ 'id' => '../x' ] ] ];

		$this->assertNull( CloudPlan::of( $job ) );
	}
}
