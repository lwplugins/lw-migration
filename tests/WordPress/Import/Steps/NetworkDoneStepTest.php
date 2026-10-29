<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\Steps\NetworkDoneStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\SiteEntry;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkDoneStepTest extends MonkeyTestCase {

	/** @var array<int, string> WordPress calls and fixes, in order. */
	private array $log = [];

	/** @var array<string, mixed>|null Value of active_sitewide_plugins; null: never written. */
	private ?array $written = null;

	protected function setUp(): void {
		parent::setUp();
		$this->log = [];
		Functions\when( 'switch_to_blog' )->alias( fn( $id ) => $this->log[] = 'switch ' . $id );
		Functions\when( 'restore_current_blog' )->alias( fn() => $this->log[] = 'restore' );
		Functions\when( 'clean_blog_cache' )->alias( fn( $id ) => $this->log[] = 'clean ' . $id );
		Functions\when( 'delete_option' )->alias( fn( $name ) => $this->log[] = 'delete ' . $name );
	}

	public function test_each_blog_is_finished_inside_its_own_switch(): void {
		$job = self::job( ArchiveKind::SITES, [ 7, 8 ] );

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$fixes = static fn( int $id ): array => array_map( static fn( string $t ): string => sprintf( 'fix %s http://new.test/s%d /w/uploads/sites/%d', $t, $id, $id ), NetworkDoneStep::TASKS );
		$this->assertSame(
			array_merge(
				[ 'switch 7' ],
				$fixes( 7 ),
				[ 'delete rewrite_rules', 'restore', 'clean 7', 'switch 8' ],
				$fixes( 8 ),
				[ 'delete rewrite_rules', 'restore', 'clean 8' ]
			),
			$this->log
		);
	}

	public function test_the_switch_is_undone_when_a_fix_throws(): void {
		$job  = self::job( ArchiveKind::SITES, [ 7 ] );
		$step = $this->step( static function ( string $task ): void {
			if ( 'jetpack' === $task ) {
				throw new \RuntimeException( 'broken' );
			}
		} );

		try {
			$step->run( $job, new NoDeadline() );
			$this->fail( 'The fix failure is not swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'broken', $e->getMessage() );
		}

		$this->assertSame( 'restore', end( $this->log ) );
		$this->assertSame( 0, $job->cursor['blog'], 'The site is finished again on the next try.' );
	}

	public function test_warnings_of_a_blog_fix_name_the_blog(): void {
		$job  = self::job( ArchiveKind::SITES, [ 7 ] );
		$step = $this->step( static function ( string $task, Job $scratch ): void {
			if ( 'woocommerce' === $task ) {
				$scratch->addWarning( 'Turned off the secure checkout.' );
			}
		} );

		$step->run( $job, new NoDeadline() );

		$this->assertSame( [ 'http://new.test/s7: Turned off the secure checkout.' ], $job->warnings );
	}

	public function test_network_plugins_of_a_sites_archive_are_merged_validated(): void {
		$job      = self::job( ArchiveKind::SITES, [ 7 ], [ 'net/net.php', 'gone/gone.php', 'kept/kept.php' ] );
		$existing = [
			'lw-migration/lw-migration.php' => 100,
			'kept/kept.php'                 => 200,
		];
		$this->sitewide( $existing );

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertFalse( $result->isDone(), 'The sites are finished in the next unit of work.' );
		$this->assertSame( 0, $job->cursor['blog'] );
		$this->assertSame( $existing + [ 'net/net.php' => 1234 ], $this->written, 'The installed one is added with a time value; existing entries and this plugin stay.' );
		$this->assertCount( 1, $job->warnings );
		$this->assertStringContainsString( 'gone/gone.php', $job->warnings[0] );
		$this->assertSame( [], $this->log, 'No site is switched to in that unit.' );
	}

	/**
	 * Final review I1: a network plugin the network did not have before the
	 * import is never activated (NetworkPreserve named it already).
	 */
	public function test_network_plugins_the_network_did_not_have_stay_inactive(): void {
		$job                       = self::job( ArchiveKind::SITES, [ 7 ], [ 'net/net.php', 'kept/kept.php' ] );
		$job->state['networkCode'] = [
			'plugins' => [ 'lw-migration', 'kept' ],
			'themes'  => [],
		];
		$this->sitewide( [ 'lw-migration/lw-migration.php' => 100 ] );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 'lw-migration/lw-migration.php' => 100, 'kept/kept.php' => 1234 ], $this->written );
		$this->assertSame( [], $job->warnings );
	}

	public function test_a_replayed_plugin_merge_changes_nothing_and_warns_once(): void {
		$job = self::job( ArchiveKind::SITES, [ 7 ], [ 'net/net.php', 'gone/gone.php' ] );
		$this->sitewide( [ 'net/net.php' => 99 ] );
		$job->addWarning( 'gone/gone.php stays inactive: it is not installed on this site.' );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertNull( $this->written );
		$this->assertCount( 1, $job->warnings );
	}

	public function test_a_network_archive_does_not_touch_sitewide_plugins_again(): void {
		$job = self::job( ArchiveKind::NETWORK, [ 1, 2 ], [ 'net/net.php' ] );
		Functions\expect( 'get_site_option' )->never();
		Functions\expect( 'update_site_option' )->never();

		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );

		$this->assertSame( 'switch 2', $this->log[0], 'The main site was finished by the post-import step (ruling R12).' );
		$this->assertNotContains( 'switch 1', $this->log );
	}

	public function test_the_main_site_is_never_finished_for_any_kind(): void {
		$job = self::job( ArchiveKind::SITES, [ 1, 7 ] );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertNotContains( 'switch 1', $this->log );
		$this->assertSame( 'switch 7', $this->log[0] );
	}

	public function test_one_blog_per_unit_of_work(): void {
		$job = self::job( ArchiveKind::SITES, [ 7, 8 ] );

		$result = $this->step()->run( $job, new CountdownDeadline( 0 ) );

		$this->assertFalse( $result->isDone() );
		$this->assertSame( 1, $job->cursor['blog'] );
		$this->assertSame( 'clean 7', end( $this->log ) );

		$this->log = [];
		$this->assertTrue( $this->step()->run( $job, new CountdownDeadline( 0 ) )->isDone() );
		$this->assertSame( 'switch 8', $this->log[0] );
	}

	public function test_a_single_site_or_a_restore_without_database_does_nothing(): void {
		$single = new Job( '0123456789abcdef', 'import', [] );
		$job    = self::job( ArchiveKind::SITES, [ 7 ] );
		unset( $job->state['hasDatabase'] );

		$this->assertTrue( $this->step()->run( $single, new NoDeadline() )->isDone() );
		$this->assertTrue( $this->step()->run( $job, new NoDeadline() )->isDone() );
		$this->assertSame( [], $this->log );
	}

	/**
	 * @param \Closure|null $fix fn( string $task, Job $job, string $home, string $uploads ): void; null logs the call.
	 */
	private function step( ?\Closure $fix = null ): NetworkDoneStep {
		$fix = $fix ?? function ( string $task, Job $job, string $home, string $uploads ): void {
			$this->log[] = sprintf( 'fix %s %s %s', $task, $home, $uploads );
		};

		return new NetworkDoneStep(
			$fix,
			static fn(): array => [
				'lw-migration/lw-migration.php' => '1.0.0',
				'net/net.php'                   => '1.0',
				'kept/kept.php'                 => '1.0',
			],
			static fn(): int => 1234
		);
	}

	/**
	 * @param array<string, mixed> $current active_sitewide_plugins before the step.
	 */
	private function sitewide( array $current ): void {
		Functions\when( 'get_site_option' )->justReturn( $current );
		Functions\when( 'update_site_option' )->alias(
			function ( $name, $value ): bool {
				$this->assertSame( 'active_sitewide_plugins', $name );
				$this->written = $value;
				return true;
			}
		);
	}

	/**
	 * A confirmed and swapped restore into http://new.test whose sites are resolved to $ids.
	 *
	 * @param string             $kind    ArchiveKind value.
	 * @param array<int, int>    $ids     New ids, one site each (old id = new id for a network archive).
	 * @param array<int, string> $plugins Network-active plugins of the archive.
	 */
	private static function job( string $kind, array $ids, array $plugins = [] ): Job {
		$target  = TargetNetwork::fromArray(
			[
				'subdomain'  => false,
				'domain'     => 'new.test',
				'path'       => '/',
				'scheme'     => 'http',
				'uploadsDir' => '/w/uploads',
			]
		);
		$entries = array_map(
			static fn( int $id ): SiteEntry => SiteEntry::fromArray(
				[
					'BlogID'  => ArchiveKind::NETWORK === $kind ? $id : $id + 10,
					'HomeURL' => 'http://old.test/b' . $id,
					'Domain'  => 'old.test',
					'Path'    => 1 === $id ? '/' : '/b' . $id . '/',
				]
			),
			$ids
		);
		$map     = BlogMap::plan( $kind, $entries, 'http://old.test', $target );
		foreach ( $ids as $i => $id ) {
			$map = $map->resolved(
				$i,
				[
					'newId'         => $id,
					'newHomeUrl'    => 'http://new.test/s' . $id,
					'newUploadsDir' => '/w/uploads/sites/' . $id,
				]
			);
		}
		$job        = new Job( '0123456789abcdef', 'import', [ NetworkDoneStep::KEY ] );
		$job->state = [
			'site'        => SiteFixture::make( '/w', [ 'homeUrl' => 'http://new.test' ] )->toArray(),
			'target'      => $target->toArray(),
			'kind'        => $kind,
			'blogs'       => $map->toArray(),
			'hasDatabase' => true,
			'multisite'   => [ 'plugins' => $plugins ],
		];
		if ( [] === $plugins ) {
			$job->cursor['blog'] = 0;
		}

		return $job;
	}
}
