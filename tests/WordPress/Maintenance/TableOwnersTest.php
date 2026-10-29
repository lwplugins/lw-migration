<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Maintenance;

use LightweightPlugins\Migration\Import\ImportFiles;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Maintenance\TableOwners;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class TableOwnersTest extends MonkeyTestCase {

	/**
	 * Scratch folder of the test.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_active_temporary_prefixes_come_from_running_recent_imports(): void {
		$store = new JobStore( $this->dir );
		$now   = 1000000;
		$run   = $this->import( $store, $now - 60, 'lwtab12_' );
		$this->import( $store, $now - 60, 'lwtcd34_', Job::FAILED );
		$this->import( $store, $now - 90000, 'lwtef56_', Job::RUNNING );
		$held = $this->import( $store, $now - 90000, 'lwt0a1b_', Job::FAILED );
		$this->import( $store, $now - 60, 'LWT9999_' );
		file_put_contents( $store->dir( $store->create( 'import', [ 'a' ], [], $now )->id ) . '/job.json', 'not json' );

		$active = TableOwners::activeTmpPrefixes( $store, $now, static fn(): ?string => $held->id );
		sort( $active );

		$this->assertSame( [ 'lwt0a1b_', 'lwtab12_' ], $active, 'Unfinished and touched within a day, or holding the site lock; only valid prefixes.' );
		$this->assertSame( [ 'lwtab12_' ], TableOwners::activeTmpPrefixes( $store, $now ) );
		$this->assertNotNull( $run );
	}

	public function test_a_swapped_or_ended_import_keeps_its_replaced_tables_while_its_folder_exists(): void {
		$store   = new JobStore( $this->dir );
		$now     = 1000000;
		$this->import( $store, $now - 60, 'lwtab12_' );
		$swapped = $this->import( $store, $now - 60, 'lwtcd34_', Job::FAILED );

		$swapped->state['swapped'] = true;
		$store->save( $swapped );
		$marked = $this->import( $store, $now - 90000, 'lwtef56_', Job::RUNNING );
		file_put_contents( $store->dir( $marked->id ) . '/' . ImportFiles::SWAP_STARTED, '' );
		$this->import( $store, $now - 60, 'lwt0a1b_', Job::FAILED );
		$this->import( $store, $now - 60, 'lwt2c3d_', Job::DONE );

		$keep = TableOwners::keptPrefixes( $store, $now );
		sort( $keep );

		$this->assertSame(
			[
				'lwo0a1b_', 'lwo2c3d_', 'lwoab12_', 'lwocd34_', 'lwoef56_',
				'lwtab12_', 'lwtcd34_', 'lwtef56_',
				'lwxab12_', 'lwxcd34_', 'lwxef56_',
				'lwy0a1b_', 'lwy2c3d_', 'lwyab12_', 'lwycd34_', 'lwyef56_',
			],
			$keep
		);
	}

	/**
	 * @param JobStore $store  Job store.
	 * @param int      $at     Created/updated at.
	 * @param string   $tmp    state.tmpPrefix.
	 * @param string   $status Job status.
	 */
	private function import( JobStore $store, int $at, string $tmp, string $status = Job::PENDING ): Job {
		$job = $store->create( 'import', [ 'a' ], [], $at );

		$job->state['tmpPrefix'] = $tmp;
		$job->status             = $status;
		$store->save( $job );

		return $job;
	}
}
