<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\CrcCursor;
use LightweightPlugins\Migration\Archive\Writer;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\Steps\ValidateStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The network side of the validate step (Task 5); the single-site side is in ArchiveChecksTest.
 */
final class ValidateStepTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

	private string $dir;

	private JobStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		Functions\when( 'get_sites' )->justReturn( [ 1, 2, 3, 4 ] );
		Functions\when( 'get_home_url' )->alias( static fn( int $id ): string => 'https://new.test/s' . $id );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_network_archive_keeps_its_ids_and_lists_the_target_sites_it_lacks(): void {
		$job = $this->networkJob( self::FIXTURES . 'network.wpress' );
		$this->assertTrue( ( new ValidateStep() )->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( ArchiveKind::NETWORK, ImportState::kind( $job ) );
		$map = ImportState::blogMap( $job );
		$this->assertTrue( $map->isResolved() );
		$this->assertSame( [ 1, 2, 3 ], array_column( $map->all(), 'newId' ) );
		$this->assertSame( [ 'https://new.test', 'https://new.test/b2', 'https://new.test/b3' ], array_column( $map->all(), 'url' ) );
		$this->assertSame( [ [ 'id' => 4, 'url' => 'https://new.test/s4' ] ], $job->state['unlisted'] );
		$this->assertTrue( $job->state['multisite']['network'] );
		$this->assertSame( [ 'lwm-net-sample/lwm-net-sample.php' ], $job->state['multisite']['plugins'] );
		$this->assertSame( [ 'admin' ], $job->state['multisite']['admins'] );
		$this->assertCount( 3, $job->state['multisite']['sites'] );
		$this->assertNull( $job->state['subsiteId'], 'The single-site subsite routing stays off on a network.' );
	}

	public function test_a_sites_archive_waits_for_its_addresses(): void {
		$job = $this->networkJob( self::FIXTURES . 'sites-2.wpress' );
		( new ValidateStep() )->run( $job, new NoDeadline() );

		$this->assertSame( ArchiveKind::SITES, ImportState::kind( $job ) );
		$map = ImportState::blogMap( $job );
		$this->assertSame( 1, $map->count() );
		$this->assertNull( $map->get( 0 )['newId'] );
		$this->assertSame( 'https://new.test/b2', $map->get( 0 )['proposal'] );
		$this->assertFalse( $map->isResolved() );
		$this->assertSame( [], $job->state['unlisted'], 'Only a network archive leaves sites out.' );
		$this->assertFalse( $job->state['multisite']['network'] );
	}

	public function test_a_single_site_archive_is_one_site_from_its_package(): void {
		$job = $this->networkJob( self::FIXTURES . 'full-site.wpress' );
		( new ValidateStep() )->run( $job, new NoDeadline() );

		$this->assertSame( ArchiveKind::SINGLE, ImportState::kind( $job ) );
		$map = ImportState::blogMap( $job );
		$this->assertNull( $map->get( 0 )['old']['BlogID'] );
		$this->assertSame( 'http://site-a.test', $map->get( 0 )['old']['HomeURL'] );
		$this->assertSame( 'https://new.test/site-a', $map->get( 0 )['url'] );
		$this->assertSame( [ 'network', 'sites', 'plugins', 'admins' ], array_keys( $job->state['multisite'] ) );
		$this->assertFalse( $job->state['multisite']['network'] );
		$this->assertSame( [ 'http://site-a.test' ], array_column( $job->state['multisite']['sites'], 'HomeURL' ) );
		$this->assertSame( [ [], [] ], [ $job->state['multisite']['plugins'], $job->state['multisite']['admins'] ] );
	}

	public function test_a_network_archive_of_more_networks_is_refused(): void {
		$archive = $this->crafted( '{"Network":true,"Networks":[{"SiteID":1},{"SiteID":2}],"Sites":[{"BlogID":1}]}' );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'This archive holds more than one network. Restoring it is not supported.' );
		( new ValidateStep() )->run( $this->networkJob( $archive ), new NoDeadline() );
	}

	public function test_an_archive_listing_one_site_id_twice_is_refused(): void {
		$archive = $this->crafted( '{"Network":false,"Sites":[{"BlogID":2,"Domain":"net.test","Path":"/b2/"},{"BlogID":2,"Domain":"net.test","Path":"/b3/"}]}' );
		$job     = $this->networkJob( $archive );

		try {
			( new ValidateStep() )->run( $job, new NoDeadline() );
			$this->fail( 'An archive with one site id twice was accepted.' );
		} catch ( ImportException $e ) {
			$this->assertStringContainsString( 'may be damaged', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( 'blogs', $job->state );
	}

	private function crafted( string $multisite ): string {
		$archive = $this->dir . '/crafted.wpress';
		$writer  = new Writer( $archive );
		$writer->addString( 'package.json', '{"SiteURL":"https://net.test","HomeURL":"https://net.test"}', 1 );
		$writer->addString( 'multisite.json', $multisite, 1 );
		$writer->finalize( new CrcCursor(), new NoDeadline() );
		$writer->close();

		return $archive;
	}

	public function test_on_a_single_site_a_network_archive_is_still_refused(): void {
		$job = ImportJobFixture::make( $this->store, $this->dir, self::FIXTURES . 'network.wpress' );

		$this->expectException( ImportException::class );
		$this->expectExceptionMessage( 'This archive holds a whole multisite network. It can only be restored into a multisite network, not into a single site.' );
		( new ValidateStep() )->run( $job, new NoDeadline() );
	}

	private function networkJob( string $archive ): Job {
		$job                  = ImportJobFixture::make( $this->store, $this->dir, $archive );
		$job->state['target'] = [
			'subdomain'  => false,
			'domain'     => 'new.test',
			'path'       => '/',
			'scheme'     => 'https',
			'uploadsDir' => '/srv/wp-content/uploads',
			'uploadsUrl' => 'https://new.test/wp-content/uploads',
			'reserved'   => [ 'page' ],
		];

		return $job;
	}
}
