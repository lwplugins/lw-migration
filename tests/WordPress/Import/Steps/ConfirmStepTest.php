<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use LightweightPlugins\Migration\Import\ImportState;
use LightweightPlugins\Migration\Import\ImportSummary;
use LightweightPlugins\Migration\Import\Steps\ConfirmStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

/**
 * The network side of the confirm step (Task 5); the single-site side is in DecryptConfirmTest.
 */
final class ConfirmStepTest extends MonkeyTestCase {

	private string $dir;

	/** @var array<int, array{0: string, 1: string, 2: int}> */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_the_summary_carries_the_network_part_with_existing_sites(): void {
		$job    = $this->job( ArchiveKind::SITES, [ 2, 3 ] );
		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'confirm', $result->needsInputKind() );
		$network = $result->inputData()['network'];
		$this->assertSame( 'sites', $network['kind'] );
		$this->assertSame( [ 'https://new.test/b2', 'https://new.test/b3' ], array_column( $network['sites'], 'proposal' ) );
		$this->assertSame( [ 5, null ], array_column( $network['sites'], 'existing' ) );
		$this->assertSame( [ [ 'new.test', '/b2/', 1 ], [ 'new.test', '/b3/', 1 ] ], $this->lookups );
		$this->assertSame( [ null, null ], array_column( $network['sites'], 'error' ) );
		$this->assertArrayNotHasKey( 'error', $network, 'Only problems that are not about one site go here.' );
		$this->assertContains( 'Site http://old.test/b2 -> https://new.test/b2 (replaces the site there)', ImportSummary::lines( $result->inputData() ) );
	}

	public function test_given_addresses_replace_the_proposals(): void {
		$job                 = $this->job( ArchiveKind::SITES, [ 2, 3 ] );
		$job->state['input'] = [
			'confirm' => true,
			'sites'   => [ '1' => 'https://new.test/Shop' ],
		];

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [ 'https://new.test/b2', 'https://new.test/shop' ], array_column( ImportState::blogMap( $job )->all(), 'url' ) );
		$this->assertArrayNotHasKey( 'sites', $job->state['input'] );
	}

	public function test_an_invalid_address_asks_again_with_the_reason_at_that_site(): void {
		$job                 = $this->job( ArchiveKind::SITES, [ 2, 3 ] );
		$job->state['input'] = [
			'confirm'      => true,
			'safetyBackup' => false,
			'sites'        => [ '0' => 'http://new.test/shop' ],
		];

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'confirm', $result->needsInputKind() );
		$sites = $result->inputData()['network']['sites'];
		$this->assertSame( [ 'Use the network\'s scheme (https).', null ], array_column( $sites, 'error' ) );
		$this->assertSame( 'http://new.test/shop', $sites[0]['address'], 'The refused address is shown as given.' );
		$this->assertArrayNotHasKey( 'error', $result->inputData()['network'] );
		$this->assertSame( [], $job->state['input'] );
		$this->assertTrue( $job->options['safetyBackup'], 'A refused answer changes no option.' );
	}

	public function test_a_kept_main_address_proposal_is_refused_before_the_first_answer(): void {
		$job = $this->job( ArchiveKind::SITES, [ 1, 2 ] );

		$sites = $this->step()->run( $job, new NoDeadline() )->inputData()['network']['sites'];
		$this->assertSame( [ 'The main site cannot be replaced by a subsite import: choose another address.', null ], array_column( $sites, 'error' ) );
		$this->assertContains( 'Site http://old.test -> https://new.test is refused: The main site cannot be replaced by a subsite import: choose another address.', ImportSummary::lines( $this->step()->run( $job, new NoDeadline() )->inputData() ) );

		$job->state['input'] = [ 'confirm' => true ];
		$this->assertSame( 'confirm', $this->step()->run( $job, new NoDeadline() )->needsInputKind(), 'Confirming the refused proposal asks again.' );

		$job->state['input'] = [
			'confirm' => true,
			'sites'   => [ 0 => 'https://new.test/main' ],
		];
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
	}

	/** @return array<string, array{0: mixed, 1: string}> */
	public static function badAnswers(): array {
		return [
			'not a list'            => [ 'https://new.test/shop', 'Give the addresses by site number.' ],
			'a site it lacks'       => [ [ 7 => 'https://new.test/shop' ], 'The archive has no site number 7.' ],
		];
	}

	/**
	 * @dataProvider badAnswers
	 * @param mixed $sites The answer's sites.
	 */
	public function test_an_answer_that_is_not_about_one_site_asks_again_with_a_general_reason( $sites, string $reason ): void {
		$job                 = $this->job( ArchiveKind::SITES, [ 2 ] );
		$job->state['input'] = [
			'confirm' => true,
			'sites'   => $sites,
		];

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( $reason, $result->inputData()['network']['error'] );
		$this->assertSame( 'https://new.test/b2', ImportState::blogMap( $job )->get( 0 )['url'] );
	}

	public function test_addresses_for_a_network_archive_are_refused(): void {
		$job                 = $this->job( ArchiveKind::NETWORK, [ 1, 2 ] );
		$job->state['input'] = [
			'confirm' => true,
			'sites'   => [ '1' => 'https://new.test/shop' ],
		];

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'confirm', $result->needsInputKind() );
		$this->assertSame( 'This archive holds a whole network: its sites keep their addresses.', $result->inputData()['network']['error'] );
		$this->assertSame( [], $this->lookups, 'A network archive keeps its ids: no address is looked up.' );
		$this->assertSame( 'https://new.test/b2', ImportState::blogMap( $job )->get( 1 )['url'] );

		$job->state['input'] = [ 'confirm' => true ];
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone(), 'Without addresses a network archive is confirmed as planned.' );
	}

	public function test_on_a_single_site_addresses_are_dropped_and_no_network_part_is_shown(): void {
		$job                       = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '/a.wpress' );
		$job->state['package']     = [ 'HomeURL' => 'https://old.test' ];
		$job->state['archiveSize'] = 1;

		$this->assertNull( $this->step()->run( $job, new NoDeadline() )->inputData()['network'] );

		$job->state['input'] = [
			'confirm' => true,
			'sites'   => [ '0' => 'https://new.test/x' ],
		];
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertArrayNotHasKey( 'sites', $job->state['input'] );
		$this->assertArrayNotHasKey( 'blogs', $job->state );
	}

	private function step(): ConfirmStep {
		return new ConfirmStep(
			static fn( string $d ): ?int => null,
			static fn(): int => 0,
			function ( string $domain, string $path, int $network ): ?int {
				$this->lookups[] = [ $domain, $path, $network ];
				return '/b2/' === $path ? 5 : null;
			}
		);
	}

	/**
	 * @param string          $kind Archive kind.
	 * @param array<int, int> $ids  Old blog ids.
	 */
	private function job( string $kind, array $ids ): Job {
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
					'HomeURL' => 'http://old.test' . ( 1 === $id ? '' : '/b' . $id ),
					'Domain'  => 'old.test',
					'Path'    => 1 === $id ? '/' : '/b' . $id . '/',
				]
			),
			$ids
		);
		$job     = ImportJobFixture::make( new JobStore( $this->dir ), $this->dir, '/a.wpress' );

		$job->state['package']     = [ 'HomeURL' => 'http://old.test' ];
		$job->state['archiveSize'] = 1;
		$job->state['target']      = $target->toArray();
		$job->state['kind']        = $kind;
		$job->state['blogs']       = BlogMap::plan( $kind, $entries, 'http://old.test', $target )->toArray();
		$job->state['unlisted']    = [];

		return $job;
	}
}
