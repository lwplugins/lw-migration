<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\Steps\NetworkSitesStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeSiteFactory;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkSitesStepTest extends MonkeyTestCase {

	private string $dir;

	private JobStore $store;

	private FakeSiteFactory $sites;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->sites = new FakeSiteFactory();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_an_existing_site_at_the_address_is_reused(): void {
		$this->sites->sites = [ 'new.test/b2/' => 5 ];
		$job                = $this->job( [ 2 ] );

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 'find new.test/b2/', 'describe 5' ], $this->sites->calls, 'Nothing is created.' );
		$map = ImportState::blogMap( $job )->get( 0 );
		$this->assertSame( [ 5, false ], [ $map['newId'], $map['created'] ] );
		$this->assertSame( [ 'https://new.test/s5', 'https://new.test/s5', '/w/uploads/sites/5', 'https://new.test/wp-content/uploads/sites/5/', '', '' ], [ $map['newSiteUrl'], $map['newHomeUrl'], $map['newUploadsDir'], $map['newUploadsUrl'], $map['newUploadPath'], $map['newUploadUrlPath'] ] );
		$this->assertArrayNotHasKey( 'createdSites', $job->state );
	}

	public function test_a_missing_site_is_created_and_recorded_before_anything_else(): void {
		$this->sites->sites    = [ 'new.test/' => 1 ];
		$job                   = $this->job( [ 2 ] );
		$job->state['admin']   = [
			'id'    => 3,
			'login' => 'boss',
		];
		$saved                 = [];
		$this->sites->onCreate = function () use ( $job, &$saved ): void {
			$saved = $this->store->load( $job->id )->state;
		};

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 'find new.test/b2/', 'maxId', 'create new.test/b2/ "new.test/b2" user 3', 'describe 7' ], $this->sites->calls );
		$this->assertSame(
			[
				'index'       => 0,
				'domain'      => 'new.test',
				'path'        => '/b2/',
				'maxIdBefore' => 1,
			],
			$saved['creating'] ?? null,
			'The intent is saved before the site is created.'
		);
		$after = $this->store->load( $job->id )->state;
		$this->assertSame( [ 7 ], $after['createdSites'], 'The created site is saved right after the create.' );
		$this->assertArrayNotHasKey( 'creating', $after );
		$map = ImportState::blogMap( $job )->get( 0 );
		$this->assertSame( [ 7, true ], [ $map['newId'], $map['created'] ] );
	}

	public function test_a_replay_after_the_create_counts_the_site_as_created(): void {
		$this->sites->sites     = [
			'new.test/'    => 1,
			'new.test/b2/' => 7,
		];
		$job                    = $this->job( [ 2 ] );
		$job->state['creating'] = [
			'index'       => 0,
			'domain'      => 'new.test',
			'path'        => '/b2/',
			'maxIdBefore' => 6,
		];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertNotContains( 'create new.test/b2/ "new.test/b2" user 0', $this->sites->calls, 'A replayed step never creates a second site.' );
		$this->assertSame( [ 7 ], $job->state['createdSites'] );
		$this->assertArrayNotHasKey( 'creating', $job->state );
		$this->assertTrue( ImportState::blogMap( $job )->get( 0 )['created'] );
	}

	public function test_the_new_row_is_recorded_before_the_site_is_set_up(): void {
		$job                   = $this->job( [ 2 ] );
		$saved                 = [];
		$this->sites->onCreate = function () use ( $job, &$saved ): void {
			$saved = $this->store->load( $job->id )->state;
		};

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 7 ], $saved['createdSites'] ?? null, 'Saved from the wp_insert_site action, while WordPress still sets the site up.' );
	}

	public function test_a_refused_create_forgets_its_intent_so_the_undo_never_takes_another_site(): void {
		$this->sites->raced = 8;
		$job                = $this->job( [ 2 ] );

		try {
			$this->step()->run( $job, new NoDeadline() );
			$this->fail( 'The refusal stops the import.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'Sorry, that site already exists!', $e->getMessage() );
		}

		$saved = $this->store->load( $job->id );
		$this->assertArrayNotHasKey( 'creating', $saved->state );
		$this->assertSame( [], $this->sites->undoCreated( $saved, $this->store->dir( $job->id ) ) );
		$this->assertNotContains( 'delete 8', $this->sites->calls, 'Site 8 is newer than the intent, but another request made it.' );
	}

	public function test_a_replayed_create_finishes_the_site_setup(): void {
		$this->sites->sites     = [ 'new.test/b2/' => 7 ];
		$job                    = $this->job( [ 2 ] );
		$job->state['creating'] = [
			'index'       => 0,
			'domain'      => 'new.test',
			'path'        => '/b2/',
			'maxIdBefore' => 6,
		];

		$this->step()->run( $job, new NoDeadline() );

		$this->assertContains( 'finishSetup 7 "new.test/b2" user 0', $this->sites->calls );
	}

	public function test_a_reused_site_is_left_as_it_is(): void {
		$this->sites->sites = [ 'new.test/b2/' => 5 ];
		$job                = $this->job( [ 2 ] );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertNotContains( 'finishSetup 5 "new.test/b2" user 0', $this->sites->calls );
	}

	public function test_a_replay_that_finds_an_older_site_reuses_it(): void {
		$this->sites->sites     = [ 'new.test/b2/' => 5 ];
		$job                    = $this->job( [ 2 ] );
		$job->state['creating'] = [
			'index'       => 0,
			'domain'      => 'new.test',
			'path'        => '/b2/',
			'maxIdBefore' => 6,
		];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertArrayNotHasKey( 'createdSites', $job->state, 'Only an id above the largest one before the create counts as created.' );
		$this->assertFalse( ImportState::blogMap( $job )->get( 0 )['created'] );
	}

	public function test_a_replay_after_the_saved_create_keeps_it_created(): void {
		$this->sites->sites         = [ 'new.test/b2/' => 7 ];
		$job                        = $this->job( [ 2 ] );
		$job->state['createdSites'] = [ 7 ];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 7 ], $job->state['createdSites'] );
		$this->assertTrue( ImportState::blogMap( $job )->get( 0 )['created'] );
		$this->assertContains( 'finishSetup 7 "new.test/b2" user 0', $this->sites->calls, 'The request may have died while WordPress set the site up.' );
	}

	public function test_a_replay_whose_create_never_ran_creates_the_site(): void {
		$job                    = $this->job( [ 2 ] );
		$job->state['creating'] = [
			'index'       => 0,
			'domain'      => 'new.test',
			'path'        => '/b2/',
			'maxIdBefore' => 6,
		];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 7 ], $job->state['createdSites'] );
	}

	public function test_a_network_on_a_port_finds_and_creates_its_sites_with_the_port(): void {
		$target              = TargetNetwork::fromArray(
			[
				'domain'     => '127.0.0.1',
				'port'       => 8093,
				'path'       => '/',
				'scheme'     => 'http',
				'networkId'  => 1,
				'mainSiteId' => 1,
			]
		);
		$job                 = $this->job( [ 2 ] );
		$job->state['target'] = $target->toArray();
		$job->state['blogs']  = BlogMap::fromArray( [ [ 'url' => 'http://127.0.0.1:8093/shop' ] ] )->toArray();

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( [ 'find 127.0.0.1:8093/shop/', 'maxId', 'create 127.0.0.1:8093/shop/ "127.0.0.1:8093/shop" user 0', 'describe 7' ], $this->sites->calls, 'wp_blogs.domain holds host:port on such a network (ruling R9).' );
	}

	public function test_the_main_site_is_never_reused(): void {
		$this->sites->sites = [ 'new.test/b2/' => 1 ];
		$job                = $this->job( [ 2 ] );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'The main site cannot be replaced by a subsite import: choose another address.' );
		$this->step()->run( $job, new NoDeadline() );
	}

	public function test_one_site_per_unit_of_work(): void {
		$job  = $this->job( [ 2, 3, 4 ] );
		$step = $this->step();

		$first = $step->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $first->isDone() );
		$this->assertSame( 'Preparing site 2 of 3...', $first->message() );
		$this->assertSame( [ 7, null, null ], array_column( ImportState::blogMap( $job )->all(), 'newId' ) );
		$this->assertArrayNotHasKey( 'networkCode', $job->state );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [ 7, 8, 9 ], array_column( ImportState::blogMap( $job )->all(), 'newId' ) );
		$this->assertSame( [ 7, 8, 9 ], $job->state['createdSites'] );
	}

	public function test_the_networks_code_is_listed_once_the_sites_are_ready(): void {
		$job = $this->job( [ 2 ] );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame(
			[
				'plugins' => [ 'akismet' ],
				'themes'  => [],
			],
			$job->state['networkCode']
		);
	}

	public function test_a_network_archive_creates_nothing(): void {
		$job                 = $this->job( [ 1, 2 ] );
		$job->state['kind']  = ArchiveKind::NETWORK;
		$job->state['blogs'] = [];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [], $this->sites->calls );
		$this->assertArrayNotHasKey( 'networkCode', $job->state );
	}

	private function step(): NetworkSitesStep {
		return new NetworkSitesStep(
			$this->store,
			$this->sites,
			static fn( Job $job ): array => [
				'plugins' => [ 'akismet' ],
				'themes'  => [],
			]
		);
	}

	/**
	 * A sites archive restore into the subdirectory network https://new.test, confirmed.
	 *
	 * @param array<int, int> $ids Blog ids of the archive.
	 */
	private function job( array $ids ): Job {
		$target  = TargetNetwork::fromArray(
			[
				'subdomain'  => false,
				'domain'     => 'new.test',
				'path'       => '/',
				'scheme'     => 'https',
				'networkId'  => 1,
				'mainSiteId' => 1,
			]
		);
		$entries = array_map(
			static fn( int $id ): SiteEntry => SiteEntry::fromArray(
				[
					'BlogID'  => $id,
					'HomeURL' => 'http://old.test/b' . $id,
					'Domain'  => 'old.test',
					'Path'    => '/b' . $id . '/',
				]
			),
			$ids
		);
		$job     = ImportJobFixture::make( $this->store, $this->dir, '/a.wpress' );

		$job->state['target'] = $target->toArray();
		$job->state['kind']   = ArchiveKind::SITES;
		$job->state['blogs']  = BlogMap::plan( ArchiveKind::SITES, $entries, 'http://old.test', $target )->toArray();
		$this->store->save( $job );

		return $job;
	}
}
