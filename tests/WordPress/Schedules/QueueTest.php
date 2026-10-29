<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\CronDriver;
use LightweightPlugins\Migration\Schedules\Queue;
use LightweightPlugins\Migration\Schedules\ScheduleException;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class QueueTest extends MonkeyTestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = FakeWpdb::install();
	}

	public function test_a_waiting_schedule_is_queued_once(): void {
		$queue = new Queue();

		$this->assertTrue( $queue->add( 'aaaaaaaa', 100 ) );
		$this->assertFalse( $queue->add( 'aaaaaaaa', 200 ), 'A missed or overlapping run coalesces with the waiting one.' );
		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => 100 ] ], $queue->all() );
	}

	public function test_a_waiting_run_past_the_skip_limit_is_replaced_and_its_due_time_reported(): void {
		$queue = new Queue();
		$queue->add( 'aaaaaaaa', 100 );
		$fresh = 100 + CronDriver::QUEUE_LIMIT + 1;

		$this->assertTrue( $queue->add( 'aaaaaaaa', $fresh, $replaced ) );
		$this->assertSame( 100, $replaced );
		$this->assertFalse( $queue->add( 'aaaaaaaa', $fresh + 60, $again ) );
		$this->assertNull( $again, 'A recent waiting run absorbs the new one; nothing was replaced.' );
		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => $fresh ] ], $queue->all() );
	}

	public function test_a_replacement_lost_to_another_request_is_not_reported(): void {
		$queue = new Queue();
		$queue->add( 'aaaaaaaa', 100 );
		$this->db->beforeWrite = static function (): void {
			( new Queue() )->take( 'aaaaaaaa' ); // Another request started the old run first.
		};

		$this->assertTrue( $queue->add( 'aaaaaaaa', 100 + CronDriver::QUEUE_LIMIT + 1, $replaced ) );
		$this->assertNull( $replaced, 'Only what the winning write removed is reported.' );
	}

	public function test_the_oldest_due_comes_first_and_remove_takes_one_out(): void {
		$queue = new Queue();
		$queue->add( 'bbbbbbbb', 300 );
		$queue->add( 'aaaaaaaa', 100 );
		$queue->add( 'cccccccc', 200 );
		$queue->remove( 'cccccccc' );

		$this->assertSame( [ 'aaaaaaaa', 'bbbbbbbb' ], array_column( $queue->all(), 'id' ) );
	}

	public function test_a_concurrent_change_is_never_lost(): void {
		$queue = new Queue();
		$queue->add( 'aaaaaaaa', 100 );
		$this->db->beforeWrite = static function (): void {
			( new Queue() )->add( 'bbbbbbbb', 150 ); // Another request wins the race.
		};
		$queue->add( 'cccccccc', 200 );

		$this->assertSame( [ 'aaaaaaaa', 'bbbbbbbb', 'cccccccc' ], array_column( $queue->all(), 'id' ) );
	}

	public function test_a_broken_row_reads_as_empty_and_is_replaced(): void {
		$this->db->rows[ Options::SCHEDULE_QUEUE ] = [
			'value'    => '{"x":',
			'autoload' => 'off',
		];
		$queue = new Queue();

		$this->assertSame( [], $queue->all() );
		$this->assertTrue( $queue->add( 'aaaaaaaa', 1 ) );
		$this->assertSame( [ [ 'id' => 'aaaaaaaa', 'due' => 1 ] ], $queue->all() );
	}

	public function test_a_row_that_keeps_changing_is_an_internal_error_not_a_text_for_people(): void {
		$queue = new Queue();
		$queue->add( 'aaaaaaaa', 100 );
		$due   = 0;
		$race  = function () use ( &$race, &$due ): void {
			( new Queue() )->add( sprintf( '%08x', ++$due ), $due ); // Every write of ours loses to another request.
			$this->db->beforeWrite = $race;
		};
		$this->db->beforeWrite = $race;

		try {
			$queue->add( 'cccccccc', 200 );
			$this->fail( 'The queue gives up after a few tries.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotInstanceOf( ScheduleException::class, $e, 'R15: never shown to people.' );
		}
	}
	public function test_only_the_request_that_removes_an_entry_takes_it(): void {
		$queue = new Queue();
		$queue->add( 'aaaaaaaa', 100 );
		$queue->add( 'bbbbbbbb', 200 );
		$other                 = null;
		$this->db->beforeWrite = static function () use ( &$other ): void {
			$other = ( new Queue() )->take( 'aaaaaaaa' ); // Another request takes it first.
		};

		$this->assertFalse( $queue->take( 'aaaaaaaa' ) );
		$this->assertTrue( $other );
		$this->assertFalse( $queue->take( 'cccccccc' ), 'Nothing to take.' );
		$this->assertSame( [ 'bbbbbbbb' ], array_column( $queue->all(), 'id' ) );
	}
}
