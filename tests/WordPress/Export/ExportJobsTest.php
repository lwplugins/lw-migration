<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportJobs;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Storage\Cloud\CloudPipeline;
use LightweightPlugins\Migration\Storage\Cloud\CloudPlan;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class ExportJobsTest extends MonkeyTestCase {

	private string $dir;

	private FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->db  = FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->db;
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_creates_a_locked_job_with_site_and_secret(): void {
		$jobs    = new JobStore( $this->dir );
		$secrets = new MemorySecretStore();
		$job     = ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'encrypt' => true ] ), 'pw', $secrets, 100 );

		$this->assertSame( ExportPipeline::STEPS, $jobs->load( $job->id )->steps );
		$this->assertSame( 'https://old.test', $jobs->load( $job->id )->state['site']['homeUrl'] );
		$this->assertSame( [ 'password' => 'pw' ], $secrets->get( $job->id ) );
		$this->assertStringNotContainsString( 'pw', (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' ) );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 101 ) );

		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, $secrets, 102 );
			$this->fail( 'A second job must be refused.' );
		} catch ( SiteBusyException $e ) {
			$this->assertStringStartsWith( 'T:', $e->getMessage(), 'The refusal is translated.' );
			$this->assertSame( [ $job->id ], $jobs->ids(), 'The refused job leaves nothing behind.' );
		}
	}

	public function test_the_refusal_says_when_the_lock_expires_in_site_time(): void {
		$jobs = new JobStore( $this->dir );
		ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 100 );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => 'site-time(' . $format . ',' . $time . ')' );

		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 500 );
			$this->fail( 'A second job must be refused.' );
		} catch ( SiteBusyException $e ) {
			$this->assertStringContainsString( 'its lock expires at site-time(Y-m-d H:i T,' . ( 100 + SiteLock::TTL ) . ')', $e->getMessage() );
		}
	}

	public function test_extra_state_is_saved_with_the_job(): void {
		$jobs  = new JobStore( $this->dir );
		$job   = ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 100, [ 'schedule' => [ 'id' => '0a1b2c3d' ] ] );
		$saved = $jobs->load( $job->id )->state;

		$this->assertSame( [ 'id' => '0a1b2c3d' ], $saved['schedule'] );
		$this->assertSame( 'https://old.test', $saved['site']['homeUrl'] );
	}

	public function test_extra_state_takes_only_a_schedule_with_a_valid_id(): void {
		$jobs = new JobStore( $this->dir );
		$bad  = [
			[ 'site' => [ 'homeUrl' => 'https://elsewhere.test' ] ],
			[
				'schedule' => [ 'id' => '0a1b2c3d' ],
				'backup'   => [],
			],
			[ 'schedule' => [ 'id' => '../x' ] ],
			[ 'schedule' => [] ],
			[ 'schedule' => 'junk' ],
		];
		foreach ( $bad as $index => $state ) {
			try {
				ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 100, $state );
				$this->fail( 'State #' . $index . ' must be refused.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( [], $jobs->ids(), 'Nothing is created.' );
				$this->assertNull( ( new SiteLock() )->holder( 101 ), 'The lock is not taken.' );
			}
		}
	}

	public function test_a_connection_export_keeps_its_archive_and_its_link(): void {
		$this->assertSame( [ 'init', 'config', 'enumerate', 'content', 'database', 'database_file', 'finalize' ], ExportPipeline::LINK_STEPS );
		$jobs = new JobStore( $this->dir );
		$link = [
			'id'      => '0123456789abcdef',
			'ref'     => 'aaaabbbbccccdddd',
			'name'    => 'Site A',
			'address' => 'https://a.example',
		];

		$job   = ExportJobs::createForLink( $jobs, new SiteLock(), SiteFixture::make( '/s' ), 100, $link );
		$saved = $jobs->load( $job->id );

		$this->assertSame( [ 'export', ExportPipeline::LINK_STEPS ], [ $saved->type, $saved->steps ] );
		$this->assertSame( ExportOptions::fromArray( [] )->toArray(), $saved->options );
		$this->assertSame( $link, $saved->state['link'] );
		$this->assertSame( 'https://old.test', $saved->state['site']['homeUrl'] );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 101 ) );

		try {
			ExportJobs::createForLink( $jobs, new SiteLock(), SiteFixture::make( '/s' ), 102, $link );
			$this->fail( 'A busy site refuses the second job.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( [ $job->id ], $jobs->ids(), 'The refused job leaves nothing behind.' );
		}
	}

	public function test_a_connection_export_is_refused_on_a_network(): void {
		$this->network( true );
		$jobs = new JobStore( $this->dir );

		try {
			ExportJobs::createForLink( $jobs, new SiteLock(), SiteFixture::make( '/s' ), 100, [ 'id' => '0123456789abcdef' ] );
			$this->fail( 'Connections work between single sites only.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'Connections work between single sites only.', $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 101 ) );
	}

	public function test_encrypted_backup_needs_a_password(): void {
		$this->expectException( \InvalidArgumentException::class );
		ExportJobs::create( new JobStore( $this->dir ), new SiteLock(), SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'encrypt' => true ] ), '', new MemorySecretStore(), 1 );
	}

	public function test_a_network_backup_starts_on_the_main_site(): void {
		$this->network( true );
		$jobs = new JobStore( $this->dir );

		$job = ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 100 );

		$this->assertSame( ExportPipeline::stepsFor( true, false ), $jobs->load( $job->id )->steps );
		$this->assertSame( 'network', $jobs->load( $job->id )->steps[1] );
		$this->assertSame( [], $jobs->load( $job->id )->options['sites'] );
	}

	public function test_a_single_site_job_keeps_today_s_steps(): void {
		$jobs = new JobStore( $this->dir );

		$job = ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'noFiles' => true ] ), null, new MemorySecretStore(), 100 );

		$this->assertSame( ExportPipeline::STEPS, $jobs->load( $job->id )->steps, 'R18: the step list never depends on noFiles here.' );
	}

	public function test_a_network_backup_is_refused_on_a_subsite(): void {
		$this->network( false );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );

		$this->assertRefused( new ExportOptions(), ExportException::class, 'T:Backups of a network run on its main site.' );
	}

	public function test_a_multi_network_install_is_refused(): void {
		$this->network( true, 2 );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$blogs = $this->blogsTable( [ '2' ] );

		$this->assertRefused( ExportOptions::fromArray( [ 'sites' => [ 2 ] ] ), ExportException::class, 'T:This network install hosts more than one network. Backing it up is not supported.' );
		$this->assertSame( [], $blogs->reads, 'Refused before the blogs table is read.' );
	}

	public function test_chosen_sites_need_a_network(): void {
		$this->assertRefused( ExportOptions::fromArray( [ 'sites' => [ 2 ] ] ), \InvalidArgumentException::class, 'Choosing sites works only on a multisite network.' );
	}

	public function test_unknown_site_ids_are_refused(): void {
		$this->network( true );
		$blogs = $this->blogsTable( [ '2' ] );

		$this->assertRefused( ExportOptions::fromArray( [ 'sites' => [ 2, 9 ] ] ), \InvalidArgumentException::class, 'There is no site with ID 9 in this network.' );
		$this->assertCount( 1, $blogs->reads );
	}

	/**
	 * Puts a blogs table that knows $ids in front of the options fake (restored in tearDown).
	 *
	 * @param array<int, string> $ids Blog ids the table holds.
	 * @return object{reads: array<int, string>}
	 */
	private function blogsTable( array $ids ): object {
		$GLOBALS['wpdb'] = new class( $this->db, $ids ) {
			public string $blogs = 'wp_blogs';

			/** @var array<int, string> */
			public array $reads = [];

			/**
			 * @param FakeWpdb           $options The options fake.
			 * @param array<int, string> $ids     Blog ids.
			 */
			public function __construct( private FakeWpdb $options, private array $ids ) {
			}

			/** @return array<int, string> */
			public function get_col( string $sql ): array {
				$this->reads[] = $sql;
				return $this->ids;
			}

			/**
			 * @param string            $name Method of the options fake.
			 * @param array<int, mixed> $args Arguments.
			 * @return mixed
			 */
			public function __call( string $name, array $args ) {
				return $this->options->$name( ...$args );
			}

			/**
			 * @param string $name Property of the options fake.
			 * @return mixed
			 */
			public function __get( string $name ) {
				return $this->options->$name;
			}
		};

		return $GLOBALS['wpdb'];
	}

	/**
	 * @param ExportOptions            $options  Options.
	 * @param class-string<\Throwable> $class    Expected exception.
	 * @param string                   $message  Expected message.
	 */
	private function assertRefused( ExportOptions $options, string $class, string $message ): void {
		$jobs = new JobStore( $this->dir );
		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $options, null, new MemorySecretStore(), 1 );
			$this->fail( 'The backup must be refused.' );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( $class, $e );
			$this->assertSame( $message, $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids(), 'Nothing is created.' );
		$this->assertNull( ( new SiteLock() )->holder( 2 ), 'The lock is not taken.' );
	}

	private function network( bool $mainSite, int $networks = 1 ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		Functions\when( 'get_networks' )->justReturn(
			array_map(
				static fn( int $id ): object => (object) [
					'id'     => $id,
					'domain' => 'n' . $id . '.test',
					'path'   => '/',
				],
				range( 1, $networks )
			)
		);
	}

	public function test_a_secret_that_cannot_be_stored_releases_the_lock_and_removes_the_job(): void {
		$jobs    = new JobStore( $this->dir );
		$failing = new class() implements SecretStore {
			public function put( string $jobId, array $values ): void {
				throw new JobException( 'Cannot save the job secret.' );
			}

			public function get( string $jobId ): array {
				return [];
			}

			public function delete( string $jobId ): void {
			}
		};
		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'encrypt' => true ] ), 'pw', $failing, 1 );
			$this->fail( 'The failed secret write must surface.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Cannot save the job secret.', $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 2 ) );
	}

	public function test_a_lock_that_cannot_be_written_leaves_no_job_behind(): void {
		$this->db->beforeWrite = static function (): void {
			throw new \RuntimeException( 'Database went away.' );
		};
		$jobs = new JobStore( $this->dir );
		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 1 );
			$this->fail( 'The lock failure must surface.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Database went away.', $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertSame( [], array_values( array_diff( (array) scandir( $this->dir ), [ '.', '..' ] ) ), 'The job folder is deleted.' );
	}

	public function test_a_failing_release_during_rollback_keeps_the_original_error(): void {
		$db      = $this->db;
		$jobs    = new JobStore( $this->dir );
		$failing = new class( $db ) implements SecretStore {
			public function __construct( private FakeWpdb $db ) {
			}

			public function put( string $jobId, array $values ): void {
				$this->db->beforeWrite = static function (): void {
					throw new \RuntimeException( 'Release failed too.' );
				};
				throw new JobException( 'Cannot save the job secret.' );
			}

			public function get( string $jobId ): array {
				return [];
			}

			public function delete( string $jobId ): void {
			}
		};
		try {
			ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), ExportOptions::fromArray( [ 'encrypt' => true ] ), 'pw', $failing, 1 );
			$this->fail( 'The failed secret write must surface.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Cannot save the job secret.', $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids(), 'The job folder is still removed.' );
	}

	public function test_a_cloud_plan_appends_the_cloud_steps_and_is_kept_in_the_job(): void {
		$jobs = new JobStore( $this->dir );
		$plan = CloudPlan::manual( [ 'aaaa1111' ], false );

		$job = ExportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ExportOptions(), null, new MemorySecretStore(), 100, [], $plan );

		$saved = $jobs->load( $job->id );
		$this->assertSame( array_merge( ExportPipeline::STEPS, CloudPipeline::EXPORT_STEPS ), $saved->steps );
		$this->assertSame( $plan->toArray(), $saved->state['cloud'] );
	}
}
