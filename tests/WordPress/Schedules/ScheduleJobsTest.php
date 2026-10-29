<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Schedules;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Schedules\RunRecorder;
use LightweightPlugins\Migration\Schedules\Schedule;
use LightweightPlugins\Migration\Schedules\ScheduleException;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Schedules\SealedPassword;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\FolderMessages;
use LightweightPlugins\Migration\Storage\FolderRefusedException;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class ScheduleJobsTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $jobs;

	private MemorySecretStore $secrets;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install();
		$this->dir     = TempDir::create();
		$this->jobs    = new JobStore( $this->dir );
		$this->secrets = new MemorySecretStore();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	private function starter(): ScheduleJobs {
		return new ScheduleJobs( $this->jobs, new SiteLock(), $this->secrets, static fn(): Site => SiteFixture::make( '/s' ), new SealedPassword() );
	}

	public function test_a_run_is_a_full_export_with_the_schedule_snapshot(): void {
		$schedule = Schedule::fromArray(
			[
				'id'        => '0a1b2c3d',
				'title'     => 'Nightly',
				'export'    => [
					'noCache'     => true,
					'compression' => 'gzip',
				],
				'retention' => [ 'count' => 2 ],
				'notify'    => [
					'when'  => 'always',
					'email' => 'a@x.test',
				],
			]
		);

		$job   = $this->starter()->start( $schedule, RunRecorder::CRON, 100 );
		$saved = $this->jobs->load( $job->id );

		$this->assertSame( ExportPipeline::STEPS, $saved->steps );
		$this->assertSame( [ true, 'gzip', 'backup', false ], [ $saved->options['noCache'], $saved->options['compression'], $saved->options['kind'], $saved->options['noFiles'] ] );
		$this->assertSame(
			[
				'id'        => '0a1b2c3d',
				'title'     => 'Nightly',
				'trigger'   => 'cron',
				'retention' => [
					'count' => 2,
					'bytes' => 0,
					'days'  => 0,
				],
				'notify'    => [
					'when'  => 'always',
					'email' => 'a@x.test',
				],
				'seal'      => '',
			],
			$saved->state['schedule']
		);
		$this->assertSame( 'https://old.test', $saved->state['site']['homeUrl'] );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 101 ) );
	}

	public function test_a_network_schedule_backs_up_the_sites_that_still_exist(): void {
		$this->network()->blogIds = [ 1, 2, 3 ];
		$schedule                 = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'export' => [ 'sites' => [ 2, 7 ] ],
			]
		);

		$job   = $this->starter()->start( $schedule, RunRecorder::CRON, 100 );
		$saved = $this->jobs->load( $job->id );

		$this->assertSame( [ 2 ], $saved->options['sites'] );
		$this->assertSame( [ 'Site 7 of this schedule no longer exists. The backup leaves it out.' ], $saved->warnings );
	}

	public function test_a_network_schedule_whose_sites_are_all_gone_starts_nothing(): void {
		$this->network()->blogIds = [ 1 ];
		$schedule                 = Schedule::fromArray(
			[
				'id'     => '0a1b2c3d',
				'export' => [ 'sites' => [ 7, 8 ] ],
			]
		);

		try {
			$this->starter()->start( $schedule, RunRecorder::CRON, 100 );
			$this->fail( 'Started.' );
		} catch ( ScheduleException $e ) {
			$this->assertSame( 'None of the sites of this schedule exist any more.', $e->getMessage() );
		}
		$this->assertSame( [], $this->jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 101 ) );
	}

	public function test_a_whole_network_schedule_keeps_its_empty_choice(): void {
		$this->network();

		$job = $this->starter()->start( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ), RunRecorder::CRON, 100 );

		$this->assertSame( [], $this->jobs->load( $job->id )->options['sites'] );
		$this->assertSame( [], $this->jobs->load( $job->id )->warnings );
	}

	/**
	 * The main site of a one-network install.
	 */
	private function network(): FakeWpdb {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'get_networks' )->justReturn( [ (object) [ 'id' => 1, 'domain' => 'net.test', 'path' => '/' ] ] );

		return $GLOBALS['wpdb'];
	}

	public function test_an_encrypted_schedule_hands_its_password_to_the_job_secrets_only(): void {
		$schedule = Schedule::fromArray(
			[
				'id'       => '0a1b2c3d',
				'export'   => [ 'encrypt' => true ],
				'password' => ( new SealedPassword() )->seal( 'pw-123' ),
			]
		);

		$job = $this->starter()->start( $schedule, RunRecorder::MANUAL, 100 );

		$this->assertSame( [ 'password' => 'pw-123' ], $this->secrets->get( $job->id ) );
		$this->assertStringNotContainsString( 'pw-123', (string) file_get_contents( $this->jobs->dir( $job->id ) . '/job.json' ) );
		$this->assertSame( ScheduleJobs::seal( $schedule ), $this->jobs->load( $job->id )->state['schedule']['seal'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}\z/', ScheduleJobs::seal( $schedule ) );
	}

	public function test_an_unreadable_password_or_bad_options_stop_the_run_before_anything_exists(): void {
		$schedules = [
			Schedule::fromArray(
				[
					'id'       => '0a1b2c3d',
					'export'   => [ 'encrypt' => true ],
					'password' => 'lwm1:not-openable',
				]
			),
			Schedule::fromArray(
				[
					'id'     => '0a1b2c3d',
					'export' => [ 'compression' => 'zip' ],
				]
			),
		];
		foreach ( $schedules as $schedule ) {
			try {
				$this->starter()->start( $schedule, RunRecorder::CRON, 100 );
				$this->fail( 'Started.' );
			} catch ( ScheduleException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertSame( [], $this->jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 101 ) );
	}

	public function test_a_run_never_starts_without_a_usable_backups_folder(): void {
		$starter = new ScheduleJobs( $this->jobs, new SiteLock(), $this->secrets, static fn(): Site => throw new FolderRefusedException( FolderRefusedException::MISSING ), new SealedPassword() );

		try {
			$starter->start( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ), RunRecorder::CRON, 100 );
			$this->fail( 'Started.' );
		} catch ( ScheduleException $e ) {
			$this->assertSame( FolderMessages::of( FolderRefusedException::MISSING ), $e->getMessage(), 'The run log names the folder problem.' );
			$this->assertInstanceOf( FolderRefusedException::class, $e->getPrevious() );
		}
		$this->assertSame( [], $this->jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 101 ) );
	}

	public function test_the_snapshot_trigger_is_exactly_cron_or_manual(): void {
		$schedule = Schedule::fromArray( [ 'id' => '0a1b2c3d' ] );
		$job      = $this->starter()->start( $schedule, RunRecorder::CRON, 100 );
		$this->assertSame( RunRecorder::CRON, $this->jobs->load( $job->id )->state['schedule']['trigger'] );
		( new SiteLock() )->release( $job->id );

		try {
			$this->starter()->start( $schedule, 'Cron', 100 );
			$this->fail( 'Started with an unknown trigger.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( [ $job->id ], $this->jobs->ids() );
		}
	}

	public function test_a_busy_site_is_refused_and_leaves_nothing(): void {
		$this->assertTrue( ( new SiteLock() )->acquire( 'ffffffffffffffff', 100 ) );

		$this->expectException( SiteBusyException::class );
		try {
			$this->starter()->start( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ), RunRecorder::CRON, 100 );
		} finally {
			$this->assertSame( [], $this->jobs->ids() );
		}
	}

	public function test_a_schedule_with_destinations_uploads_with_its_own_retention_and_keep_local(): void {
		$schedule = Schedule::fromArray(
			[
				'id'           => '0a1b2c3d',
				'keepLocal'    => false,
				'destinations' => [
					[
						'id'        => 'aaaa1111',
						'retention' => [ 'count' => 4 ],
					],
				],
			]
		);

		$saved = $this->jobs->load( $this->starter()->start( $schedule, RunRecorder::CRON, 100 )->id );

		$this->assertSame( array_merge( ExportPipeline::STEPS, CloudPipeline::EXPORT_STEPS ), $saved->steps );
		$this->assertSame(
			[
				'targets'   => [
					[
						'id'        => 'aaaa1111',
						'retention' => [ 'count' => 4, 'bytes' => 0, 'days' => 0 ],
					],
				],
				'keepLocal' => false,
				'prune'     => true,
			],
			$saved->state['cloud']
		);
		$this->assertNull( ScheduleJobs::plan( Schedule::fromArray( [ 'id' => '0a1b2c3d' ] ) ) );
	}
}
