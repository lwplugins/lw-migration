<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Schedules\Repository;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Schedules\ScheduleSecrets;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ScheduleSecretsTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $jobs;

	private MemorySecretStore $inner;

	private Schedule $schedule;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$this->dir      = TempDir::create();
		$this->jobs     = new JobStore( $this->dir );
		$this->inner    = new MemorySecretStore();
		$this->schedule = Schedule::fromArray(
			[
				'id'       => '0a1b2c3d',
				'export'   => [ 'encrypt' => true ],
				'password' => ( new SealedPassword() )->seal( 'pw-123' ),
			]
		);
		FakeOptions::install( [ Options::SCHEDULES => [ $this->schedule->toArray() ] ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function store(): ScheduleSecrets {
		return new ScheduleSecrets( $this->inner, $this->jobs, new Repository(), new SealedPassword() );
	}

	private function job( bool $encrypt = true, string $seal = '' ): string {
		$job                    = $this->jobs->create( 'export', [ 'init' ], [ 'encrypt' => $encrypt ], 1 );
		$job->state['schedule'] = [
			'id'   => '0a1b2c3d',
			'seal' => '' === $seal ? ScheduleJobs::seal( $this->schedule ) : $seal,
		];
		$this->jobs->save( $job );

		return $job->id;
	}

	public function test_the_job_transient_comes_first(): void {
		$id = $this->job();
		$this->store()->put( $id, [ 'password' => 'from-the-transient' ] );

		$this->assertSame( [ 'password' => 'from-the-transient' ], $this->store()->get( $id ) );
		$this->store()->delete( $id );
		$this->assertSame( [], $this->inner->get( $id ) );
	}

	public function test_an_evicted_transient_falls_back_to_the_schedule_password(): void {
		$this->assertSame( [ 'password' => 'pw-123' ], $this->store()->get( $this->job() ) );
	}

	public function test_no_fallback_for_a_changed_password_an_unencrypted_job_or_another_job(): void {
		$this->assertSame( [], $this->store()->get( $this->job( true, 'ffffffffffffffff' ) ), 'The schedule got another password meanwhile.' );
		$this->assertSame( [], $this->store()->get( $this->job( false ) ) );
		$plain = $this->jobs->create( 'export', [ 'init' ], [ 'encrypt' => true ], 1 );
		$this->assertSame( [], $this->store()->get( $plain->id ) );
		$this->assertSame( [], $this->store()->get( '0123456789abcdef' ), 'A missing job.' );
	}

	public function test_no_fallback_for_an_import_job_or_an_empty_seal(): void {
		$import                    = $this->jobs->create( 'import', [ 'init' ], [ 'encrypt' => true ], 1 );
		$import->state['schedule'] = [
			'id'   => '0a1b2c3d',
			'seal' => ScheduleJobs::seal( $this->schedule ),
		];
		$this->jobs->save( $import );
		$this->assertSame( [], $this->store()->get( $import->id ), 'Only an export of the schedule may use its password.' );

		$unsealed                    = $this->jobs->create( 'export', [ 'init' ], [ 'encrypt' => true ], 1 );
		$unsealed->state['schedule'] = [
			'id'   => '0a1b2c3d',
			'seal' => '',
		];
		$this->jobs->save( $unsealed );
		$this->assertSame( [], $this->store()->get( $unsealed->id ), 'A run started without a password never gets one.' );
	}
}
