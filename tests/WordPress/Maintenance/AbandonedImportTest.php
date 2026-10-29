<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Maintenance;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Maintenance\AbandonedImport;
use LightweightPlugins\Migration\Support\TempName;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class AbandonedImportTest extends MonkeyTestCase {

	/**
	 * Scratch folder of the test.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		mkdir( $this->dir . '/backups' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_only_an_unfinished_import_discards_its_temps(): void {
		$store   = new JobStore( $this->dir . '/jobs' );
		$crashed = $this->job( $store, 'import', Job::RUNNING );
		$failed  = $this->job( $store, 'import', Job::FAILED );
		$export  = $this->job( $store, 'export', Job::RUNNING );

		foreach ( [ $crashed, $failed, $export ] as $job ) {
			$this->assertTrue( AbandonedImport::discard( $store, $job->id, $this->dir . '/backups', $this->dir ) );
		}

		$this->assertFileDoesNotExist( $this->dir . '/backups/' . $crashed->cursor['safety']['cursor']['temp'] );
		$this->assertFileExists( $this->dir . '/backups/' . $failed->cursor['safety']['cursor']['temp'], 'Its finish hook already ran.' );
		$this->assertFileExists( $this->dir . '/backups/' . $export->cursor['safety']['cursor']['temp'] );
	}

	public function test_a_folder_without_a_readable_job_is_skipped(): void {
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->job( $store, 'import', Job::RUNNING );
		file_put_contents( $store->dir( $job->id ) . '/job.json', 'not json' );

		$this->assertTrue( AbandonedImport::discard( $store, $job->id, $this->dir . '/backups', $this->dir ) );
		$this->assertFileExists( $this->dir . '/backups/' . $job->cursor['safety']['cursor']['temp'] );
	}

	public function test_an_abandoned_import_removes_the_sites_it_created_unless_it_may_have_swapped(): void {
		$deleted = [];
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_delete_site' )->alias(
			static function ( int $id ) use ( &$deleted ) {
				$deleted[] = $id;
				return (object) [ 'id' => $id ];
			}
		);
		$store   = new JobStore( $this->dir . '/jobs' );
		$crashed = $this->networkJob( $store, [ 7 ] );
		$swapped = $this->networkJob( $store, [ 8 ] );
		file_put_contents( $store->dir( $swapped->id ) . '/' . ImportFiles::SWAP_STARTED, '' );

		$this->assertTrue( AbandonedImport::discard( $store, $crashed->id, $this->dir . '/backups', $this->dir ) );
		$this->assertTrue( AbandonedImport::discard( $store, $swapped->id, $this->dir . '/backups', $this->dir ) );

		$this->assertSame( [ 7 ], $deleted, 'Ruling R4, with the swap guard of ruling R2.' );
	}

	public function test_a_created_site_that_cannot_be_removed_is_reported(): void {
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'wp_delete_site' )->justReturn( null );
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->networkJob( $store, [ 7, 9 ] );
		$lines = [];

		$this->assertFalse(
			AbandonedImport::discard(
				$store,
				$job->id,
				$this->dir . '/backups',
				$this->dir,
				static function ( string $line ) use ( &$lines ): void {
					$lines[] = $line;
				}
			)
		);
		$this->assertSame( [ 'the sites 7, 9 an abandoned import created could not be removed: delete them under Network Admin > Sites' ], $lines );
		$this->assertSame( [ 7, 9 ], $store->load( $job->id )->state['createdSites'], 'The trimmed record is saved.' );
	}

	public function test_the_sites_removed_by_the_sweep_are_no_longer_recorded(): void {
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_delete_site' )->justReturn( (object) [] );
		$store = new JobStore( $this->dir . '/jobs' );
		$job   = $this->networkJob( $store, [ 7 ] );

		$this->assertTrue( AbandonedImport::discard( $store, $job->id, $this->dir . '/backups', $this->dir ) );
		$this->assertSame( [], $store->load( $job->id )->state['createdSites'] );
	}

	/**
	 * @param array<int, int> $created state.createdSites.
	 */
	private function networkJob( JobStore $store, array $created ): Job {
		$job                        = $this->job( $store, 'import', Job::RUNNING );
		$job->state['target']       = [ 'domain' => 'new.test' ];
		$job->state['createdSites'] = $created;
		$store->save( $job );

		return $job;
	}

	private function job( JobStore $store, string $type, string $status ): Job {
		$job  = $store->create( $type, [ 'a' ], [], time() );
		$temp = TempName::name();
		file_put_contents( $this->dir . '/backups/' . $temp, 'data' );

		$job->status = $status;
		$job->cursor = [ 'safety' => [ 'cursor' => [ 'temp' => $temp ] ] ];
		$store->save( $job );

		return $job;
	}
}
