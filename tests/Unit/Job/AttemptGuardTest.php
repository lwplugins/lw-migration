<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\AttemptGuard;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use PHPUnit\Framework\TestCase;

final class AttemptGuardTest extends TestCase {

	public function test_starts_from_the_same_cursor_are_counted_up_to_the_limit(): void {
		$job = new Job( 'abc', 'export', [ 's' ] );
		for ( $i = 1; $i <= AttemptGuard::MAX_ATTEMPTS; $i++ ) {
			AttemptGuard::record( $job, 's' );
			$this->assertSame( $i, $job->attempt['count'] );
		}

		$this->expectException( JobException::class );
		$this->expectExceptionMessage( 'started 5 times without making progress' );
		AttemptGuard::record( $job, 's' );
	}

	public function test_a_moved_cursor_or_next_step_restarts_the_count(): void {
		$job = new Job( 'abc', 'export', [ 's', 't' ] );
		AttemptGuard::record( $job, 's' );
		AttemptGuard::record( $job, 's' );

		$job->cursor = [ 'n' => 1 ];
		AttemptGuard::record( $job, 's' );
		$this->assertSame( 1, $job->attempt['count'] );

		$job->stepIndex = 1;
		$job->cursor    = [ 'n' => 1 ];
		AttemptGuard::record( $job, 't' );
		$this->assertSame( 1, $job->attempt['count'] );
	}
}
