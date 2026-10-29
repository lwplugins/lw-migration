<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Multisite;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Multisite\NetworkReader;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class NetworkReaderTest extends MonkeyTestCase {

	private const OWN = 'lw-migration/lw-migration.php';

	/** @var object{blogs: string, queries: array<int, array{0: string, 1: array<int, mixed>}>, column: array<int, string>, var: string} */
	private object $db;

	/** @var array<int, string> */
	private array $log = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new class() {
			public string $blogs = 'wp_blogs';
			/** @var array<int, array{0: string, 1: array<int, mixed>}> */
			public array $queries = [];
			/** @var array<int, string> */
			public array $column = [];
			public string $var   = '0';

			/**
			 * @param mixed ...$args Placeholder values.
			 */
			public function prepare( string $query, ...$args ): string {
				return (string) json_encode( [ $query, $args ] );
			}

			/** @return array<int, string> */
			public function get_col( string $sql ): array {
				$this->queries[] = $this->decode( $sql );
				return $this->column;
			}

			public function get_var( string $sql ): string {
				$this->queries[] = $this->decode( $sql );
				return $this->var;
			}

			/** @return array{0: string, 1: array<int, mixed>} */
			private function decode( string $sql ): array {
				$decoded = json_decode( $sql, true );
				return is_array( $decoded ) ? $decoded : [ $sql, [] ];
			}
		};
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_ids_page_through_the_blogs_table(): void {
		$this->db->column = [ '2', '3' ];

		$this->assertSame( [ 2, 3 ], ( new NetworkReader() )->ids( 1, 50 ) );
		$this->assertSame(
			[ [ 'SELECT blog_id FROM wp_blogs WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d', [ 1, 50 ] ] ],
			$this->db->queries
		);
	}

	public function test_the_count_reads_the_blogs_table(): void {
		$this->db->var = '4';

		$this->assertSame( 4, ( new NetworkReader() )->count() );
		$this->assertSame( [ [ 'SELECT COUNT(*) FROM wp_blogs', [] ] ], $this->db->queries );
	}

	public function test_existing_keeps_only_known_ids_of_the_main_network_in_order(): void {
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$this->db->column = [ '9', '2' ];
		$reader           = new NetworkReader();

		$this->assertSame( [ 2, 9 ], $reader->existing( [ 5, 2, 9, 2, 0, -3 ] ) );
		$this->assertSame(
			[ [ 'SELECT blog_id FROM wp_blogs WHERE blog_id IN (%d, %d, %d) AND site_id = %d', [ 5, 2, 9, 1 ] ] ],
			$this->db->queries
		);
		$this->assertSame( [], $reader->existing( [ 0, -1 ] ), 'No whole positive id: no query.' );
		$this->assertCount( 1, $this->db->queries );
	}

	public function test_an_entry_is_read_inside_switch_to_blog_and_restored(): void {
		$this->stubSite();
		Functions\when( 'wp_upload_dir' )->alias(
			function ( $time, $create ) {
				$this->log[] = 'wp_upload_dir:' . var_export( $create, true );
				return [
					'basedir' => '/w/wp-content/uploads/sites/2/',
					'baseurl' => 'http://n.test/b2/wp-content/uploads/sites/2',
				];
			}
		);

		$entry = ( new NetworkReader() )->entry( 2, self::OWN );

		$this->assertSame( 'switch:2', $this->log[0] );
		$this->assertSame( 'restore', end( $this->log ) );
		$this->assertContains( 'wp_upload_dir:false', $this->log );
		$this->assertSame( [ 2, 1, 7 ], [ $entry->blogId, $entry->siteId, $entry->langId ] );
		$this->assertSame( [ 'n.test', '/b2/' ], [ $entry->domain, $entry->path ] );
		$this->assertSame( [ 'http://n.test/b2/wp', 'http://n.test/b2' ], [ $entry->siteUrl, $entry->homeUrl ] );
		$this->assertSame( [ 'a/a.php' ], $entry->plugins );
		$this->assertSame( [ 'parent', 'child' ], [ $entry->template, $entry->stylesheet ] );
		$this->assertFalse( $entry->uploads, 'A missing upload_path stays false.' );
		$this->assertSame( '/up', $entry->uploadsUrl );
		$this->assertSame( '/w/wp-content/uploads/sites/2', $entry->wpUploads );
		$this->assertSame( 'http://n.test/b2/wp-content/uploads/sites/2/', $entry->wpUploadsUrl );
	}

	public function test_the_blog_is_restored_when_reading_fails(): void {
		$this->stubSite();
		Functions\when( 'wp_upload_dir' )->alias(
			static function () {
				throw new \RuntimeException( 'uploads' );
			}
		);

		try {
			( new NetworkReader() )->entry( 2, self::OWN );
			$this->fail( 'The failure must reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'uploads', $e->getMessage() );
		}
		$this->assertSame( [ 'switch:2', 'restore' ], [ $this->log[0], end( $this->log ) ] );
		$this->assertCount( 1, array_keys( $this->log, 'restore', true ) );
	}

	public function test_an_unknown_site_is_refused_without_switching(): void {
		Functions\when( 'get_site' )->justReturn( null );
		Functions\expect( 'switch_to_blog' )->never();

		$this->expectException( \RuntimeException::class );
		( new NetworkReader() )->entry( 99, self::OWN );
	}

	public function test_sitewide_plugins_are_the_keys_without_this_plugin(): void {
		Functions\expect( 'get_site_option' )->once()->with( 'active_sitewide_plugins', [] )->andReturn(
			[
				'a/a.php'  => 1700000000,
				self::OWN  => 1700000001,
				'b/b.php'  => 1700000002,
			]
		);

		$this->assertSame( [ 'a/a.php', 'b/b.php' ], ( new NetworkReader() )->sitewidePlugins( self::OWN ) );
	}

	public function test_super_admins_and_networks(): void {
		Functions\when( 'get_super_admins' )->justReturn( [ 3 => 'admin', 5 => 'boss' ] );
		Functions\expect( 'get_networks' )->once()->with(
			[
				'number'  => 0,
				'orderby' => 'id',
				'order'   => 'ASC',
			]
		)->andReturn(
			[
				(object) [
					'id'     => '1',
					'domain' => 'n.test',
					'path'   => '/',
				],
			]
		);
		$reader = new NetworkReader();

		$this->assertSame( [ 'admin', 'boss' ], $reader->superAdmins() );
		$this->assertSame(
			[
				[
					'SiteID' => 1,
					'Domain' => 'n.test',
					'Path'   => '/',
				],
			],
			$reader->networks()
		);
	}

	public function test_a_page_of_sites_of_the_main_network_for_the_picker(): void {
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$calls = [];
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ) use ( &$calls ) {
				$calls[] = $args;
				if ( ! empty( $args['count'] ) ) {
					return 12;
				}
				return [
					(object) [
						'blog_id'  => '11',
						'blogname' => 'Shop',
					],
				];
			}
		);
		Functions\when( 'get_home_url' )->alias( static fn( $id ) => 'http://n.test/b' . $id );

		$page = ( new NetworkReader() )->page( 3, 5, 'sh' );

		$this->assertSame(
			[
				'items' => [
					[
						'id'    => 11,
						'url'   => 'http://n.test/b11',
						'title' => 'Shop',
					],
				],
				'total' => 12,
			],
			$page
		);
		$this->assertSame(
			[
				[
					'network_id' => 1,
					'number'     => 5,
					'offset'     => 10,
					'search'     => 'sh',
					'orderby'    => 'id',
					'order'      => 'ASC',
				],
				[
					'network_id' => 1,
					'search'     => 'sh',
					'count'      => true,
				],
			],
			$calls
		);
	}

	public function test_the_runtime_cache_is_flushed_only_where_the_cache_supports_it(): void {
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\expect( 'wp_cache_flush_runtime' )->once()->andReturn( true );
		( new NetworkReader() )->releaseMemory();
	}

	public function test_a_cache_without_runtime_flush_is_left_alone(): void {
		Functions\expect( 'wp_cache_supports' )->once()->with( 'flush_runtime' )->andReturn( false );
		Functions\expect( 'wp_cache_flush_runtime' )->never();
		( new NetworkReader() )->releaseMemory();
		$this->addToAssertionCount( 1 );
	}

	private function stubSite(): void {
		FakeOptions::install(
			[
				'active_plugins'  => [ 'a/a.php', self::OWN ],
				'template'        => 'parent',
				'stylesheet'      => 'child',
				'upload_url_path' => '/up',
			]
		);
		Functions\when( 'get_site' )->justReturn(
			(object) [
				'blog_id' => '2',
				'domain'  => 'n.test',
				'path'    => '/b2/',
				'site_id' => '1',
				'lang_id' => '7',
			]
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->log[] = 'switch:' . $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () {
				$this->log[] = 'restore';
				return true;
			}
		);
		Functions\when( 'get_site_url' )->alias(
			function ( $id ) {
				$this->log[] = 'get_site_url';
				return 'http://n.test/b' . $id . '/wp';
			}
		);
		Functions\when( 'get_home_url' )->alias( static fn( $id ) => 'http://n.test/b' . $id );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );

	}
}
