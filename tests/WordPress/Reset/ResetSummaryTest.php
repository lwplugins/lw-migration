<?php
/**
 * Tests for ResetSummary.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Reset;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Reset\DefaultTheme;
use LightweightPlugins\Migration\Reset\Preflight;
use LightweightPlugins\Migration\Reset\ResetSummary;
use LightweightPlugins\Migration\Reset\RootGuard;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\Site;

final class ResetSummaryTest extends MonkeyTestCase {

	private string $root;

	private bool $createdContentDir = false;

	private bool $multisite = false;

	/** @var array<string, array<string, string>> Plugin basename => header. */
	private array $plugins = [];

	/** Whether the tables closure ran. */
	private bool $planned = false;

	protected function setUp(): void {
		parent::setUp();
		FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
			$this->createdContentDir = true;
		}
		$this->root = TempDir::create();
		foreach ( [ 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/themes', 'wp-content/uploads' ] as $dir ) {
			mkdir( $this->root . '/' . $dir, 0755, true );
		}
		file_put_contents( $this->root . '/wp-content/object-cache.php', '<?php' );
		$this->plugins = [
			'lw-migration/lw-migration.php' => [ 'Name' => 'LW Migration' ],
			'sample/sample.php'             => [ 'Name' => 'Sample' ],
			'hello.php'                     => [ 'Name' => 'Hello' ],
		];
		Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
		Functions\when( 'get_plugins' )->alias( fn() => $this->plugins );
		Functions\when( '_get_dropins' )->justReturn(
			[
				'advanced-cache.php' => [ 'Advanced caching plugin.', 'WP_CACHE' ],
				'object-cache.php'   => [ 'External object cache.', true ],
			]
		);
		Functions\when( 'get_mu_plugins' )->justReturn( [] );
		Functions\when( 'wp_get_themes' )->justReturn(
			[
				'twentytwentyfive' => self::theme( 'twentytwentyfive', 'Twenty Twenty-Five', $this->root . '/wp-content/themes' ),
				'old-theme'        => self::theme( 'old-theme', 'Old theme', $this->root . '/wp-content/themes' ),
			]
		);
		Functions\when( 'wp_get_theme' )->alias( fn( string $slug ) => self::theme( $slug, 'twentytwentyfive' === $slug ? 'Twenty Twenty-Five' : $slug, $this->root . '/wp-content/themes' ) );
		Functions\when( 'wp_count_attachments' )->justReturn(
			(object) [
				'image/png'  => 3,
				'image/jpeg' => 2,
				'trash'      => 1,
			]
		);
		Functions\when( 'get_user_count' )->justReturn( 5 );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => WP_CONTENT_DIR . '/uploads' ] );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'site_url' )->justReturn( 'https://example.com' );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->root );
		if ( $this->createdContentDir ) {
			TempDir::remove( WP_CONTENT_DIR );
		} else {
			TempDir::remove( WP_CONTENT_DIR . '/lw-migration-0123456789ab' );
		}
		parent::tearDown();
	}

	private static function theme( string $slug, string $name, string $root ): object {
		return new class( $slug, $name, $root ) {
			public function __construct( private string $slug, private string $name, private string $root ) {
			}

			public function get_template(): string {
				return $this->slug;
			}

			public function get_stylesheet(): string {
				return $this->slug;
			}

			public function get_theme_root(): string {
				return $this->root;
			}

			public function get( string $key ): string {
				return 'Name' === $key ? $this->name : '';
			}

			public function exists(): bool {
				return true;
			}

			public function errors(): bool {
				return false;
			}
		};
	}

	private function user(): \WP_User {
		$user             = \Mockery::mock( \WP_User::class );
		$user->ID         = 7;
		$user->user_login = 'anna';

		return $user;
	}

	/**
	 * @param array<string, mixed>                   $site Site fields.
	 * @param array{core?: array, retire?: array, kept?: array, views?: array} $plan What the tables closure answers.
	 * @return array<string, mixed>
	 */
	private function summary( array $site = [], array $plan = [] ): array {
		$installed = static fn( string $slug ): bool => 'twentytwentyfive' === $slug;

		return ResetSummary::of(
			new Services(),
			SiteFixture::make( $this->root, $site + [ 'homeUrl' => 'https://Example.com/' ] ),
			$this->user(),
			function ( Site $site ) use ( $plan ): array {
				$this->planned = true;
				return $plan + [
					'core'   => [ 'posts', 'options' ],
					'retire' => [],
					'kept'   => [],
					'views'  => [],
				];
			},
			fn( Site $site ): Preflight => new Preflight( $site, $this->multisite, static fn(): bool => true, static fn( string $dir ): bool => true, static fn(): ?string => null, new RootGuard( 33 ) ),
			new DefaultTheme( $installed, static fn(): ?string => null, static fn(): bool => false, static fn(): string => 'lw-migration-blank', true, true )
		);
	}

	public function test_the_phrase_is_host_and_path(): void {
		$this->assertSame( 'example.com', ResetSummary::phrase( 'https://Example.com' ) );
		$this->assertSame( 'example.com/blog', ResetSummary::phrase( 'https://example.com/blog/' ) );
		$this->assertSame( 'localhost:8080', ResetSummary::phrase( 'http://localhost:8080/' ) );
	}

	public function test_no_server_path_is_ever_shown(): void {
		$summary = $this->summary();
		$this->assertStringNotContainsString( $this->root, (string) json_encode( $summary ) );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, (string) json_encode( $summary ) );
		$this->assertSame( '[uploads]', $summary['types']['media']['folder'] );
		$this->assertSame( [ 'object-cache.php' ], $summary['dropIns'] );
		$this->assertSame( 2, $summary['types']['plugins']['count'] );
		$this->assertSame( [ 'Hello', 'Sample' ], $summary['types']['plugins']['names'], 'By their names, as the plugin list shows them.' );
		$this->assertSame( 'example.com', $summary['phrase'] );
		$this->assertTrue( $summary['backupReady'] );
		$this->assertFalse( $summary['network'] );
		$this->assertSame( 0, $summary['muPlugins'] );
	}

	public function test_every_part_says_what_goes_and_what_stays(): void {
		$summary = $this->summary();
		$this->assertSame(
			[
				'available'  => true,
				'reason'     => null,
				'reasonText' => '',
				'target'     => [
					'slug'   => 'twentytwentyfive',
					'name'   => 'Twenty Twenty-Five',
					'source' => 'installed',
				],
				'count'      => 1,
				'names'      => [ 'old-theme' ],
				'more'       => 0,
			],
			$summary['types']['themes']
		);
		$this->assertSame( 6, $summary['types']['media']['attachments'] );
		$database = $summary['types']['database'];
		$this->assertSame( [ 'anna', 4, 0, 0, false ], [ $database['user'], $database['otherUsers'], $database['connections'], $database['schedules'], $database['prefixWarning'] ] );
		$this->assertSame( [ 'available' => true, 'reason' => null, 'reasonText' => '' ], $summary['types']['full'] );
		$this->assertSame( [], $summary['keptPlugins'] );
	}

	public function test_the_tables_that_go_are_named(): void {
		$retire = array_map( static fn( int $i ): string => sprintf( 'wp_plugin_%02d', $i ), range( 1, 12 ) );
		$tables = $this->summary( [], [ 'retire' => $retire, 'kept' => [ 'wp_shop_options', 'wp_shop_posts' ] ] )['types']['database']['tables'];
		$this->assertSame(
			[
				'replaced'     => 2,
				'retired'      => 12,
				'kept'         => 2,
				'retiredNames' => array_slice( $retire, 0, 10 ),
				'more'         => 2,
			],
			$tables
		);
	}

	public function test_a_prefix_without_underscore_warns(): void {
		$this->assertTrue( $this->summary( [ 'tablePrefix' => 'wp' ] )['types']['database']['prefixWarning'] );
		$this->assertFalse( $this->summary( [ 'tablePrefix' => 'wp_' ] )['types']['database']['prefixWarning'] );
	}

	public function test_a_plugin_a_loader_requires_stays_and_is_not_counted(): void {
		mkdir( $this->root . '/wp-content/plugins/sample', 0755 );
		file_put_contents( $this->root . '/wp-content/mu-plugins/loader.php', "<?php require WP_CONTENT_DIR . '/plugins/sample/sample.php';" );
		$summary = $this->summary();
		$this->assertSame(
			[
				[
					'plugin' => 'sample/sample.php',
					'by'     => 'mu-plugins/loader.php',
				],
			],
			$summary['keptPlugins']
		);
		$this->assertSame( [ 1, [ 'Hello' ] ], [ $summary['types']['plugins']['count'], $summary['types']['plugins']['names'] ] );
	}

	public function test_only_the_first_ten_plugins_are_named(): void {
		foreach ( range( 1, 13 ) as $i ) {
			$this->plugins[ sprintf( 'p%02d/p%02d.php', $i, $i ) ] = [ 'Name' => 'P' . $i ];
		}
		$plugins = $this->summary()['types']['plugins'];
		$this->assertSame( [ 15, 10, 5 ], [ $plugins['count'], count( $plugins['names'] ), $plugins['more'] ] );
	}

	public function test_a_network_offers_files_and_media_only_and_reads_no_tables(): void {
		$this->multisite = true;
		$summary         = $this->summary();
		$this->assertTrue( $summary['network'] );
		$this->assertNull( $summary['types']['media']['attachments'] );
		$this->assertTrue( $summary['types']['plugins']['available'] );
		foreach ( [ 'database', 'full' ] as $type ) {
			$this->assertSame( [ false, 'network' ], [ $summary['types'][ $type ]['available'], $summary['types'][ $type ]['reason'] ], $type );
			$this->assertSame( 'On a network only plugins, themes and media can be reset in this version.', $summary['types'][ $type ]['reasonText'] );
		}
		$this->assertFalse( $this->planned, 'A network database is never planned.' );
		$this->assertSame( 0, $summary['types']['database']['tables']['retired'] );
	}

	public function test_full_is_available_only_when_every_part_is(): void {
		$summary = ResetSummary::of(
			new Services(),
			SiteFixture::make( $this->root ),
			$this->user(),
			static fn(): array => [ 'core' => [], 'retire' => [], 'kept' => [], 'views' => [] ],
			static fn( Site $site ): Preflight => new Preflight( $site, false, static fn(): bool => false, static fn(): bool => true, null, new RootGuard( 33 ) ),
			new DefaultTheme( static fn(): bool => true, static fn(): ?string => null, static fn(): bool => false, static fn(): string => 'x', false, true )
		);
		$this->assertSame( [ false, 'file_mods' ], [ $summary['types']['full']['available'], $summary['types']['full']['reason'] ] );
		$this->assertTrue( $summary['types']['media']['available'] );
		$this->assertTrue( $summary['types']['database']['available'] );
	}
}
