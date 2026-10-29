<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Archive\Reader;
use LightweightPlugins\Migration\Import\ArchiveInventory;
use LightweightPlugins\Migration\Import\Steps\ConfirmStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\PackageCodec;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The comparison part of the confirm summary: the archive next to this site,
 * read from package.json and the entry headers only.
 */
final class ConfirmSummaryTest extends MonkeyTestCase {

	private const FIXTURES = __DIR__ . '/../../../Fixtures/wpress/';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_a_full_archive_is_compared_with_this_site(): void {
		$summary = $this->summary( $this->job( 'full-site.wpress' ) );

		$this->assertSame( 'http://site-a.test', $summary['from'] );
		$this->assertSame( 'https://new.test', $summary['to'] );
		$this->assertTrue( $summary['replacesAddress'] );
		$this->assertSame( [ 'archive' => '7.1.2', 'site' => '7.1' ], $summary['wordpress'] );
		$this->assertSame( [ 'archive' => 'wp_', 'site' => 'wp_' ], $summary['prefix'] );
		$this->assertSame( 1791386979, $summary['createdAt'] );
		$this->assertSame( 28 + 41 + 17 + 28 + 45 + 28 + 18 + 32 + 40219, $summary['expandedSize'] );
		$this->assertFalse( $summary['encrypted'] );
		$this->assertTrue( $summary['keepsThisPlugin'] );
		$this->assertSame(
			[
				'database'  => [ 'included' => true, 'bytes' => 40219, 'count' => null ],
				'media'     => [ 'included' => true, 'bytes' => 17, 'count' => 1 ],
				'plugins'   => [ 'included' => true, 'bytes' => 73, 'count' => 1 ],
				'themes'    => [ 'included' => true, 'bytes' => 78, 'count' => 1 ],
				'muPlugins' => [ 'included' => true, 'bytes' => 41, 'count' => 1 ],
			],
			$summary['parts']
		);
		$this->assertStringNotContainsString( $this->dir, (string) json_encode( $summary, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_an_archive_without_the_optional_metadata_marks_it_unknown(): void {
		$summary = $this->summary( $this->job( 'plain.wpress' ) );

		$this->assertSame( [ 'archive' => '', 'site' => '7.1' ], $summary['wordpress'] );
		$this->assertSame( [ 'archive' => '', 'site' => PHP_VERSION ], $summary['php'] );
		$this->assertSame( [ 'archive' => null, 'site' => 'wp_' ], $summary['prefix'] );
		$this->assertSame( 1767225600, $summary['createdAt'] );
		$this->assertSame( [ 'included' => false, 'bytes' => 0, 'count' => 0 ], $summary['parts']['themes'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 600000, 'count' => 1 ], $summary['parts']['plugins'] );
	}

	public function test_an_encrypted_archive_is_counted_without_its_password(): void {
		$summary = $this->summary( $this->job( 'encrypted.wpress' ) );

		$this->assertTrue( $summary['encrypted'] );
		$this->assertNull( $summary['expandedSize'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 48, 'count' => null ], $summary['parts']['database'] );
	}

	public function test_a_network_archive_counts_every_sites_media(): void {
		$summary = $this->summary( $this->networkJob() );

		$this->assertSame( 'network', $summary['network']['kind'] );
		$this->assertSame( [ 'included' => true, 'bytes' => 32, 'count' => 3 ], $summary['parts']['media'] );
		$this->assertSame( 2, $summary['parts']['plugins']['count'] );
		$this->assertSame( [ 'archive' => 'wp_', 'site' => 'wp_' ], $summary['prefix'] );
		$this->assertTrue( $summary['replacesAddress'] );
	}

	public function test_the_same_address_is_not_replaced_and_an_unknown_one_is_not_known(): void {
		$same                                = $this->job( 'plain.wpress' );
		$same->state['package']['HomeURL']   = 'https://NEW.test/';
		$unknown                             = $this->job( 'plain.wpress' );
		$unknown->state['package']['HomeURL'] = '';

		$this->assertFalse( $this->summary( $same )['replacesAddress'] );
		$this->assertNull( $this->summary( $unknown )['replacesAddress'] );
	}

	public function test_the_headers_are_read_over_several_ticks_before_asking(): void {
		$job  = $this->networkJob();
		$step = $this->step();
		$runs = 0;
		do {
			$result = $step->run( $job, new CountdownDeadline( 1 ) );
			++$runs;
			if ( null === $result->needsInputKind() ) {
				$this->assertFalse( $result->isDone() );
				$this->assertSame( 'Reading the archive\'s contents...', $result->message() );
			}
		} while ( null === $result->needsInputKind() && $runs < 50 );

		$this->assertGreaterThan( 2, $runs );
		$this->assertSame( 'confirm', $result->needsInputKind() );
		$this->assertSame( 3, $result->inputData()['parts']['media']['count'] );
		$this->assertSame( [], $job->cursor, 'The walk is kept in the state, not in the cursor.' );
		$this->assertTrue( $job->state[ ArchiveInventory::KEY ]['complete'] );
	}

	public function test_an_archive_that_cannot_be_read_still_asks_with_its_contents_unknown(): void {
		$job = $this->job( 'plain.wpress' );
		$job->state['archive']             = $this->dir . '/missing.wpress';
		$job->state['package']['NoThemes'] = true;

		$summary = $this->summary( $job );

		$this->assertNull( $summary['createdAt'] );
		$this->assertNull( $summary['expandedSize'] );
		$this->assertSame( [ 'included' => null, 'bytes' => null, 'count' => null ], $summary['parts']['media'] );
		$this->assertSame( [ 'included' => false, 'bytes' => null, 'count' => null ], $summary['parts']['themes'] );
	}

	public function test_a_header_that_fails_mid_walk_leaves_the_contents_unknown(): void {
		$damaged = $this->dir . '/damaged.wpress';
		copy( self::FIXTURES . 'plain.wpress', $damaged );
		$reader = new Reader( $damaged );
		$first  = $reader->firstEntry();
		$reader->close();
		$this->assertNotNull( $first );
		$second = $first->dataOffset() + $first->size;
		$handle = fopen( $damaged, 'r+b' );
		$this->assertIsResource( $handle );
		fseek( $handle, $second );
		fwrite( $handle, str_repeat( 'Z', Format::HEADER_SIZE ) );
		fclose( $handle );

		$job                   = $this->job( 'plain.wpress' );
		$job->state['archive'] = $damaged;
		$summary               = $this->summary( $job );

		$this->assertNull( $summary['createdAt'], 'The first header was read, but nothing of a failed walk is kept.' );
		$this->assertNull( $summary['expandedSize'] );
		$this->assertSame( [ 'included' => null, 'bytes' => null, 'count' => null ], $summary['parts']['plugins'] );
		$this->assertSame( [ 'included' => null, 'bytes' => null, 'count' => null ], $summary['parts']['database'] );
	}

	public function test_the_walk_runs_once_and_the_confirmation_still_works(): void {
		$job  = $this->job( 'plain.wpress' );
		$step = $this->step();
		$this->assertSame( 'confirm', $step->run( $job, new NoDeadline() )->needsInputKind() );
		$job->state['archive'] = $this->dir . '/gone.wpress';

		$this->assertSame( 3, count( array_filter( array_column( $step->run( $job, new NoDeadline() )->inputData()['parts'], 'included' ) ) ), 'Asking again reuses the walk.' );

		$job->state['input'] = [
			'confirm'      => true,
			'safetyBackup' => false,
		];
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );
		$this->assertFalse( $job->options['safetyBackup'] );
	}

	/**
	 * @param Job $job Import job.
	 * @return array<string, mixed>
	 */
	private function summary( Job $job ): array {
		$result = $this->step()->run( $job, new NoDeadline() );
		$this->assertSame( 'confirm', $result->needsInputKind() );

		return $result->inputData();
	}

	private function step(): ConfirmStep {
		return new ConfirmStep( static fn( string $d ): ?int => 5000, static fn(): int => 700, static fn( string $domain, string $path, int $network ): ?int => null );
	}

	private function job( string $fixture ): Job {
		$job                       = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, self::FIXTURES . $fixture );
		$job->state['package']     = PackageCodec::read( self::FIXTURES . $fixture )->toArray();
		$job->state['archiveSize'] = (int) filesize( self::FIXTURES . $fixture );

		return $job;
	}

	private function networkJob(): Job {
		$target  = TargetNetwork::fromArray(
			[
				'subdomain' => false,
				'domain'    => 'new.test',
				'path'      => '/',
				'scheme'    => 'https',
			]
		);
		$entries = array_map(
			static fn( int $id ): SiteEntry => SiteEntry::fromArray(
				[
					'BlogID'  => $id,
					'HomeURL' => 'http://net-n.test' . ( 1 === $id ? '' : '/b' . $id ),
					'Domain'  => 'net-n.test',
					'Path'    => 1 === $id ? '/' : '/b' . $id . '/',
				]
			),
			[ 1, 2, 3 ]
		);
		$job     = $this->job( 'network.wpress' );

		$job->state['target']   = $target->toArray();
		$job->state['kind']     = ArchiveKind::NETWORK;
		$job->state['blogs']    = BlogMap::plan( ArchiveKind::NETWORK, $entries, 'http://net-n.test', $target )->toArray();
		$job->state['unlisted'] = [];

		return $job;
	}
}
