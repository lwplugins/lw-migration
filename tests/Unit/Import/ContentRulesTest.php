<?php
/**
 * Tests for ContentRules.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Import;

use LightweightPlugins\Migration\Import\ContentRules;
use LightweightPlugins\Migration\Import\ProtectedTargets;
use LightweightPlugins\Migration\Import\SharedCode;
use LightweightPlugins\Migration\Import\UploadRoutes;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\WordPress\Site;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentRulesTest extends TestCase {

	private const BASES = [
		'content' => '/w/c',
		'plugins' => '/w/c/plugins',
		'themes'  => '/w/c/themes',
		'uploads' => '/w/c/uploads',
		'staging' => '/job/mu',
	];

	private const PROTECTED = [
		'/w/c/mu-plugins'  => 'protected',
		'/w/c/my-backups/' => 'storage',
		'/w/wp-config.php' => 'config',
	];

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public static function routes(): array {
		$skip = static fn( string $reason ): array => [ 'action' => ContentRules::SKIP, 'reason' => $reason ];
		$to   = static fn( string $base, string $rel ): array => [ 'action' => ContentRules::EXTRACT, 'base' => $base, 'rel' => $rel ];
		return [
			'package'                => [ 'package.json', $skip( '' ) ],
			'multisite config'       => [ 'multisite.json', $skip( '' ) ],
			'dump'                   => [ 'database.sql', $skip( '' ) ],
			'drop-in'                => [ 'object-cache.php', $skip( 'dropin' ) ],
			'not a drop-in'          => [ 'sub/object-cache.php', $to( '/w/c', 'sub/object-cache.php' ) ],
			'own mu-plugin'          => [ 'mu-plugins/custom.php', [ 'action' => ContentRules::STAGE, 'base' => '/job/mu', 'rel' => 'custom.php' ] ],
			'own mu-plugin folder'   => [ 'mu-plugins/tools/a.php', [ 'action' => ContentRules::STAGE, 'base' => '/job/mu', 'rel' => 'tools/a.php' ] ],
			'host mu folder'         => [ 'mu-plugins/wpcomsh/x.php', $skip( 'hosting' ) ],
			'host mu file'           => [ 'mu-plugins/gd-system-plugin.php', $skip( 'hosting' ) ],
			'host mu other case'     => [ 'mu-plugins/GD-System-Plugin.php', $skip( 'hosting' ) ],
			'own plugin'             => [ 'plugins/lw-migration/lw-migration.php', $skip( 'own' ) ],
			'own plugin other case'  => [ 'plugins/LW-Migration/lw-migration.php', $skip( 'own' ) ],
			'similar plugin'         => [ 'plugins/lw-migration-extra/x.php', $to( '/w/c/plugins', 'lw-migration-extra/x.php' ) ],
			'plugin'                 => [ 'plugins/akismet/akismet.php', $to( '/w/c/plugins', 'akismet/akismet.php' ) ],
			'themes functions'       => [ 'themes/functions.php', $skip( 'generated' ) ],
			'theme file'             => [ 'themes/t/style.css', $to( '/w/c/themes', 't/style.css' ) ],
			'elementor css'          => [ 'uploads/elementor/css/post-1.css', $skip( 'generated' ) ],
			'elementor similar'      => [ 'uploads/elementor/cssx/a', $to( '/w/c/uploads', 'elementor/cssx/a' ) ],
			'beaver cache'           => [ 'uploads/bb-plugin/cache/layout.css', $skip( 'generated' ) ],
			'civicrm templates'      => [ 'uploads/civicrm/templates_c/x', $skip( 'generated' ) ],
			'less cache'             => [ 'uploads/a.less.cache', $skip( 'generated' ) ],
			'sqlite'                 => [ 'database/.ht.sqlite', $skip( 'generated' ) ],
			'subsite uploads'        => [ 'uploads/sites/2/2026/a.jpg', $to( '/w/c/uploads', '2026/a.jpg' ) ],
			'subsite elementor css'  => [ 'uploads/sites/2/elementor/css/post-1.css', $skip( 'generated' ) ],
			'old subsite files'      => [ 'blogs.dir/2/files/a.jpg', $to( '/w/c/uploads', 'a.jpg' ) ],
			'other subsite'          => [ 'uploads/sites/3/a.jpg', $to( '/w/c/uploads', 'sites/3/a.jpg' ) ],
			'content root'           => [ 'index.php', $to( '/w/c', 'index.php' ) ],
			// Unsafe names are never routed anywhere.
			'absolute'               => [ '/etc/passwd', $skip( 'unsafe' ) ],
			'drive letter'           => [ 'C:/x.php', $skip( 'unsafe' ) ],
			'parent'                 => [ 'uploads/../../wp-config.php', $skip( 'unsafe' ) ],
			'parent after dot'       => [ 'plugins/./../x.php', $skip( 'unsafe' ) ],
			'only parent'            => [ '..', $skip( 'unsafe' ) ],
			'nul'                    => [ "uploads/a.php\0.jpg", $skip( 'unsafe' ) ],
			'backslash'              => [ 'uploads\\..\\x.php', $skip( 'unsafe' ) ],
			'empty'                  => [ '', $skip( 'unsafe' ) ],
			'only separators'        => [ './/.', $skip( 'unsafe' ) ],
			// Separators are normalised before routing.
			'double separator'       => [ 'uploads//2026/a.jpg', $to( '/w/c/uploads', '2026/a.jpg' ) ],
			'leading dot'            => [ './plugins/akismet/a.php', $to( '/w/c/plugins', 'akismet/a.php' ) ],
			'dot own plugin'         => [ 'plugins/./lw-migration/x.php', $skip( 'own' ) ],
			'dot drop-in'            => [ './object-cache.php', $skip( 'dropin' ) ],
			'dot package'            => [ './package.json', $skip( '' ) ],
			// Our storage, legacy and other .wpress backup folders, our temp files.
			'storage folder'         => [ 'lw-migration-0123456789ab/backups/x.wpress', $skip( 'storage' ) ],
			'storage other case'     => [ 'LW-Migration-0123456789AB/jobs/j/job.json', $skip( 'storage' ) ],
			'storage similar'        => [ 'lw-migration-0123456789abc/x', $to( '/w/c', 'lw-migration-0123456789abc/x' ) ],
			'suffixed backups folder' => [ 'lw-migration-backups-0123456789ab/x.wpress', $skip( 'storage' ) ],
			'suffixed backups case'  => [ 'LW-Migration-Backups-0123456789AB/x.wpress', $skip( 'storage' ) ],
			'suffixed backups similar' => [ 'lw-migration-backups-0123456789a/x', $to( '/w/c', 'lw-migration-backups-0123456789a/x' ) ],
			'legacy folder'          => [ 'lw-migration/backups/x.wpress', $skip( 'storage' ) ],
			'backups of other tools' => [ 'ai1wm-backups/site.wpress', $skip( 'storage' ) ],
			'storage name in uploads' => [ 'uploads/lw-migration-0123456789ab/a.jpg', $to( '/w/c/uploads', 'lw-migration-0123456789ab/a.jpg' ) ],
			'temp in uploads'        => [ 'uploads/2026/.lwm-0123456789abcdef.part', $skip( 'storage' ) ],
			'temp in mu-plugins'     => [ 'mu-plugins/.lwm-0123456789abcdef.part', $skip( 'storage' ) ],
			'temp-like name'         => [ 'uploads/.lwm-0123456789abcdef.part.jpg', $to( '/w/c/uploads', '.lwm-0123456789abcdef.part.jpg' ) ],
			// wp-config.php never lands anywhere.
			'wp-config root'         => [ 'wp-config.php', $skip( 'config' ) ],
			'wp-config nested'       => [ 'uploads/wp-config.php', $skip( 'config' ) ],
			'wp-config other case'   => [ 'plugins/x/WP-Config.php', $skip( 'config' ) ],
			'wp-config similar'      => [ 'wp-config-sample.php', $to( '/w/c', 'wp-config-sample.php' ) ],
			// Drop-in names in any case (case-insensitive file systems).
			'drop-in other case'     => [ 'Object-Cache.php', $skip( 'dropin' ) ],
			'drop-in upper case'     => [ 'OBJECT-CACHE.PHP', $skip( 'dropin' ) ],
			'temp upper case'        => [ 'uploads/.LWM-0123456789ABCDEF.PART', $skip( 'storage' ) ],
			// Names Windows would silently shorten or read as a stream.
			'trailing dot'           => [ 'wp-config.php.', $skip( 'unsafe' ) ],
			'trailing space'         => [ 'wp-config.php ', $skip( 'unsafe' ) ],
			'stream'                 => [ 'wp-config.php::$DATA', $skip( 'unsafe' ) ],
			'drive relative'         => [ 'c:x', $skip( 'unsafe' ) ],
			'own plugin dot'         => [ 'plugins/lw-migration./x', $skip( 'unsafe' ) ],
			'own plugin space'       => [ 'plugins/lw-migration /x', $skip( 'unsafe' ) ],
			'storage dot space'      => [ 'lw-migration-abcdef123456. /x', $skip( 'unsafe' ) ],
			'drop-in space'          => [ 'advanced-cache.php ', $skip( 'unsafe' ) ],
			'dot in the middle'      => [ 'uploads/a.b/c.jpg', $to( '/w/c/uploads', 'a.b/c.jpg' ) ],
			// Bare roots and folder markers are never files.
			'bare plugins'           => [ 'plugins', $skip( 'unsafe' ) ],
			'plugins folder'         => [ 'plugins/', $skip( 'unsafe' ) ],
			'bare themes'            => [ 'themes', $skip( 'unsafe' ) ],
			'bare uploads'           => [ 'uploads', $skip( 'unsafe' ) ],
			'bare mu-plugins'        => [ 'mu-plugins', $skip( 'unsafe' ) ],
			'mu-plugins folder'      => [ 'mu-plugins/', $skip( 'unsafe' ) ],
			'subsite uploads folder' => [ 'uploads/sites/2/', $skip( 'unsafe' ) ],
			'old subsite folder'     => [ 'blogs.dir/2/files/', $skip( 'unsafe' ) ],
			'old subsite root'       => [ 'blogs.dir/2/files', $skip( 'unsafe' ) ],
			'own plugin folder'      => [ 'plugins/lw-migration', $skip( 'own' ) ],
			// Protected absolute targets of the site.
			'external backups'       => [ 'my-backups/a.wpress', $skip( 'storage' ) ],
			'external backups case'  => [ 'My-Backups/a.wpress', $skip( 'storage' ) ],
			'external similar'       => [ 'my-backups-old/a.wpress', $to( '/w/c', 'my-backups-old/a.wpress' ) ],
		];
	}

	/** @param array<string, string> $expected */
	#[DataProvider( 'routes' )]
	public function test_route( string $entry, array $expected ): void {
		$this->assertSame( $expected, self::rules( self::BASES, 2 )->route( $entry ) );
	}

	public function test_without_a_subsite_every_uploads_entry_keeps_its_path(): void {
		$rules = self::rules( self::BASES, null );

		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c/uploads', 'rel' => 'sites/2/a.jpg' ], $rules->route( 'uploads/sites/2/a.jpg' ) );
		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c', 'rel' => 'blogs.dir/2/files/a.jpg' ], $rules->route( 'blogs.dir/2/files/a.jpg' ) );
	}

	public function test_a_content_entry_that_reaches_our_plugin_folder_is_skipped(): void {
		// The site keeps its plugins in wp-content/modules: a plain wp-content entry
		// of the archive would land in this plugin's own folder.
		$bases            = self::BASES;
		$bases['plugins'] = '/w/c/modules/';
		$rules            = self::rules( $bases, null );

		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'own' ], $rules->route( 'modules/lw-migration/lw-migration.php' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'own' ], $rules->route( 'modules/lw-migration' ) );
		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c', 'rel' => 'modules/other/x.php' ], $rules->route( 'modules/other/x.php' ) );
		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c/modules/', 'rel' => 'akismet/a.php' ], $rules->route( 'plugins/akismet/a.php' ) );
	}

	public function test_bases_outside_wp_content_are_used_as_given(): void {
		$rules = self::rules(
			[
				'content' => '/srv/app/content',
				'plugins' => '/srv/app/plugins',
				'themes'  => '/srv/shared/themes',
				'uploads' => '/srv/media',
				'staging' => '/job/mu',
			],
			null
		);

		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/srv/media', 'rel' => '2026/a.jpg' ], $rules->route( 'uploads/2026/a.jpg' ) );
		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/srv/shared/themes', 'rel' => 't/style.css' ], $rules->route( 'themes/t/style.css' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'own' ], $rules->route( 'plugins/lw-migration/x.php' ) );
	}

	/** @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string, 3: array<string, string>}> */
	public static function layouts(): array {
		$skip  = static fn( string $reason ): array => [ 'action' => ContentRules::SKIP, 'reason' => $reason ];
		$same  = [ 'content' => '/w/c', 'plugins' => '/w/c/plugins', 'themes' => '/w/c/themes', 'uploads' => '/w/c', 'staging' => '/job/mu' ];
		$above = [ 'content' => '/w/wp-content', 'plugins' => '/w/wp-content/plugins', 'themes' => '/w/wp-content/themes', 'uploads' => '/w', 'staging' => '/job/mu' ];
		$slash = [ 'content' => '/w/c/', 'plugins' => '/w/c/plugins/', 'themes' => '/w/c//themes', 'uploads' => '/w/./c/uploads', 'staging' => '/job/mu/' ];
		$p1    = [ '/w/c/mu-plugins' => 'protected', '/w/wp-config.php' => 'config' ];
		$p2    = [ '/w/wp-content/mu-plugins' => 'protected', '/w/wp-config.php' => 'config' ];
		$p3    = [ '/w/c/mu-plugins/' => 'protected' ];
		return [
			'uploads=content mu-plugins'    => [ $same, $p1, 'uploads/mu-plugins/evil.php', $skip( 'protected' ) ],
			'uploads=content storage'       => [ $same, $p1, 'uploads/lw-migration-abcdef123456/x', $skip( 'storage' ) ],
			'uploads=content legacy'        => [ $same, $p1, 'uploads/LW-Migration/x', $skip( 'storage' ) ],
			'uploads=content other backups' => [ $same, $p1, 'uploads/ai1wm-backups/a', $skip( 'storage' ) ],
			'uploads=content drop-in'       => [ $same, $p1, 'uploads/object-cache.php', $skip( 'dropin' ) ],
			'uploads=content drop-in case'  => [ $same, $p1, 'uploads/ADVANCED-cache.php', $skip( 'dropin' ) ],
			'uploads=content own plugin'    => [ $same, $p1, 'uploads/plugins/lw-migration/x.php', $skip( 'own' ) ],
			'uploads=content plugins root'  => [ $same, $p1, 'uploads/plugins', $skip( 'unsafe' ) ],
			'uploads=content media'         => [ $same, $p1, 'uploads/2026/a.jpg', [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c', 'rel' => '2026/a.jpg' ] ],
			'uploads=ABSPATH wp-config'     => [ $above, $p2, 'uploads/wp-config.php', $skip( 'config' ) ],
			'uploads=ABSPATH mu-plugins'    => [ $above, $p2, 'uploads/wp-content/mu-plugins/evil.php', $skip( 'protected' ) ],
			'uploads=ABSPATH content root'  => [ $above, $p2, 'uploads/wp-content', $skip( 'unsafe' ) ],
			'uploads=ABSPATH own plugin'    => [ $above, $p2, 'uploads/wp-content/plugins/lw-migration/x.php', $skip( 'own' ) ],
			'uploads=ABSPATH storage'       => [ $above, $p2, 'uploads/wp-content/lw-migration-abcdef123456/x', $skip( 'storage' ) ],
			'uploads=ABSPATH drop-in'       => [ $above, $p2, 'uploads/wp-content/object-cache.php', $skip( 'dropin' ) ],
			'uploads=ABSPATH media'         => [ $above, $p2, 'uploads/2026/a.jpg', [ 'action' => ContentRules::EXTRACT, 'base' => '/w', 'rel' => '2026/a.jpg' ] ],
			'slashes own plugin'            => [ $slash, $p3, 'plugins/lw-migration/x', $skip( 'own' ) ],
			'slashes mu-plugins'            => [ $slash, $p3, 'mu-plugins/x.php', [ 'action' => ContentRules::STAGE, 'base' => '/job/mu/', 'rel' => 'x.php' ] ],
			'slashes protected'             => [ $slash, $p3, 'uploads/../x', $skip( 'unsafe' ) ],
			'slashes theme'                 => [ $slash, $p3, 'themes/t/a.css', [ 'action' => ContentRules::EXTRACT, 'base' => '/w/c//themes', 'rel' => 't/a.css' ] ],
			'slashes storage'               => [ $slash, $p3, 'lw-migration-abcdef123456/x', $skip( 'storage' ) ],
		];
	}

	/**
	 * @param array<string, string> $bases     Bases.
	 * @param array<string, string> $protected Protected paths.
	 * @param string                $entry     Entry name.
	 * @param array<string, string> $expected  Route.
	 */
	#[DataProvider( 'layouts' )]
	public function test_nested_bases_never_reach_a_protected_target( array $bases, array $protected, string $entry, array $expected ): void {
		$this->assertSame( $expected, self::rules( $bases, null, $protected )->route( $entry ) );
	}

	public function test_a_site_snapshot_protects_its_folders_through_every_base(): void {
		$site  = Site::fromArray(
			[
				'abspath'      => '/w/',
				'contentDir'   => '/w/wp-content',
				'pluginsDir'   => '/w/wp-content/plugins',
				'muPluginsDir' => '/w/wp-content/mu-plugins',
				'themeRoot'    => '/w/wp-content/themes',
				'uploadsDir'   => '/w',
				'ownPlugin'    => 'lw-migration/lw-migration.php',
			]
		);
		$bases = [ 'content' => '/w/wp-content', 'plugins' => '/w/wp-content/plugins', 'themes' => '/w/wp-content/themes', 'uploads' => '/w', 'staging' => '/job/mu' ];
		$rules = new ContentRules( $bases, [ 'object-cache.php' ], UploadRoutes::forSubsite( null ), ProtectedTargets::forSite( $site, '/w/wp-content/lw-migration-0123456789ab' ) );

		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'protected' ], $rules->route( 'uploads/wp-content/mu-plugins/evil.php' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'own' ], $rules->route( 'uploads/wp-content/plugins/lw-migration/x.php' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'dropin' ], $rules->route( 'uploads/wp-content/object-cache.php' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'config' ], $rules->route( 'uploads/WP-Config.php' ) );
		$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'storage' ], $rules->route( 'uploads/wp-content/ai1wm-backups/a.wpress' ) );
		$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => '/w', 'rel' => '2026/a.jpg' ], $rules->route( 'uploads/2026/a.jpg' ) );
	}

	public function test_a_folder_symlinked_into_a_protected_target_is_skipped(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/site/c/uploads/2026', 0755, true );
			mkdir( $dir . '/site/c/mu-plugins/inner', 0755, true );
			mkdir( $dir . '/site/c/lw-migration-abcdef123456' );
			symlink( $dir . '/site/c/mu-plugins', $dir . '/site/c/uploads/evil' ); // Already on the site, inside a base.
			symlink( $dir . '/site/c', $dir . '/site/c/uploads/up' );
			symlink( $dir . '/site', $dir . '/alias' ); // The site's paths as WordPress reports them (/var vs /private/var).
			$c     = $dir . '/alias/c';
			$bases = [
				'content' => $c,
				'plugins' => $c . '/plugins',
				'themes'  => $c . '/themes',
				'uploads' => $c . '/uploads',
				'staging' => $dir . '/job',
			];
			$rules = new ContentRules( $bases, [], UploadRoutes::forSubsite( null ), new ProtectedTargets( $c, [ $c . '/mu-plugins' => 'protected' ], array_values( $bases ) ) );
			$skip  = [
				'action' => ContentRules::SKIP,
				'reason' => 'protected',
			];

			$this->assertSame( $skip, $rules->route( 'uploads/evil/x.php' ) );
			$this->assertSame( $skip, $rules->route( 'uploads/evil/inner/x.php' ) );
			$this->assertSame( $skip, $rules->route( 'uploads/evil/new/deeper/x.php' ), 'Folders still to be created resolve through the link above them.' );
			$this->assertSame( $skip, $rules->route( 'uploads/up/mu-plugins/x.php' ) );
			$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => 'storage' ], $rules->route( 'uploads/up/lw-migration-abcdef123456/x' ) );
			$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => $c . '/uploads', 'rel' => '2026/a.jpg' ], $rules->route( 'uploads/2026/a.jpg' ) );
			$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => $c . '/uploads', 'rel' => '2027/new/a.jpg' ], $rules->route( 'uploads/2027/new/a.jpg' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	public function test_missing_base_is_refused(): void {
		$bases = self::BASES;
		unset( $bases['staging'] );

		$this->expectException( \InvalidArgumentException::class );
		self::rules( $bases, null );
	}

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public static function networkRoutes(): array {
		$skip = static fn( string $reason ): array => [ 'action' => ContentRules::SKIP, 'reason' => $reason ];
		$to   = static fn( string $base, string $rel ): array => [ 'action' => ContentRules::EXTRACT, 'base' => $base, 'rel' => $rel ];
		return [
			'blog uploads'          => [ 'uploads/sites/3/2026/a.jpg', $to( '/w/c/uploads', 'sites/8/2026/a.jpg' ) ],
			'legacy blog files'     => [ 'blogs.dir/3/files/a.jpg', $to( '/w/c/uploads', 'sites/8/a.jpg' ) ],
			'old main uploads'      => [ 'uploads/2026/m.jpg', $to( '/w/c/uploads', 'sites/7/2026/m.jpg' ) ],
			'unmapped blog'         => [ 'uploads/sites/4/a.jpg', $skip( 'unsafe' ) ],
			'unmapped legacy blog'  => [ 'blogs.dir/4/files/a.jpg', $skip( 'unsafe' ) ],
			'blog folder itself'    => [ 'uploads/sites/3', $skip( 'unsafe' ) ],
			'legacy folder itself'  => [ 'blogs.dir/3/files', $skip( 'unsafe' ) ],
			'generated blog cache'  => [ 'uploads/sites/3/elementor/css/post-1.css', $skip( 'generated' ) ],
			'must-use plugin'       => [ 'mu-plugins/custom.php', $skip( SharedCode::SHARED ) ],
			'hosting must-use'      => [ 'mu-plugins/gd-system-plugin.php', $skip( SharedCode::SHARED ) ],
			'wp-content root file'  => [ 'index.php', $skip( SharedCode::SHARED ) ],
			'drop-in'               => [ 'object-cache.php', $skip( 'dropin' ) ],
			'wp-config'             => [ 'wp-config.php', $skip( 'config' ) ],
			'plugin of the network' => [ 'plugins/akismet/akismet.php', $skip( 'kept:plugins/akismet' ) ],
			'new plugin'            => [ 'plugins/shop/shop.php', $skip( 'new:plugins/shop' ) ],
			'theme of the network'  => [ 'themes/kept/style.css', $skip( 'kept:themes/kept' ) ],
			'new theme'             => [ 'themes/fresh/style.css', $skip( 'new:themes/fresh' ) ],
			'new file elsewhere'    => [ 'languages/hu_HU.mo', $to( '/w/c', 'languages/hu_HU.mo' ) ],
		];
	}

	/** @param array<string, string> $expected */
	#[DataProvider( 'networkRoutes' )]
	public function test_a_sites_archive_into_a_network_routes_uploads_by_blog_and_keeps_the_networks_code( string $entry, array $expected ): void {
		$map   = BlogMap::fromArray(
			[
				[
					'old'   => [ 'BlogID' => 1 ],
					'newId' => 7,
				],
				[
					'old'   => [ 'BlogID' => 3 ],
					'newId' => 8,
				],
			]
		);
		$code  = SharedCode::fromArray(
			[
				'plugins' => [ 'akismet' ],
				'themes'  => [ 'kept' ],
			],
			'/w/c'
		);
		$rules = new ContentRules( self::BASES, [ 'object-cache.php' ], UploadRoutes::forNetwork( ArchiveKind::SITES, $map, false ), new ProtectedTargets( '/w/c', self::PROTECTED, [] ), $code );

		$this->assertSame( $expected, $rules->route( $entry ) );
	}

	public function test_a_sites_archive_into_a_network_only_adds_files_to_other_shared_folders(): void {
		$dir = TempDir::create();
		try {
			mkdir( $dir . '/languages', 0755, true );
			file_put_contents( $dir . '/languages/hu_HU.mo', 'network' );
			$bases = [
				'content' => $dir,
				'plugins' => $dir . '/plugins',
				'themes'  => $dir . '/themes',
				'uploads' => $dir . '/uploads',
				'staging' => $dir . '/staging',
			];
			$rules = new ContentRules( $bases, [], UploadRoutes::forSubsite( null ), new ProtectedTargets( $dir, [], [] ), SharedCode::fromArray( [], $dir ) );

			$this->assertSame( [ 'action' => ContentRules::SKIP, 'reason' => SharedCode::EXISTING ], $rules->route( 'languages/hu_HU.mo' ), 'The network\'s file stays.' );
			$this->assertSame( [ 'action' => ContentRules::EXTRACT, 'base' => $dir, 'rel' => 'languages/de_DE.mo' ], $rules->route( 'languages/de_DE.mo' ) );
		} finally {
			TempDir::remove( $dir );
		}
	}

	/**
	 * @param array<string, string> $bases     Bases.
	 * @param int|null              $subsiteId Sub-site id.
	 * @param array<string, string> $protected Protected absolute path => reason.
	 */
	private static function rules( array $bases, ?int $subsiteId, array $protected = self::PROTECTED ): ContentRules {
		$content = rtrim( $bases['content'], '/' );
		$paths   = $protected + [
			rtrim( $bases['plugins'], '/' ) . '/lw-migration' => 'own',
			$content . '/ai1wm-backups'                        => 'storage',
			$content . '/lw-migration'                         => 'storage',
		];

		return new ContentRules( $bases, [ 'object-cache.php', 'Advanced-Cache.php' ], UploadRoutes::forSubsite( $subsiteId ), new ProtectedTargets( $bases['content'], $paths, [] ) );
	}
}
