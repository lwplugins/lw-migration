<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Job;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobBusyException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\Runner;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RunnerFailTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_fail_saves_the_reason_logs_it_and_runs_the_finish_hook_after_saving(): void {
		$store   = new JobStore( $this->dir );
		$secrets = new MemorySecretStore();
		$job     = $store->create( 'export', [ 's' ], [], 1 );
		$secrets->put( $job->id, [ 'password' => 'pw-secret' ] );
		$seen   = [];
		$runner = new Runner(
			$store,
			[],
			$secrets,
			[ '/srv/site' ],
			static function ( Job $ended ) use ( $store, &$seen ): void {
				$seen[] = $store->load( $ended->id )->status;
			}
		);

		$failed = $runner->fail( $job->id, 'Lock lost at /srv/site/wp-content/x', 5 );

		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertSame( 'Lock lost at wp-content/x', $failed->error );
		$this->assertSame( 5, $failed->updatedAt );
		$this->assertSame( Job::FAILED, $store->load( $job->id )->status, 'Saved before the hook ran.' );
		$this->assertSame( [ Job::FAILED ], $seen );
		$this->assertSame( [], $secrets->get( $job->id ) );
		$log = (string) file_get_contents( $store->dir( $job->id ) . '/job.log' );
		$this->assertStringContainsString( 'FAILED in step s: Lock lost at wp-content/x', $log );
		$this->assertStringNotContainsString( '/srv/site', $log );
	}

	public function test_fail_leaves_an_ended_job_alone(): void {
		$store  = new JobStore( $this->dir );
		$calls  = 0;
		$runner = new Runner(
			$store,
			[],
			null,
			[],
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		$job    = $store->create( 'export', [ 's' ], [], 1 );
		$runner->cancel( $job->id, 2 );

		$this->assertSame( Job::CANCELLED, $runner->fail( $job->id, 'late', 3 )->status );
		$this->assertSame( 1, $calls );
	}

	public function test_fail_on_a_busy_job_throws(): void {
		$store = new JobStore( $this->dir );
		$job   = $store->create( 'export', [ 's' ], [], 1 );
		$lock  = $store->acquire( $job->id );

		try {
			$this->expectException( JobBusyException::class );
			( new Runner( $store, [] ) )->fail( $job->id, 'x', 2 );
		} finally {
			$store->release( $lock );
		}
	}
}
