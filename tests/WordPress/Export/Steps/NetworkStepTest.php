<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Steps;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\ExportException;
use LightweightPlugins\Migration\Export\ExportFiles;
use LightweightPlugins\Migration\Export\Steps\NetworkStep;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\JobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkStepTest extends MonkeyTestCase {

	private const OWN = 'lw-migration/lw-migration.php';

	private string $dir;

	private JobStore $store;

	/** @var array<int, array{plugins: array<int, string>, template: string, stylesheet: string}> Blogs by id. */
	private array $blogs = [];

	/** @var int Blog the fake WordPress is switched to. */
	private int $current = 1;

	/** @var array<int, array{0: int, 1: int}> after/limit of every ids() page. */
	private array $pages = [];

	/** @var array<int, string> ids() pages and runtime cache flushes, in order. */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir   = TempDir::create();
		$this->store = new JobStore( $this->dir . '/jobs' );
		$this->fakeNetwork();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_sites_are_read_in_slices_and_appended_once(): void {
		$this->blogs( 120 );
		$job  = JobFixture::export( $this->store, $this->dir );
		$step = $this->step();

		$first = $step->run( $job, new CountdownDeadline( 0 ) );
		$this->assertFalse( $first->isDone() );
		$this->assertSame( [ [ 0, 50 ] ], $this->pages, 'One slice of 50 per deadline check.' );
		$this->assertSame( 50, $job->cursor['after'] );
		$this->assertSame( 50, count( $this->lines( $job ) ) );
		$this->assertSame( filesize( $this->work( $job ) ), $job->cursor['bytes'] );
		$this->assertStringContainsString( '50', $first->message() );

		// A request that died after writing more lines than it saved: they are cut off.
		file_put_contents( $this->work( $job ), "{\"BlogID\":51}\n", FILE_APPEND );
		$second = $step->run( $job, new CountdownDeadline( 0 ) );
		$this->assertFalse( $second->isDone() );
		$this->assertSame( [ 0, 50 ], $this->pages[0] );
		$this->assertSame( [ 50, 50 ], $this->pages[1], 'The second call continues after the cursor.' );
		$this->assertTrue( $step->run( $job, new NoDeadline() )->isDone() );

		$ids = array_map( static fn( array $entry ): int => (int) $entry['BlogID'], $this->lines( $job ) );
		$this->assertSame( range( 1, 120 ), $ids, 'Every site once, in order.' );
		$this->assertSame( range( 1, 120 ), $job->state['network']['blogIds'] );
	}

	public function test_the_runtime_cache_is_flushed_once_per_slice(): void {
		$this->blogs( 120 );
		$job = JobFixture::export( $this->store, $this->dir );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 'page', 'flush', 'page', 'flush', 'page', 'flush' ], $this->calls, 'Every switched blog\'s cached options are let go after its slice.' );
	}

	public function test_a_site_deleted_while_listing_the_whole_network_is_left_out(): void {
		$this->blogs( 3 );
		$job = JobFixture::export( $this->store, $this->dir );
		Functions\when( 'get_site' )->alias( fn( int $id ) => 2 === $id ? null : $this->siteRow( $id ) );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( 'network', $job->state['network']['mode'] );
		$this->assertSame( [ 1, 3 ], $job->state['network']['blogIds'] );
		$this->assertSame( [ 1, 3 ], array_map( static fn( array $entry ): int => (int) $entry['BlogID'], $this->lines( $job ) ) );
		$this->assertSame( [ 'Site 2 no longer exists and was left out.' ], $job->newWarnings );
	}

	public function test_the_whole_network_lists_every_blog(): void {
		$this->blogs( 3 );
		$job = JobFixture::export( $this->store, $this->dir );

		$result = $this->step()->run( $job, new NoDeadline() );

		$this->assertTrue( $result->isDone() );
		$network = $job->state['network'];
		$this->assertSame( 'network', $network['mode'] );
		$this->assertSame( [], $network['chosen'] );
		$this->assertSame( [ 1, 2, 3 ], $network['blogIds'] );
		$this->assertSame(
			[
				[
					'SiteID' => 1,
					'Domain' => 'n.test',
					'Path'   => '/',
				],
			],
			$network['networks']
		);
		$this->assertSame( [ 'net/net.php' ], $network['plugins'] );
		$this->assertSame( [ 'admin' ], $network['admins'] );
		$first = $this->lines( $job )[1];
		$this->assertSame( [ 'BlogID', 'SiteID', 'LangID', 'SiteURL', 'HomeURL', 'Domain', 'Path', 'Plugins', 'Template', 'Stylesheet', 'Uploads', 'UploadsURL', 'WordPress' ], array_keys( $first ) );
		$this->assertSame( '/b2/', $first['Path'] );
		$this->assertNotContains( self::OWN, $first['Plugins'], 'This plugin is never listed.' );
		$this->assertSame( 1, $this->current, 'The main site is current again.' );
	}

	public function test_chosen_sites_keep_only_existing_ids(): void {
		$this->blogs( 4 );
		unset( $this->blogs[3] );
		$job = JobFixture::export( $this->store, $this->dir, [ 'sites' => [ 1, 3, 4 ] ] );

		$this->step()->run( $job, new NoDeadline() );

		$network = $job->state['network'];
		$this->assertSame( 'sites', $network['mode'] );
		$this->assertSame( [ 1, 4 ], $network['chosen'] );
		$this->assertSame( [ 1, 2, 4 ], $network['blogIds'] );
		$this->assertSame( [ 1, 4 ], array_map( static fn( array $entry ): int => (int) $entry['BlogID'], $this->lines( $job ) ) );
		$this->assertSame( [ 'Site 3 no longer exists and was left out.' ], $job->newWarnings );

		$gone = JobFixture::export( $this->store, $this->dir, [ 'sites' => [ 3 ] ] );
		Functions\when( '__' )->alias( static fn( string $text ): string => 'T:' . $text );
		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'T:None of the chosen sites exist.' );
		$this->step()->run( $gone, new NoDeadline() );
	}

	public function test_a_site_deleted_while_listing_is_left_out(): void {
		$this->blogs( 3 );
		$job = JobFixture::export( $this->store, $this->dir, [ 'sites' => [ 2, 3 ] ] );
		Functions\when( 'get_site' )->alias( fn( int $id ) => 3 === $id ? null : $this->siteRow( $id ) );

		$this->step()->run( $job, new NoDeadline() );

		$this->assertSame( [ 2 ], $job->state['network']['chosen'] );
		$this->assertSame( [ 1, 2 ], $job->state['network']['blogIds'], 'A vanished site leaves the id list too.' );
		$this->assertSame( [ 'Site 3 no longer exists and was left out.' ], $job->newWarnings );
	}

	public function test_the_union_of_active_plugins_and_themes(): void {
		$this->blogs( 3 );
		$this->blogs[2] = [
			'plugins'    => [ 'shop/shop.php', self::OWN ],
			'template'   => 'parent',
			'stylesheet' => 'child',
		];
		$this->blogs[3] = [
			'plugins'    => [ 'forms/forms.php', 'shop/shop.php' ],
			'template'   => 'other',
			'stylesheet' => 'other',
		];

		$all = JobFixture::export( $this->store, $this->dir );
		$this->step()->run( $all, new NoDeadline() );
		$this->assertSame( [ 'net/net.php', 'shop/shop.php', 'forms/forms.php' ], $all->state['network']['activePlugins'] );
		$this->assertSame( [ 'main-theme', 'parent', 'child', 'other' ], $all->state['network']['activeThemes'] );

		$two = JobFixture::export( $this->store, $this->dir, [ 'sites' => [ 2 ] ] );
		$this->step()->run( $two, new NoDeadline() );
		$this->assertSame( [ 'net/net.php', 'shop/shop.php' ], $two->state['network']['activePlugins'], 'Only the exported sites count, plus the network-active plugins.' );
		$this->assertSame( [ 'parent', 'child' ], $two->state['network']['activeThemes'] );
	}

	public function test_a_value_that_is_not_utf8_names_the_site(): void {
		$this->blogs( 2 );
		$this->blogs[2]['template'] = "bad\xff";
		$job                        = JobFixture::export( $this->store, $this->dir );

		$this->expectException( ExportException::class );
		$this->expectExceptionMessage( 'Site 2' );
		$this->step()->run( $job, new NoDeadline() );
	}

	private function step(): NetworkStep {
		return new NetworkStep( $this->store, new NetworkReader() );
	}

	private function work( Job $job ): string {
		return $this->store->dir( $job->id ) . '/' . ExportFiles::NETWORK_SITES;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function lines( Job $job ): array {
		$lines = array_filter( explode( "\n", (string) file_get_contents( $this->work( $job ) ) ), static fn( string $l ): bool => '' !== $l );

		return array_values( array_map( static fn( string $l ): array => (array) json_decode( $l, true ), $lines ) );
	}

	private function blogs( int $count ): void {
		for ( $id = 1; $id <= $count; $id++ ) {
			$this->blogs[ $id ] = [
				'plugins'    => 1 === $id ? [ self::OWN ] : [],
				'template'   => 'main-theme',
				'stylesheet' => 'main-theme',
			];
		}
	}

	private function siteRow( int $id ): ?object {
		return isset( $this->blogs[ $id ] ) ? (object) [
			'blog_id' => (string) $id,
			'domain'  => 'n.test',
			'path'    => 1 === $id ? '/' : '/b' . $id . '/',
			'site_id' => '1',
			'lang_id' => '0',
		] : null;
	}

	/**
	 * A network in memory: wp_blogs pages, per-blog options, the switch stack.
	 */
	private function fakeNetwork(): void {
		$test            = $this;
		$GLOBALS['wpdb'] = new class( $test ) {
			public string $blogs = 'wp_blogs';

			public function __construct( private NetworkStepTest $test ) {
			}

			/**
			 * @param mixed ...$args Values.
			 */
			public function prepare( string $query, ...$args ): string {
				return (string) json_encode( [ $query, $args ] );
			}

			/** @return array<int, string> */
			public function get_col( string $sql ): array {
				[ , $args ] = json_decode( $sql, true );
				return $this->test->page( (int) $args[0], (int) $args[1] );
			}
		};
		Functions\when( 'get_site' )->alias( fn( int $id ) => $this->siteRow( $id ) );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $id ): bool {
				$this->current = $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->current = 1;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				$blog = $this->blogs[ $this->current ] ?? null;
				return match ( $name ) {
					'active_plugins' => $blog['plugins'] ?? [],
					'template'       => $blog['template'] ?? '',
					'stylesheet'     => $blog['stylesheet'] ?? '',
					default          => $fallback,
				};
			}
		);
		Functions\when( 'wp_upload_dir' )->alias(
			fn() => [
				'basedir' => '/w/wp-content/uploads' . ( 1 === $this->current ? '' : '/sites/' . $this->current ),
				'baseurl' => 'http://n.test/wp-content/uploads',
			]
		);
		Functions\when( 'get_site_url' )->alias( static fn( int $id ): string => 'http://n.test' . ( 1 === $id ? '' : '/b' . $id ) );
		Functions\when( 'get_home_url' )->alias( static fn( int $id ): string => 'http://n.test' . ( 1 === $id ? '' : '/b' . $id ) );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $v ): string => rtrim( $v, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( string $v ): string => rtrim( $v, '/\\' ) . '/' );
		Functions\when( 'get_site_option' )->justReturn(
			[
				'net/net.php' => 1,
				self::OWN     => 1,
			]
		);
		Functions\when( 'get_super_admins' )->justReturn( [ 'admin' ] );
		Functions\when( 'wp_cache_supports' )->alias( static fn( string $feature ): bool => 'flush_runtime' === $feature );
		Functions\when( 'wp_cache_flush_runtime' )->alias(
			function (): bool {
				$this->calls[] = 'flush';
				return true;
			}
		);
		Functions\when( 'get_networks' )->justReturn(
			[
				(object) [
					'id'     => 1,
					'domain' => 'n.test',
					'path'   => '/',
				],
			]
		);
	}

	/**
	 * The fake wp_blogs page (called by the fake $wpdb).
	 *
	 * @return array<int, string>
	 */
	public function page( int $after, int $limit ): array {
		$this->pages[] = [ $after, $limit ];
		$this->calls[] = 'page';
		$ids           = array_values( array_filter( array_keys( $this->blogs ), static fn( int $id ): bool => $id > $after ) );
		sort( $ids );

		return array_map( 'strval', array_slice( $ids, 0, $limit ) );
	}
}
