<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Storage\Cloud;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Link\Steps\Retry;
use LightweightPlugins\Migration\Storage\Cloud\CloudRetry;
use LightweightPlugins\Migration\Storage\Destinations\TransientFailure;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use PHPUnit\Framework\TestCase;

final class CloudRetryTest extends TestCase {

	public function test_counts_failures_and_gives_up_after_ten_spanning_fifteen_minutes(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );
		$e   = new TransientFailure( 'busy', TransientFailure::BUSY, 503 );
		for ( $i = 1; $i < Retry::TOLERATED; $i++ ) {
			$this->assertSame( $i, CloudRetry::note( $job, $e, 1000 + $i ) );
		}

		$this->assertSame( Retry::TOLERATED, CloudRetry::note( $job, $e, 1000 + 60 ), 'Ten quick failures are not yet fifteen minutes.' );
		$this->expectExceptionObject( $e );
		CloudRetry::note( $job, $e, 1001 + Retry::SECONDS );
	}

	public function test_the_pause_honours_retry_after_and_clear_forgets_everything(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );

		$this->assertSame( 2, CloudRetry::pause( new TransientFailure( 'x', TransientFailure::SERVER ), 1 ) );
		$this->assertSame( 10, CloudRetry::pause( new TransientFailure( 'x', TransientFailure::SERVER ), 9 ) );
		$this->assertSame( 45, CloudRetry::pause( new TransientFailure( 'x', TransientFailure::BUSY, 503, 45 ), 1 ) );
		CloudRetry::note( $job, new TransientFailure( 'x', TransientFailure::SERVER ), 5 );
		CloudRetry::hold( $job, 50 );
		$this->assertSame( 50, CloudRetry::notBefore( $job ) );
		CloudRetry::clear( $job );
		$this->assertSame( [], $job->cursor );
	}

	public function test_wait_if_held_waits_counts_the_wait_and_asks_the_caller_to_return_more(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );
		CloudRetry::hold( $job, 1030 );
		$slept = [];
		$pause = static function ( float $seconds ) use ( &$slept ): void {
			$slept[] = $seconds;
		};

		$this->assertTrue( CloudRetry::waitIfHeld( $job, new CountdownDeadline( 5 ), $pause, 1000 ) );
		$this->assertSame( [ 30.0 ], $slept );
		$this->assertSame( 1, $job->cursor['waits'] );
		$this->assertTrue( CloudRetry::waitIfHeld( $job, new CountdownDeadline( 5 ), $pause, 1001 ) );
		$this->assertSame( 2, $job->cursor['waits'], 'Every waiting call changes the cursor.' );
	}

	public function test_wait_if_held_does_not_sleep_past_a_reached_deadline_but_still_counts(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );
		CloudRetry::hold( $job, 1030 );
		$slept = [];

		$this->assertTrue( CloudRetry::waitIfHeld( $job, new CountdownDeadline( 0 ), static function ( float $s ) use ( &$slept ): void {
			$slept[] = $s;
		}, 1000 ) );
		$this->assertSame( [], $slept );
		$this->assertSame( 1, $job->cursor['waits'] );
	}

	public function test_wait_if_held_is_false_and_leaves_the_cursor_alone_when_nothing_is_held(): void {
		$job   = new Job( '0123456789abcdef', 'cloud', [] );
		$pause = static function ( float $seconds ): void {
			throw new \LogicException( 'Must not sleep.' );
		};

		$this->assertFalse( CloudRetry::waitIfHeld( $job, new CountdownDeadline( 5 ), $pause, 1000 ) );
		CloudRetry::hold( $job, 1000 );
		$this->assertFalse( CloudRetry::waitIfHeld( $job, new CountdownDeadline( 5 ), $pause, 1000 ), 'The hold has just run out.' );
		$this->assertArrayNotHasKey( 'waits', $job->cursor );
	}

	public function test_defer_counts_the_failure_and_holds_the_next_request_back(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );

		CloudRetry::defer( $job, new TransientFailure( 'x', TransientFailure::SERVER ), 1000 );
		$this->assertSame( [ 1, 1002 ], [ $job->cursor['failures'], CloudRetry::notBefore( $job ) ] );
		CloudRetry::defer( $job, new TransientFailure( 'x', TransientFailure::BUSY, 503, 45 ), 1001 );
		$this->assertSame( [ 2, 1046 ], [ $job->cursor['failures'], CloudRetry::notBefore( $job ) ] );
	}

	public function test_defer_throws_the_failure_when_patience_is_spent_and_holds_nothing(): void {
		$job = new Job( '0123456789abcdef', 'cloud', [] );
		$e   = new TransientFailure( 'busy', TransientFailure::BUSY, 503 );
		for ( $i = 0; $i < Retry::TOLERATED - 1; $i++ ) {
			CloudRetry::defer( $job, $e, 1000 + $i );
		}
		$job->cursor['notBefore'] = 0;

		try {
			CloudRetry::defer( $job, $e, 1000 + Retry::SECONDS );
			$this->fail( 'Patience is spent.' );
		} catch ( TransientFailure $thrown ) {
			$this->assertSame( $e, $thrown );
		}
		$this->assertSame( 0, CloudRetry::notBefore( $job ), 'No new hold after the failure stands.' );
	}
}
