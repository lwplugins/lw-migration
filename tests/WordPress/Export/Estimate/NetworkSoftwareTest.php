<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Export\Estimate;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Export\Estimate\DatabaseSizes;
use LightweightPlugins\Migration\Export\Estimate\EstimateParts;
use LightweightPlugins\Migration\Export\Estimate\EstimateScan;
use LightweightPlugins\Migration\Export\Estimate\FileSizes;
use LightweightPlugins\Migration\Export\Estimate\NetworkSoftware;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Support\NoDeadline;
use LightweightPlugins\Migration\Tests\Support\CountdownDeadline;
use LightweightPlugins\Migration\Tests\Support\FakeConnection;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkSoftwareTest extends MonkeyTestCase {

	private const OWN = 'lw-migration/lw-migration.php';

	/** @var array<int, array{plugins: array<int, string>, template: string, stylesheet: string}> Blogs by id. */
	private array $blogs = [];

	private int $current = 1;

	protected function setUp(): void {
		parent::setUp();
		$this->fakeNetwork();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_collects_what_every_site_uses_and_the_network_active_plugins(): void {
		for ( $id = 1; $id <= 60; ++$id ) {
			$this->blogs[ $id ] = [
				'plugins'    => [ self::OWN, 0 === $id % 2 ? 'even/even.php' : 'odd/odd.php' ],
				'template'   => 'parent',
				'stylesheet' => 60 === $id ? 'child' : 'parent',
			];
		}
		$software = new NetworkSoftware( new NetworkReader(), self::OWN );

		$state = $software->collect( $software->start(), new CountdownDeadline( 0 ) );
		$this->assertFalse( $state['done'], 'One slice of sites per deadline check.' );
		$this->assertSame( 50, $state['after'] );
		$state = $software->collect( $state, new NoDeadline() );

		$this->assertTrue( $state['done'] );
		$this->assertSame( [ 'net/net.php', 'odd/odd.php', 'even/even.php' ], $state['plugins'], 'This plugin is never counted.' );
		$this->assertSame( [ 'parent', 'child' ], $state['themes'] );
	}

	public function test_a_site_deleted_meanwhile_is_skipped(): void {
		$this->blogs = [
			1 => [ 'plugins' => [], 'template' => 'a', 'stylesheet' => 'a' ],
			2 => [ 'plugins' => [ 'gone/gone.php' ], 'template' => 'b', 'stylesheet' => 'b' ],
		];
		Functions\when( 'get_site' )->alias( fn( int $id ) => 2 === $id ? null : $this->siteRow( $id ) );
		$software = new NetworkSoftware( new NetworkReader(), self::OWN );

		$state = $software->collect( $software->start(), new NoDeadline() );

		$this->assertSame( [ 'net/net.php' ], $state['plugins'] );
		$this->assertSame( [ 'a' ], $state['themes'] );
	}

	public function test_a_network_measuring_reads_the_sites_first_and_counts_every_sites_tables(): void {
		$this->blogs = [
			1 => [ 'plugins' => [ 'sample/sample.php' ], 'template' => 'a', 'stylesheet' => 'a' ],
			2 => [ 'plugins' => [], 'template' => 'b', 'stylesheet' => 'b' ],
		];
		$db          = new FakeConnection();
		$db->rows    = [
			'SHOW FULL TABLES'                         => [ [ 'Tables_in_fake' => 'wp_posts' ], [ 'Tables_in_fake' => 'wp_2_posts' ] ],
			'SELECT TABLE_NAME AS n'                   => [],
			'SELECT COUNT(*) AS n FROM `wp_posts`'     => [ [ 'n' => '5' ] ],
			'SELECT COUNT(*) AS n FROM `wp_2_posts`'   => [ [ 'n' => '6' ] ],
		];
		$dir         = TempDir::create();
		$scan        = new EstimateScan( new FileSizes( $dir . '/work', $dir . '/storage' ), static fn(): DatabaseSizes => new DatabaseSizes( $db ), new NetworkSoftware( new NetworkReader(), self::OWN ) );

		try {
			$state = $scan->start( SiteFixture::make( $dir ), 1 );
			$this->assertSame( 'sites', $state['phase'] );
			$state = $scan->run( $state, new NoDeadline(), 2, '0123456789abcdef' );
			$this->assertSame( 'files', $state['phase'], 'The measured database ends its slice.' );
			$state = $scan->run( $state, new NoDeadline(), 2, '0123456789abcdef' );
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertSame( EstimateScan::DONE, $state['phase'] );
		$this->assertSame( [ 'net/net.php', 'sample/sample.php' ], $state['network']['plugins'] );
		$this->assertSame( 11, $state['database']['revisions'] );
		$this->assertSame( 2, $state['touchedAt'] );
	}

	public function test_on_a_network_active_means_used_by_any_site(): void {
		$site  = SiteFixture::make(
			'/n',
			[
				'plugins'       => [ self::OWN, 'sample/sample.php', 'inactive/inactive.php', 'hello.php' ],
				'activePlugins' => [ self::OWN ],
			]
		);
		$parts = EstimateParts::of(
			[
				'phase'    => 'done',
				'site'     => $site->toArray(),
				'network'  => [ 'after' => 9, 'plugins' => [ 'hello.php' ], 'themes' => [ 'old-theme' ], 'done' => true ],
				'database' => null,
				'tally'    => [
					'plugins' => [
						'sample'    => 100,
						'inactive'  => 20,
						'hello.php' => 3,
					],
					'themes'  => [
						'twentytwentyfive' => 50,
						'old-theme'        => 7,
					],
				],
			]
		);

		$this->assertSame( [ 'bytes' => 123, 'count' => 3, 'activeBytes' => 3, 'activeCount' => 1 ], $parts['plugins'], 'The main site\'s own list does not decide on a network.' );
		$this->assertSame( [ 'bytes' => 57, 'count' => 2, 'activeBytes' => 7, 'activeCount' => 1 ], $parts['themes'] );
	}

	/**
	 * The fake wp_blogs page (called by the fake $wpdb).
	 *
	 * @return array<int, string>
	 */
	public function page( int $after, int $limit ): array {
		$ids = array_values( array_filter( array_keys( $this->blogs ), static fn( int $id ): bool => $id > $after ) );
		sort( $ids );

		return array_map( 'strval', array_slice( $ids, 0, $limit ) );
	}

	private function siteRow( int $id ): ?object {
		return isset( $this->blogs[ $id ] ) ? (object) [
			'blog_id' => (string) $id,
			'domain'  => 'n.test',
			'path'    => '/',
			'site_id' => '1',
			'lang_id' => '0',
		] : null;
	}

	private function fakeNetwork(): void {
		$GLOBALS['wpdb'] = new class( $this ) {
			public string $blogs = 'wp_blogs';

			public function __construct( private NetworkSoftwareTest $test ) {
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
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => '/n/wp-content/uploads',
				'baseurl' => 'http://n.test/wp-content/uploads',
			]
		);
		Functions\when( 'get_site_url' )->justReturn( 'http://n.test' );
		Functions\when( 'get_home_url' )->justReturn( 'http://n.test' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $v ): string => rtrim( $v, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( string $v ): string => rtrim( $v, '/\\' ) . '/' );
		Functions\when( 'get_site_option' )->justReturn(
			[
				'net/net.php' => 1,
				self::OWN     => 1,
			]
		);
		Functions\when( 'wp_cache_supports' )->justReturn( false );
	}
}
