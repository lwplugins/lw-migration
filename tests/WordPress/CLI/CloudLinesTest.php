<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\CLI;

use LightweightPlugins\Migration\CLI\CloudLines;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Storage\Cloud\CloudOutcome;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class CloudLinesTest extends MonkeyTestCase {

	public function test_one_line_per_destination_failures_as_warnings(): void {
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = CloudPlan::manual( [ 'aaaa1111', 'bbbb2222', 'cccc3333' ], true )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::FAILED, 'Two', 's3', 'Access denied.' );
		CloudOutcome::record( $job, 'cccc3333', CloudOutcome::SKIPPED, 'cccc3333', '', 'This destination was deleted.' );

		$this->assertSame(
			[
				[ 'log', 'Uploaded to One.' ],
				[ 'warning', 'Upload to Two failed: Access denied.' ],
				[ 'warning', 'Skipped cccc3333: This destination was deleted.' ],
			],
			CloudLines::of( $job )
		);
	}

	public function test_schedule_log_rows_become_the_same_lines(): void {
		$this->assertSame(
			[
				[ 'log', 'Uploaded to One.' ],
				[ 'warning', 'Upload to Two failed: Access denied.' ],
				[ 'warning', 'Skipped Three: This destination was deleted.' ],
			],
			CloudLines::ofLog(
				[
					[ 'name' => 'One', 'status' => 'ok', 'error' => '' ],
					[ 'name' => 'Two', 'status' => 'failed', 'error' => 'Access denied.' ],
					[ 'name' => 'Three', 'status' => 'skipped', 'error' => 'This destination was deleted.' ],
				]
			)
		);
	}

	public function test_a_pending_row_is_not_uploaded_and_falls_back_to_the_id(): void {
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = CloudPlan::manual( [ 'aaaa1111' ], true )->toArray();

		$this->assertSame( [ [ 'warning', 'Not uploaded to aaaa1111.' ] ], CloudLines::of( $job ) );
	}

	public function test_conclude_adds_the_summary_only_for_a_partial_result(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-cli-class.php';
		\WP_CLI::$lines = [];
		$job                 = new Job( '0123456789abcdef', 'export', [] );
		$job->state['cloud'] = CloudPlan::manual( [ 'aaaa1111', 'bbbb2222' ], true )->toArray();
		CloudOutcome::record( $job, 'aaaa1111', CloudOutcome::OK, 'One', 's3' );
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::FAILED, 'Two', 's3', 'Access denied.' );

		$this->assertTrue( CloudLines::conclude( $job ) );
		$this->assertSame( 'Warning: The backup is ready, but not every upload succeeded.', end( \WP_CLI::$lines ) );

		\WP_CLI::$lines = [];
		CloudOutcome::record( $job, 'bbbb2222', CloudOutcome::OK, 'Two', 's3' );
		$this->assertFalse( CloudLines::conclude( $job ) );
		$this->assertSame( [ 'Uploaded to One.', 'Uploaded to Two.' ], \WP_CLI::$lines );
	}
}
