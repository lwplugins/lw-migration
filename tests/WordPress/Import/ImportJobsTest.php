<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportJobs;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Import\Steps\MuPluginsStep;
use LightweightPlugins\Migration\Import\Steps\PostImportStep;
use LightweightPlugins\Migration\Import\Steps\SwapStep;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Job\MemorySecretStore;
use LightweightPlugins\Migration\Job\SecretStore;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class ImportJobsTest extends MonkeyTestCase {

	private string $dir;

	private ImportOptions $options;

	protected function setUp(): void {
		parent::setUp();
		$this->dir     = TempDir::create();
		$this->options = ImportOptions::fromArray(
			[
				'source'   => 'file',
				'location' => '/a.wpress',
			]
		);
		FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $time ): string => gmdate( $format, $time ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_steps_by_source(): void {
		$this->assertSame( array_merge( [ 'url_resolve', 'url_download' ], ImportPipeline::COMMON ), ImportPipeline::stepsFor( 'url' ) );
		$this->assertSame( array_merge( [ 'source' ], ImportPipeline::COMMON ), ImportPipeline::stepsFor( 'backup' ) );
		$this->assertSame( array_merge( [ 'source' ], ImportPipeline::COMMON ), ImportPipeline::stepsFor( 'file' ) );
		$this->assertSame( array_merge( [ 'upload' ], ImportPipeline::COMMON ), ImportPipeline::stepsFor( 'upload' ) );
		$preserve = (int) array_search( 'preserve', ImportPipeline::COMMON, true );
		$this->assertSame( SwapStep::KEY, ImportPipeline::COMMON[ $preserve + 1 ], 'Preserve runs right before the swap.' );
		$this->assertSame( PostImportStep::KEY, ImportPipeline::COMMON[ $preserve + 2 ] );
		$this->assertSame( MuPluginsStep::KEY, ImportPipeline::COMMON[ $preserve + 3 ] );
		$this->assertLessThan( (int) array_search( 'content', ImportPipeline::COMMON, true ), (int) array_search( 'safety_backup', ImportPipeline::COMMON, true ), 'The safety backup is taken before any file is restored.' );
	}

	public function test_creates_a_locked_job_with_target_admin_and_password(): void {
		$jobs    = new JobStore( $this->dir );
		$secrets = new MemorySecretStore();
		$job     = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, [ 'id' => 1, 'login' => 'admin' ], true, 'pw', $secrets, 10 );

		$saved = $jobs->load( $job->id );
		$this->assertSame( 'import', $saved->type );
		$this->assertSame( ImportPipeline::stepsFor( 'file' ), $saved->steps );
		$this->assertSame( [ 'id' => 1, 'login' => 'admin' ], $saved->state['admin'] );
		$this->assertTrue( $saved->state['usingPermalinks'] );
		$this->assertSame( 'https://old.test', $saved->state['site']['homeUrl'] );
		$this->assertSame( [ 'password' => 'pw' ], $secrets->get( $job->id ) );
		$this->assertStringNotContainsString( 'pw"', (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' ) );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 11 ) );
	}

	public function test_a_pull_is_an_import_over_its_link(): void {
		$jobs = new JobStore( $this->dir );
		$link = [
			'id'      => '0123456789abcdef',
			'name'    => 'Site B',
			'address' => 'https://b.example',
		];
		$job  = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), new ImportOptions( ImportOptions::LINK, $link['id'], true ), [ 'id' => 1, 'login' => 'admin' ], true, null, new MemorySecretStore(), 10, [ 'link' => $link ] );

		$saved = $jobs->load( $job->id );
		$this->assertSame( ImportPipeline::stepsFor( 'link' ), $saved->steps );
		$this->assertSame( [ 'source' => 'link', 'location' => '0123456789abcdef', 'safetyBackup' => true ], $saved->options );
		$this->assertSame( $link, $saved->state['link'] );
		$this->assertSame( [ 'id' => 1, 'login' => 'admin' ], $saved->state['admin'] );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 11 ) );
	}

	public function test_no_password_stores_no_secret(): void {
		$jobs    = new JobStore( $this->dir );
		$secrets = new MemorySecretStore();
		$job     = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, '', $secrets, 10 );

		$this->assertSame( [], $secrets->get( $job->id ) );
		$this->assertNull( $jobs->load( $job->id )->state['admin'] );
		$this->assertFalse( $jobs->load( $job->id )->state['usingPermalinks'] );
	}

	public function test_a_second_job_is_refused_with_the_lock_expiry_and_leaves_nothing(): void {
		$jobs    = new JobStore( $this->dir );
		$secrets = new MemorySecretStore();
		$job     = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, null, $secrets, 10 );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );

		try {
			ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, 'pw', $secrets, 11 );
			$this->fail( 'A second job must be refused.' );
		} catch ( SiteBusyException $e ) {
			$this->assertStringStartsWith( 'T:', $e->getMessage(), 'The refusal is translated.' );
			$this->assertStringContainsString( 'its lock expires at ' . gmdate( 'Y-m-d H:i T', 10 + SiteLock::TTL ), $e->getMessage() );
		}
		$this->assertSame( [ $job->id ], $jobs->ids(), 'The refused job leaves nothing behind.' );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 12 ) );
	}

	public function test_network_steps(): void {
		$steps = ImportPipeline::stepsFor( 'file', true );

		$this->assertSame(
			[ 'source', 'validate', 'crc', 'decrypt', 'confirm', 'safety_backup', 'content', 'database_file', 'database', 'preserve', 'swap', 'post_import', 'mu_plugins', 'done', 'clean' ],
			ImportPipeline::stepsFor( 'file', false ),
			'A single site keeps exactly its steps.'
		);
		$this->assertSame( ImportPipeline::stepsFor( 'file' ), ImportPipeline::stepsFor( 'file', false ) );
		$this->assertSame( 'network_sites', $steps[ (int) array_search( 'safety_backup', $steps, true ) + 1 ], 'Sites are created after the safety backup, before any file is restored.' );
		$this->assertSame( 'network_users', $steps[ (int) array_search( SwapStep::KEY, $steps, true ) + 1 ] );
		$this->assertSame( 'network_done', $steps[ (int) array_search( PostImportStep::KEY, $steps, true ) + 1 ] );
		$this->assertSame( array_values( array_diff( $steps, [ 'network_sites', 'network_users', 'network_done' ] ) ), ImportPipeline::stepsFor( 'file' ) );
		$this->assertSame( array_merge( [ 'upload' ], array_slice( $steps, 1 ) ), ImportPipeline::stepsFor( 'upload', true ) );
	}

	public function test_on_the_main_site_of_a_network_the_target_is_saved(): void {
		$this->network( 1, 1, true );
		$jobs = new JobStore( $this->dir );
		$job  = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, null, new MemorySecretStore(), 10, [ 'target' => [ 'domain' => 'evil.test' ] ] );

		$saved = $jobs->load( $job->id );
		$this->assertSame( ImportPipeline::stepsFor( 'file', true ), $saved->steps );
		$this->assertSame( 'new.test', $saved->state['target']['domain'], 'The read target wins over given state.' );
		$this->assertSame( 'https://new.test', TargetNetwork::fromArray( $saved->state['target'] )->mainUrl() );
		$this->assertSame( [ 1, 1 ], [ $saved->state['target']['networks'], $saved->state['target']['mainSiteId'] ] );
	}

	public function test_a_single_site_job_never_carries_a_target(): void {
		$jobs = new JobStore( $this->dir );
		$job  = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, null, new MemorySecretStore(), 10, [ 'target' => [ 'domain' => 'evil.test' ] ] );

		$this->assertArrayNotHasKey( 'target', $jobs->load( $job->id )->state );
	}

	/** @return array<string, array{0: int, 1: int, 2: bool, 3: string}> */
	public static function refusedNetworks(): array {
		return [
			'a subsite'               => [ 1, 1, false, 'Restores into a network run on its main site.' ],
			'more than one network'   => [ 2, 1, true, 'This network install hosts more than one network. Restoring into it is not supported.' ],
			'a main site other than 1' => [ 1, 3, true, 'This network\'s main site does not have ID 1, which this archive format needs.' ],
		];
	}

	/**
	 * @dataProvider refusedNetworks
	 */
	public function test_a_network_restore_is_refused_before_anything_is_created( int $networks, int $mainSiteId, bool $onMainSite, string $reason ): void {
		$this->network( $networks, $mainSiteId, $onMainSite );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$jobs = new JobStore( $this->dir );

		try {
			ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, null, new MemorySecretStore(), 1 );
			$this->fail( 'The restore must be refused.' );
		} catch ( ImportException $e ) {
			$this->assertSame( 'T:' . $reason, $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 2 ) );
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
			ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), $this->options, null, false, 'pw', $failing, 1 );
			$this->fail( 'The failed secret write must surface.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'Cannot save the job secret.', $e->getMessage() );
		}
		$this->assertSame( [], $jobs->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 2 ) );
	}

	public function test_extra_state_is_saved_with_the_job_but_never_over_the_fixed_keys(): void {
		$jobs = new JobStore( $this->dir );
		$job  = ImportJobs::create( $jobs, new SiteLock(), SiteFixture::make( '/s' ), ImportOptions::fromArray( [ 'source' => 'upload', 'location' => 'site.wpress' ] ), null, false, null, new MemorySecretStore(), 10, [ 'upload' => [ 'size' => 9000 ], 'admin' => [ 'id' => 666, 'login' => 'mallory' ] ] );

		$saved = $jobs->load( $job->id );
		$this->assertSame( [ 'size' => 9000 ], $saved->state['upload'] );
		$this->assertNull( $saved->state['admin'] );
		$this->assertSame( ImportPipeline::stepsFor( 'upload' ), $saved->steps );
	}

	private function network( int $networks, int $mainSiteId, bool $onMainSite ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $onMainSite );
		Functions\when( 'is_subdomain_install' )->justReturn( false );
		Functions\when( 'get_main_site_id' )->justReturn( $mainSiteId );
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'get_networks' )->justReturn( $networks );
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'get_subdirectory_reserved_names' )->justReturn( [ 'page' ] );
		Functions\when( 'get_site' )->justReturn(
			(object) [
				'domain' => 'new.test',
				'path'   => '/',
			]
		);
		Functions\when( 'get_home_url' )->justReturn( 'https://new.test' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/\\' ) );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/srv/wp-content/uploads',
				'baseurl' => 'https://new.test/wp-content/uploads',
			]
		);
	}
}
