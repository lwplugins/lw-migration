<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Link;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportOptions;
use LightweightPlugins\Migration\Export\ExportPipeline;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\PushJobs;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteBusyException;
use LightweightPlugins\Migration\WordPress\SiteLock;

final class PushJobsTest extends MonkeyTestCase {

	private const LINK = [
		'id'      => '0123456789abcdef',
		'name'    => 'Site B',
		'address' => 'https://b.example',
		'allow'   => [ 'push' ],
	];

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

	private function create( ExportOptions $options, bool $safety = true, int $now = 100 ): Job {
		return PushJobs::create( new JobStore( $this->dir ), new SiteLock(), SiteFixture::make( '/s' ), $options, self::LINK, $safety, $now );
	}

	public function test_a_push_exports_then_sends_restores_and_cleans(): void {
		$this->assertSame( array_merge( ExportPipeline::LINK_STEPS, [ 'link_upload', 'link_import', 'link_clean' ] ), PushJobs::steps() );
		$options = ExportOptions::fromArray( [ 'noMedia' => true, 'compression' => 'gzip' ] );

		$job   = $this->create( $options, false );
		$saved = ( new JobStore( $this->dir ) )->load( $job->id );

		$this->assertSame( [ PushJobs::TYPE, PushJobs::steps() ], [ $saved->type, $saved->steps ] );
		$this->assertSame( $options->toArray(), $saved->options );
		$this->assertSame(
			[
				'id'      => '0123456789abcdef',
				'name'    => 'Site B',
				'address' => 'https://b.example',
			],
			$saved->state['link']
		);
		$this->assertSame( [ 'safetyBackup' => false ], $saved->state['remote'] );
		$this->assertSame( 'https://old.test', $saved->state['site']['homeUrl'] );
		$this->assertSame( $job->id, ( new SiteLock() )->holder( 101 ) );
	}

	public function test_an_encrypted_or_a_sites_push_and_a_network_are_refused_before_anything_is_written(): void {
		foreach ( [ 'encrypt' => [ 'encrypt' => true ], 'sites' => [ 'sites' => [ 2 ] ] ] as $label => $raw ) {
			try {
				$this->create( ExportOptions::fromArray( $raw ) );
				$this->fail( 'Refused: ' . $label );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'A push is never encrypted and never chooses sites: the other site restores the archive without a password, and a connection joins two single sites.', $e->getMessage(), $label );
			}
		}
		Functions\when( 'is_multisite' )->justReturn( true );
		try {
			$this->create( new ExportOptions() );
			$this->fail( 'Refused on a network.' );
		} catch ( ExportException $e ) {
			$this->assertSame( 'Connections work between single sites only.', $e->getMessage() );
		}
		$this->assertSame( [], ( new JobStore( $this->dir ) )->ids() );
		$this->assertNull( ( new SiteLock() )->holder( 101 ) );
	}

	public function test_a_busy_site_leaves_no_job(): void {
		$first = $this->create( new ExportOptions() );

		try {
			$this->create( new ExportOptions(), true, 102 );
			$this->fail( 'A busy site refuses the push.' );
		} catch ( SiteBusyException $e ) {
			$this->assertSame( [ $first->id ], ( new JobStore( $this->dir ) )->ids() );
		}
	}
}
