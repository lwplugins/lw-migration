<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\AbandonedRuns;
use LightweightPlugins\Migration\Schedules\Log;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class AbandonedRunsTest extends MonkeyTestCase {

	private const NOW = 1767225600;

	private const ID = '0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		FakeOptions::install( [ Options::SCHEDULES => [ [ 'id' => 'aaaaaaaa' ] ] ] );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/**
	 * @param mixed $schedule state.schedule.id (null: no schedule).
	 * @param int   $saved    Seconds since the job was last saved.
	 */
	private static function job( $schedule, int $saved ): Job {
		$job            = new Job( self::ID, 'export', [] );
		$job->updatedAt = self::NOW - $saved;
		if ( null !== $schedule ) {
			$job->state['schedule'] = [ 'id' => $schedule ];
		}

		return $job;
	}

	/**
	 * @dataProvider cases
	 *
	 * @param mixed $schedule state.schedule.id (null: no schedule).
	 * @param int   $saved    Seconds since the job was last saved.
	 * @param bool  $logged   Whether the schedule's run log ended this job.
	 * @param bool  $expected Whether it is abandoned.
	 */
	public function test_a_run_is_over_when_its_log_ended_it_or_its_schedule_is_gone_and_it_stopped_saving( $schedule, int $saved, bool $logged, bool $expected ): void {
		if ( $logged ) {
			Log::add( 'aaaaaaaa', [ 'job' => self::ID, 'status' => 'failed' ] );
		}

		$this->assertSame( $expected, ( new AbandonedRuns( new Repository() ) )->abandoned( self::job( $schedule, $saved ), self::NOW ) );
	}

	/** @return array<string, array{0: mixed, 1: int, 2: bool, 3: bool}> */
	public static function cases(): array {
		return [
			'no schedule'                         => [ null, SiteLock::TTL * 9, false, false ],
			'its schedule, still running'         => [ 'aaaaaaaa', SiteLock::TTL * 9, false, false ],
			'its schedule, given up'              => [ 'aaaaaaaa', 0, true, true ],
			'schedule gone, still saving'         => [ 'ffffffff', SiteLock::TTL - 1, false, false ],
			'schedule gone, no save for the lock' => [ 'ffffffff', SiteLock::TTL, false, true ],
			'not a schedule id, no save'          => [ 'x', SiteLock::TTL, false, true ],
		];
	}

	public function test_the_schedule_of_a_job(): void {
		$this->assertSame( 'aaaaaaaa', AbandonedRuns::scheduleOf( self::job( 'aaaaaaaa', 0 ) ) );
		$this->assertNull( AbandonedRuns::scheduleOf( self::job( 'x', 0 ) ) );
		$this->assertNull( AbandonedRuns::scheduleOf( self::job( null, 0 ) ) );
		$this->assertNull( AbandonedRuns::scheduleOf( null ) );
	}
}
