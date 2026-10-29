<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Import;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\ImportOptions;
use LightweightPlugins\Migration\Import\ImportPipeline;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Steps\PullDownloadStep;
use LightweightPlugins\Migration\Link\Steps\RemoteJobStep;
use LightweightPlugins\Migration\Tests\Support\ImportJobFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ImportPipelineTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		Functions\when( 'get_plugins' )->justReturn(
			[
				'akismet/akismet.php' => [ 'Version' => '5.4' ],
				'hello.php'           => [],
			]
		);
		Functions\when( '_get_dropins' )->justReturn( [ 'object-cache.php' => [ 'Object cache' ] ] );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
		parent::tearDown();
	}

	public function test_rules_route_into_this_sites_folders_and_protect_storage_and_dropins(): void {
		$job     = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$content = $this->dir . '/wp-content';

		$rules = ImportPipeline::rules( $job, $this->dir . '/staging', $content . '/lw-migration-0123456789ab' );

		$this->assertSame(
			[
				'content' => $content,
				'plugins' => $content . '/plugins',
				'themes'  => $content . '/themes',
				'uploads' => $content . '/uploads',
				'staging' => $this->dir . '/staging',
			],
			$rules->bases()
		);
		$this->assertSame( ContentRules::EXTRACT, $rules->route( 'uploads/2026/a.jpg' )['action'] );
		$this->assertSame( ContentRules::SKIP, $rules->route( 'object-cache.php' )['action'], 'Drop-ins of this site are never replaced.' );
		$this->assertSame( ContentRules::SKIP, $rules->route( 'lw-migration-0123456789ab/jobs/x' )['action'], 'Our storage is never written.' );
		$this->assertSame( ContentRules::SKIP, $rules->route( 'plugins/lw-migration/lw-migration.php' )['action'], 'This plugin is never replaced.' );
	}

	public function test_on_a_network_a_sites_archive_routes_by_blog_and_keeps_the_networks_code(): void {
		$job                         = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$content                     = $this->dir . '/wp-content';
		$job->state['target']        = [ 'domain' => 'new.test' ];
		$job->state['kind']          = 'sites';
		$job->state['blogs']         = [
			[
				'old'   => [ 'BlogID' => 3 ],
				'newId' => 8,
			],
		];
		$job->state['networkCode']   = [
			'plugins' => [ 'akismet' ],
			'themes'  => [],
		];

		$rules = ImportPipeline::rules( $job, $this->dir . '/staging', $content . '/lw-migration-0123456789ab' );

		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => $content . '/uploads', 'rel' => 'sites/8/a.jpg' ], $rules->route( 'uploads/sites/3/a.jpg' ) );
		$this->assertSame( ContentRules::SKIP, $rules->route( 'uploads/2026/main.jpg' )['action'], 'The network\'s main uploads are not the archive\'s.' );
		$this->assertSame( 'kept:plugins/akismet', $rules->route( 'plugins/akismet/akismet.php' )['reason'] ?? null );
		$this->assertSame( 'shared', $rules->route( 'mu-plugins/x.php' )['reason'] ?? null );
		$this->assertSame( 'new:plugins/shop', $rules->route( 'plugins/shop/shop.php' )['reason'] ?? null, 'Final re-review: a plugin the network does not have is not written.' );
		mkdir( $content . '/languages', 0755, true );
		file_put_contents( $content . '/languages/hu_HU.mo', 'x' );
		$this->assertSame( 'existing', $rules->route( 'languages/hu_HU.mo' )['reason'] ?? null, 'Checked in this site\'s wp-content.' );
	}

	public function test_on_a_network_a_whole_network_archive_writes_its_code_as_before(): void {
		$job                  = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$job->state['target'] = [ 'domain' => 'new.test' ];
		$job->state['kind']   = 'network';
		$job->state['blogs']  = [
			[
				'old'   => [ 'BlogID' => 1 ],
				'newId' => 1,
			],
			[
				'old'   => [ 'BlogID' => 2 ],
				'newId' => 2,
			],
		];

		$rules = ImportPipeline::rules( $job, $this->dir . '/staging', $this->dir . '/wp-content/lw-migration-0123456789ab' );

		$this->assertSame( ContentRules::STAGE, $rules->route( 'mu-plugins/x.php' )['action'] );
		$this->assertSame( 'sites/2/x.jpg', $rules->route( 'blogs.dir/2/files/x.jpg' )['rel'] ?? null );
		$this->assertSame( ContentRules::EXTRACT, $rules->route( 'plugins/akismet/akismet.php' )['action'] );
	}

	public function test_on_a_network_an_unplanned_or_unresolved_map_routes_no_blog(): void {
		$job                  = ImportJobFixture::make( new JobStore( $this->dir . '/jobs' ), $this->dir, '/unused.wpress' );
		$job->state['target'] = [ 'domain' => 'new.test' ];
		$job->state['kind']   = 'sites';
		$rules                = ImportPipeline::rules( $job, $this->dir . '/staging', $this->dir . '/wp-content/lw-migration-0123456789ab' );
		$this->assertSame( $this->dir . '/wp-content/uploads', $rules->bases()['uploads'], 'The cleanups still get the folders.' );

		$job->state['blogs'] = [ [ 'old' => [ 'BlogID' => 3 ] ] ];
		$rules               = ImportPipeline::rules( $job, $this->dir . '/staging', $this->dir . '/wp-content/lw-migration-0123456789ab' );
		$this->assertSame( 'sites/3/a.jpg', $rules->route( 'uploads/sites/3/a.jpg' )['rel'] ?? null, 'Ruling R21: routes only once resolved.' );
	}

	public function test_installed_plugins_are_read_fresh_with_their_versions(): void {
		Functions\expect( 'wp_clean_plugins_cache' )->once()->with( false );

		$this->assertSame(
			[
				'akismet/akismet.php' => '5.4',
				'hello.php'           => '',
			],
			ImportPipeline::installedPlugins()
		);
	}

	public function test_plugin_and_theme_existence_is_read_from_disk_with_safe_names_only(): void {
		$plugins = $this->dir . '/plugins';
		$themes  = $this->dir . '/themes';
		mkdir( $plugins . '/sample', 0755, true );
		file_put_contents( $plugins . '/sample/sample.php', '<?php' );
		mkdir( $themes . '/kept', 0755, true );
		file_put_contents( $themes . '/kept/style.css', '' );

		$this->assertTrue( ImportPipeline::pluginExists( 'sample/sample.php', $plugins ) );
		$this->assertFalse( ImportPipeline::pluginExists( 'missing/missing.php', $plugins ) );
		$this->assertFalse( ImportPipeline::pluginExists( '../plugins/sample/sample.php', $plugins ) );
		$this->assertTrue( ImportPipeline::themeExists( 'kept', $themes ) );
		$this->assertFalse( ImportPipeline::themeExists( 'gone', $themes ) );
		$this->assertFalse( ImportPipeline::themeExists( '../themes/kept', $themes ) );
	}

	public function test_themes_are_looked_up_in_every_registered_root(): void {
		$default = $this->dir . '/themes';
		$extra   = $this->dir . '/more-themes';
		mkdir( $extra . '/elsewhere', 0755, true );
		file_put_contents( $extra . '/elsewhere/style.css', '' );
		Functions\when( 'get_theme_root' )->justReturn( $default );
		$GLOBALS['wp_theme_directories'] = [ $default, $extra ];
		try {
			$this->assertSame( [ $default, $extra ], ImportPipeline::themeRoots() );
			$this->assertTrue( ImportPipeline::themeExistsIn( 'elsewhere', ImportPipeline::themeRoots() ) );
			$this->assertFalse( ImportPipeline::themeExistsIn( 'gone', ImportPipeline::themeRoots() ) );
		} finally {
			unset( $GLOBALS['wp_theme_directories'] );
		}
		$this->assertSame( [ $default ], ImportPipeline::themeRoots() );
	}

	public function test_a_pull_downloads_over_the_connection_before_the_common_steps(): void {
		$this->assertSame( array_merge( [ RemoteJobStep::PULL_KEY, PullDownloadStep::KEY ], ImportPipeline::COMMON ), ImportPipeline::stepsFor( ImportOptions::LINK ) );
		$this->assertSame( [ 'link_export', 'link_download' ], array_slice( ImportPipeline::stepsFor( 'link' ), 0, 2 ) );
	}
}
