<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\PostImport;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ImportException;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\PostImport\Fixes;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class FixesTest extends MonkeyTestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_rewrite'], $GLOBALS['is_apache'] );
		parent::tearDown();
	}

	private static function job( string $home = 'http://new.test' ): Job {
		$job                = new Job( '0123456789abcdef', 'import', [] );
		$job->state['site'] = SiteFixture::make( '/s', [ 'homeUrl' => $home ] )->toArray();
		return $job;
	}

	/**
	 * A $wpdb whose sitemeta table holds $accounts as fs_accounts (null: no sitemeta table).
	 *
	 * @param string|null $accounts Serialized fs_accounts value.
	 */
	private static function wpdb( ?string $accounts ): void {
		$GLOBALS['wpdb'] = new class( $accounts ) {
			public string $base_prefix = 'wp_';
			public function __construct( private ?string $accounts ) {
			}
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}
			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
			public function get_var( string $sql ) {
				if ( str_starts_with( $sql, 'SHOW TABLES' ) ) {
					return null === $this->accounts ? null : 'wp_sitemeta';
				}
				return $this->accounts;
			}
		};
	}

	public function test_caches_elementor_jetpack_woocommerce_and_db_version(): void {
		FakeOptions::install(
			[
				'_elementor_global_css'          => 'x',
				'jetpack_active_modules'         => [ 'photon', 'stats', 'sso' ],
				'woocommerce_force_ssl_checkout' => 'yes',
				'db_version'                     => '',
				'initial_db_version'             => '58975',
			]
		);
		Functions\expect( 'wp_cache_flush' )->once();
		Functions\expect( 'wp_cache_flush_runtime' )->once();
		Functions\expect( 'delete_post_meta_by_key' )->times( 3 );
		$fixes = new Fixes();
		$job   = self::job();
		foreach ( [ 'flushCaches', 'elementor', 'jetpack', 'woocommerce', 'dbVersion' ] as $task ) {
			$fixes->run( $task, $job );
		}

		$this->assertArrayNotHasKey( '_elementor_global_css', FakeOptions::$values );
		$this->assertSame( [ 'stats' ], FakeOptions::$values['jetpack_active_modules'] );
		$this->assertSame( 'no', FakeOptions::$values['woocommerce_force_ssl_checkout'] );
		$this->assertSame( '58975', FakeOptions::$values['db_version'] );
		$warnings = implode( "\n", $job->warnings );
		$this->assertStringContainsString( 'photon, sso', $warnings, 'Switched-off modules are named.' );
		$this->assertStringContainsString( 'secure checkout', $warnings, 'The checkout setting change is reported.' );
		$this->assertCount( 2, $job->warnings, 'Cache clean-ups and the db_version repair are not reported.' );
	}

	public function test_unchanged_settings_produce_no_warning(): void {
		FakeOptions::install(
			[
				'jetpack_active_modules'         => [ 'stats' ],
				'woocommerce_force_ssl_checkout' => 'no',
				'db_version'                     => '58975',
			]
		);
		$job = self::job();
		foreach ( [ 'jetpack', 'woocommerce', 'dbVersion' ] as $task ) {
			( new Fixes() )->run( $task, $job );
		}

		$this->assertSame( [], $job->warnings );
		$this->assertSame( [ 'stats' ], FakeOptions::$values['jetpack_active_modules'] );
	}

	public function test_builder_cache_folders_are_emptied(): void {
		$root = TempDir::create();
		mkdir( $root . '/wp-content/uploads/elementor/css', 0755, true );
		mkdir( $root . '/wp-content/uploads/bb-plugin/cache', 0755, true );
		file_put_contents( $root . '/wp-content/uploads/elementor/css/post-1.css', 'old' );
		file_put_contents( $root . '/wp-content/uploads/bb-plugin/cache/1-layout.css', 'old' );
		file_put_contents( $root . '/wp-content/uploads/keep.jpg', 'k' );
		$job                = new Job( '0123456789abcdef', 'import', [] );
		$job->state['site'] = SiteFixture::make( $root )->toArray();

		( new Fixes() )->run( 'builderCaches', $job );

		$this->assertDirectoryDoesNotExist( $root . '/wp-content/uploads/elementor/css' );
		$this->assertDirectoryDoesNotExist( $root . '/wp-content/uploads/bb-plugin/cache' );
		$this->assertFileExists( $root . '/wp-content/uploads/keep.jpg' );
		TempDir::remove( $root );
	}

	public function test_https_target_keeps_forced_ssl_checkout(): void {
		FakeOptions::install( [ 'woocommerce_force_ssl_checkout' => 'yes' ] );
		$job = self::job( 'https://new.test' );
		( new Fixes() )->run( 'woocommerce', $job );
		$this->assertSame( 'yes', FakeOptions::$values['woocommerce_force_ssl_checkout'] );
		$this->assertSame( [], $job->warnings );
	}

	public function test_freemius_accounts_are_merged_without_creating_objects(): void {
		FakeOptions::install( [ 'fs_accounts' => [ 'plugins' => [ 'a' ] ] ] );
		Functions\when( 'is_multisite' )->justReturn( false );
		self::wpdb( serialize( [ 'users' => [ 1 ], 'sites' => [ 2 ], 'obj' => new \ArrayObject() ] ) );
		$job = self::job();

		( new Fixes() )->run( 'freemius', $job );

		$merged = FakeOptions::$values['fs_accounts'];
		$this->assertSame( [ 'a' ], $merged['plugins'] );
		$this->assertSame( [ 1 ], $merged['users'] );
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $merged['obj'], 'No class from the archive is instantiated.' );
		$this->assertSame( [], $job->warnings );
	}

	public function test_freemius_options_without_accounts_are_reset_with_a_warning(): void {
		FakeOptions::install(
			[
				'fs_accounts'       => [ 'plugins' => [ 'a' ] ],
				'fs_active_plugins' => 'x',
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		self::wpdb( serialize( [ 'plugins' => [ 'b' ] ] ) );
		$job = self::job();

		( new Fixes() )->run( 'freemius', $job );

		$this->assertArrayNotHasKey( 'fs_accounts', FakeOptions::$values );
		$this->assertArrayNotHasKey( 'fs_active_plugins', FakeOptions::$values );
		$this->assertStringContainsString( 'Freemius', implode( "\n", $job->warnings ) );
	}

	public function test_freemius_is_left_alone_when_the_network_value_cannot_be_read(): void {
		FakeOptions::install(
			[
				'fs_accounts'       => [ 'plugins' => [ 'a' ] ],
				'fs_active_plugins' => 'x',
			]
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		self::wpdb( 'a:1:{broken' );
		$job = self::job();

		( new Fixes() )->run( 'freemius', $job );

		$this->assertSame( [ 'plugins' => [ 'a' ] ], FakeOptions::$values['fs_accounts'] );
		$this->assertSame( 'x', FakeOptions::$values['fs_active_plugins'] );
		$this->assertSame( [], $job->warnings );
	}

	public function test_freemius_is_left_alone_without_a_sitemeta_table(): void {
		FakeOptions::install( [ 'fs_accounts' => [ 'plugins' => [ 'a' ] ] ] );
		Functions\when( 'is_multisite' )->justReturn( false );
		self::wpdb( null );
		$job = self::job();

		( new Fixes() )->run( 'freemius', $job );

		$this->assertSame( [ 'plugins' => [ 'a' ] ], FakeOptions::$values['fs_accounts'] );
		$this->assertSame( [], $job->warnings );
	}

	public function test_rewrite_rules_are_rebuilt_and_only_the_permalink_change_is_reported(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		$GLOBALS['wp_rewrite'] = new class() {
			public string $structure = '/%postname%/';
			public function init(): void {
			}
			public function using_permalinks(): bool {
				return true;
			}
			public function set_permalink_structure( string $s ): void {
				$this->structure = $s;
			}
		};
		$GLOBALS['is_apache']          = true;
		Functions\when( 'apache_mod_loaded' )->justReturn( false );
		$job                           = self::job();
		$job->state['usingPermalinks'] = false;
		$job->state['plugins']         = [
			'active'  => [],
			'removed' => [ 'gone/gone.php' => 'missing' ],
		];

		( new Fixes() )->run( 'rewrite', $job );

		$this->assertArrayNotHasKey( 'rewrite_rules', FakeOptions::$values );
		$this->assertSame( '', $GLOBALS['wp_rewrite']->structure );
		$warnings = implode( "\n", $job->warnings );
		$this->assertStringContainsString( 'mod_rewrite', $warnings );
		$this->assertStringNotContainsString( 'gone/gone.php', $warnings, 'Plugins left inactive are reported by the preserve step only.' );
		$this->assertNotContains( 'report', Fixes::TASKS );
	}

	public function test_permalinks_stay_when_the_site_used_them_before(): void {
		FakeOptions::install( [ 'rewrite_rules' => [ 'x' ] ] );
		$GLOBALS['wp_rewrite'] = new class() {
			public string $structure = '/%postname%/';
			public function init(): void {
			}
			public function using_permalinks(): bool {
				return true;
			}
			public function set_permalink_structure( string $s ): void {
				$this->structure = $s;
			}
		};
		$GLOBALS['is_apache']          = true;
		Functions\when( 'apache_mod_loaded' )->justReturn( false );
		$job                           = self::job();
		$job->state['usingPermalinks'] = true;

		( new Fixes() )->run( 'rewrite', $job );

		$this->assertSame( '/%postname%/', $GLOBALS['wp_rewrite']->structure );
		$this->assertSame( [], $job->warnings );
	}

	public function test_unknown_task_is_refused(): void {
		$this->expectException( ImportException::class );
		( new Fixes() )->run( 'report', self::job() );
	}

	public function test_builder_caches_of_a_blog_are_removed_from_its_own_uploads(): void {
		$root = TempDir::create();
		mkdir( $root . '/wp-content/uploads/elementor/css', 0755, true );
		mkdir( $root . '/wp-content/uploads/sites/7/elementor/css', 0755, true );
		mkdir( $root . '/wp-content/uploads/sites/7/bb-plugin/cache', 0755, true );
		file_put_contents( $root . '/wp-content/uploads/sites/7/2026.jpg', 'k' );
		$job                = new Job( '0123456789abcdef', 'import', [] );
		$job->state['site'] = SiteFixture::make( $root )->toArray();

		( new Fixes() )->runOnBlog( 'builderCaches', $job, 'http://new.test/b7', $root . '/wp-content/uploads/sites/7' );

		$this->assertDirectoryDoesNotExist( $root . '/wp-content/uploads/sites/7/elementor/css' );
		$this->assertDirectoryDoesNotExist( $root . '/wp-content/uploads/sites/7/bb-plugin/cache' );
		$this->assertFileExists( $root . '/wp-content/uploads/sites/7/2026.jpg' );
		$this->assertDirectoryExists( $root . '/wp-content/uploads/elementor/css', 'The main site\'s caches are not this blog\'s.' );
		TempDir::remove( $root );
	}

	public function test_the_woocommerce_fix_uses_the_blog_address(): void {
		FakeOptions::install( [ 'woocommerce_force_ssl_checkout' => 'yes' ] );
		$job = self::job( 'http://new.test' );

		( new Fixes() )->runOnBlog( 'woocommerce', $job, 'https://shop.new.test', '/w/uploads/sites/7' );
		$this->assertSame( 'yes', FakeOptions::$values['woocommerce_force_ssl_checkout'], 'An https blog keeps it, whatever the main site uses.' );

		( new Fixes() )->runOnBlog( 'woocommerce', self::job( 'https://new.test' ), 'http://shop.new.test', '/w/uploads/sites/7' );
		$this->assertSame( 'no', FakeOptions::$values['woocommerce_force_ssl_checkout'] );
	}

	public function test_the_other_blog_fixes_run_as_on_a_single_site(): void {
		FakeOptions::install(
			[
				'_elementor_global_css'  => 'x',
				'jetpack_active_modules' => [ 'photon', 'stats' ],
				'db_version'             => '',
				'initial_db_version'     => '58975',
			]
		);
		Functions\expect( 'delete_post_meta_by_key' )->times( 3 );
		$job = self::job();
		foreach ( [ 'elementor', 'jetpack', 'dbVersion' ] as $task ) {
			( new Fixes() )->runOnBlog( $task, $job, 'http://new.test/b7', '/w/uploads/sites/7' );
		}

		$this->assertArrayNotHasKey( '_elementor_global_css', FakeOptions::$values );
		$this->assertSame( [ 'stats' ], FakeOptions::$values['jetpack_active_modules'] );
		$this->assertSame( '58975', FakeOptions::$values['db_version'] );
	}

	public function test_a_task_that_is_not_per_blog_is_refused_on_a_blog(): void {
		$this->expectException( ImportException::class );
		( new Fixes() )->runOnBlog( 'freemius', self::job(), 'http://new.test/b7', '/w/uploads/sites/7' );
	}
}
