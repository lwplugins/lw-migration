<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\Job;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase {

	public function test_round_trip_and_current_step(): void {
		$job = new Job( 'abc', 'export', [ 'one', 'two' ] );
		$job->stepIndex = 1;
		$job->cursor    = [ 'x' => 1 ];
		$job->state     = [ 'tmp' => 'lwt0000_' ];
		$copy           = Job::fromArray( $job->toArray() );

		$this->assertSame( 'two', $copy->currentStep() );
		$this->assertSame( [ 'x' => 1 ], $copy->cursor );
		$this->assertSame( [ 'tmp' => 'lwt0000_' ], $copy->state );
		$this->assertSame( Job::PENDING, $copy->status );
	}

	public function test_warnings_are_capped_but_counted(): void {
		$job = new Job( 'abc', 'import', [ 'a' ] );
		for ( $i = 0; $i < 150; $i++ ) {
			$job->addWarning( "w{$i}" );
		}
		$this->assertCount( 100, $job->warnings );
		$this->assertSame( 150, $job->warningCount );
	}

	public function test_finished_states(): void {
		$job = new Job( 'abc', 'import', [ 'a' ] );
		$this->assertFalse( $job->isFinished() );
		foreach ( [ Job::DONE, Job::FAILED, Job::CANCELLED ] as $status ) {
			$job->status = $status;
			$this->assertTrue( $job->isFinished() );
		}
	}

	public function test_secrets_are_never_serialised(): void {
		$job          = new Job( 'abc', 'import', [ 'a' ] );
		$job->secrets = [ 'password' => 'p' ];
		$this->assertArrayNotHasKey( 'secrets', $job->toArray() );
		$this->assertSame( [], Job::fromArray( $job->toArray() )->secrets );
	}
}
