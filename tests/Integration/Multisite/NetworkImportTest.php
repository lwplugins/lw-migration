<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Integration\Multisite;

use LightweightPlugins\Migration\Database\ImportCursor;
use LightweightPlugins\Migration\Database\ImportPlan;
use LightweightPlugins\Migration\Database\TableSwap;
use LightweightPlugins\Migration\Multisite\ArchiveKind;
use LightweightPlugins\Migration\Multisite\BlogMap;
use LightweightPlugins\Migration\Multisite\NetworkImport;
use LightweightPlugins\Migration\Multisite\NetworkPrefixPairs;
use LightweightPlugins\Migration\Multisite\TargetNetwork;
use LightweightPlugins\Migration\Package\MultisiteConfig;
use LightweightPlugins\Migration\Tests\Integration\DatabaseTestCase;
use LightweightPlugins\Migration\Tests\Support\TempDir;

/**
 * Reference-made network, sites and single-site archives imported into a live
 * three-site network (NetworkTables): everything lands in temporary tables, blog
 * tables under their new ids, the archive's network tables of a subsite import
 * staged under lwmsrc_, and no live table changes.
 */
final class NetworkImportTest extends DatabaseTestCase {

	private const TMP = 'lwtab12_';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = TempDir::create();
		$this->exec( ...NetworkTables::create( 'wp_', [ 1, 2, 3 ] ) );
		$this->exec( ...NetworkTables::seed( 'wp_' ) );
	}

	protected function tearDown(): void {
		TempDir::remove( $this->dir );
	}

	public function test_a_network_archive_lands_in_temp_tables_with_its_ids(): void {
		$before  = $this->liveChecksums();
		$to      = self::target( true );
		$entries = MultisiteConfig::fromJson( $this->entry( 'network.wpress', 'multisite.json' ) )->entries();
		$network = new NetworkImport( ArchiveKind::NETWORK, BlogMap::plan( ArchiveKind::NETWORK, $entries, 'http://net-n.test', $to ), $to );

		$this->import( 'network.wpress', $network );

		foreach ( [ 'lwtab12_users', 'lwtab12_sitemeta', 'lwtab12_2_posts', 'lwtab12_3_options' ] as $table ) {
			$this->assertContains( $table, $this->tables() );
		}
		$this->assertSame(
			[ [ 'new.test', '/' ], [ 'b2.new.test', '/' ], [ 'b3.new.test', '/' ] ],
			array_map( static fn( array $r ): array => [ $r['domain'], $r['path'] ], self::db()->query( 'SELECT domain, path FROM lwtab12_blogs ORDER BY blog_id' ) )
		);
		$this->assertStringContainsString( 'http://b2.new.test/hello/', $this->value( "SELECT post_content FROM lwtab12_2_posts WHERE post_title = 'Network Fixture B2'" ) );
		$this->assertSame( [], $this->tablesLike( 'lwtab12\\_lwmsrc\\_%' ), 'A whole-network archive stages nothing.' );
		$this->assertSame( $before, $this->liveChecksums(), 'No live table changed.' );
	}

	public function test_a_sites_archive_is_moved_to_its_new_blog_and_its_users_are_staged(): void {
		$before = $this->liveChecksums();
		$upload = "INSERT INTO `SERVMASK_PREFIX_2_postmeta` VALUES (900,3,'file','http://net-n.test/wp-content/uploads/sites/2/2026/10/b2.txt');\n";

		$this->import( 'sites-2.wpress', self::moved( ArchiveKind::SITES, 2, 7, 'http://new.test/shop' ), $upload );

		$this->assertStringContainsString( 'http://new.test/shop/hello/', $this->value( "SELECT post_content FROM lwtab12_7_posts WHERE post_title = 'Network Fixture B2'" ) );
		$editor = $this->value( "SELECT ID FROM lwtab12_lwmsrc_users WHERE user_login = 'editor2'" );
		$this->assertNotSame( '', $editor );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_lwmsrc_usermeta WHERE meta_key = 'wp_7_capabilities' AND user_id = " . (int) $editor ) );
		$this->assertSame( 'http://new.test/wp-content/uploads/sites/7/2026/10/b2.txt', $this->value( 'SELECT meta_value FROM lwtab12_7_postmeta WHERE meta_id = 900' ) );
		$this->assertContains( 'lwtab12_lwmsrc_sitemeta', $this->tables() );
		$this->assertSame( $before, $this->liveChecksums(), 'No live table changed.' );

		( new TableSwap( self::db(), self::TMP, 'wp_', ImportPlan::DEFAULT_EXTRA_PREFIXES, [ 'lwmsrc_' ] ) )->swap();
		$this->assertContains( 'wp_7_posts', $this->tables() );
		$this->assertSame( [], $this->tablesLike( 'wp\\_lwmsrc\\_%' ), 'Held staging tables never go live.' );
		$this->assertSame( [ 'admin', 'author3', 'editor2' ], array_column( self::db()->query( 'SELECT user_login FROM wp_users ORDER BY user_login' ), 'user_login' ), 'The network\'s users are only merged later.' );
	}

	public function test_a_single_site_archive_becomes_blog_9(): void {
		$before = $this->liveChecksums();
		$extra  = "CREATE TABLE `SERVMASK_PREFIX_sitemap_cache` (`id` int NOT NULL);\nINSERT INTO `SERVMASK_PREFIX_sitemap_cache` VALUES (1);\n";

		$this->import( 'full-site.wpress', self::moved( ArchiveKind::SINGLE, null, 9, 'http://new.test/site-a' ), $extra );

		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_9_posts WHERE post_title = 'Reference Fixture Post'" ) );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_9_options WHERE option_name = 'wp_9_user_roles'" ) );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_lwmsrc_users WHERE user_login = 'editor1'" ) );
		$this->assertSame( '0', $this->value( "SELECT COUNT(*) FROM lwtab12_9_posts WHERE post_content LIKE '%site-a.test%'" ), 'Every link points to the new address.' );
		$this->assertSame( '1', $this->value( "SELECT COUNT(*) FROM lwtab12_9_posts WHERE post_content LIKE '%http://new.test/site-a/hello/%'" ) );
		$this->assertContains( 'lwtab12_9_sitemap_cache', $this->tables(), 'Decision 14: global tables are matched as whole names.' );
		$this->assertNotContains( 'lwtab12_lwmsrc_sitemap_cache', $this->tables() );
		$this->assertSame( $before, $this->liveChecksums(), 'No live table changed.' );
	}

	/**
	 * Ruling R6: a crafted placeholder table or an extra-prefix table never becomes a
	 * base-prefix or extra-prefix temporary table in a sites or single-site import.
	 */
	public function test_no_base_prefix_or_extra_prefix_temp_table_is_created_by_a_sites_or_single_import(): void {
		$crafted = "CREATE TABLE `SERVMASK_PREFIX_options` (`option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL);\n"
			. "INSERT INTO `SERVMASK_PREFIX_options` VALUES ('lw_migration_lock','taken');\n"
			. "DROP TABLE IF EXISTS `wbk_bookings`;\nCREATE TABLE `wbk_bookings` (`id` int NOT NULL);\nINSERT INTO `wbk_bookings` VALUES (1);\n";
		$cases   = [
			[ 'sites-2.wpress', self::moved( ArchiveKind::SITES, 2, 7, 'http://new.test/shop' ), '/^lwtab12_(7_|lwmsrc_)/' ],
			[ 'full-site.wpress', self::moved( ArchiveKind::SINGLE, null, 9, 'http://new.test/site-a' ), '/^lwtab12_(9_|lwmsrc_)/' ],
		];
		foreach ( $cases as [ $file, $network, $shape ] ) {
			$this->dropTemporary();

			$cursor = $this->import( $file, $network, $crafted );

			$this->assertNotSame( [], $this->tablesLike( 'lwtab12\\_%' ) );
			foreach ( $this->tablesLike( 'lw%' ) as $table ) {
				$this->assertMatchesRegularExpression( $shape, $table, $file );
			}
			$this->assertSame( [ 'wbk_bookings' ], $cursor->leftOut, $file );
			$this->assertSame( [], preg_grep( '/wbk_/', $cursor->warnings ), 'Left out once, not warned per statement.' );
			$this->assertSame( 'x', $this->value( "SELECT option_value FROM wp_options WHERE option_name = 'lw_migration_lock'" ) );
		}
	}

	/**
	 * Review C1/I1/M3: statements that name this run's temporary prefix directly,
	 * the archive's network tables under every placeholder spelling, and views of
	 * other sites change nothing outside the mapped sites' tables, even after the swap.
	 *
	 * @return array<string, array{0: string, 1: array<int, array{0: int|null, 1: int}>}>
	 */
	public static function crafted(): array {
		return [
			'sites without the main site' => [ 'sites-2.wpress', ArchiveKind::SITES, [ [ 2, 7 ] ] ],
			'sites with the main site'    => [ 'network.wpress', ArchiveKind::SITES, [ [ 1, 7 ], [ 2, 8 ], [ 3, 9 ] ] ],
			'single site'                 => [ 'full-site.wpress', ArchiveKind::SINGLE, [ [ null, 7 ] ] ],
		];
	}

	/**
	 * @dataProvider crafted
	 * @param string                                   $file Archive.
	 * @param string                                   $kind ArchiveKind value.
	 * @param array<int, array{0: int|null, 1: int}> $ids  Old id => new id.
	 */
	public function test_a_crafted_subsite_archive_changes_nothing_outside_its_new_sites( string $file, string $kind, array $ids ): void {
		$this->exec( 'CREATE VIEW `wp_mainview` AS SELECT 1 AS a', 'CREATE VIEW `wp_3_view` AS SELECT 3 AS a' );
		$before  = $this->liveChecksums();
		$t       = self::TMP;
		$crafted = "CREATE TABLE `{$t}users` (`ID` int, `user_login` varchar(60));\nINSERT INTO `{$t}users` VALUES (1,'attacker');\n"
			. "CREATE TABLE `{$t}options` (`option_name` varchar(191), `option_value` longtext);\nINSERT INTO `{$t}options` VALUES ('siteurl','http://evil.test');\n"
			. "CREATE TABLE `{$t}3_options` (`option_name` varchar(191), `option_value` longtext);\nINSERT INTO `{$t}3_options` VALUES ('siteurl','http://evil.test');\n"
			. "CREATE TABLE `{$t}70_posts` (`ID` int);\n"
			. "CREATE TABLE `SERVMASK_PREFIX_users` (`ID` int, `user_login` varchar(60));\nINSERT INTO `SERVMASK_PREFIX_users` VALUES (99,'crafted');\n"
			. "CREATE TABLE `SERVMASK_PREFIX_mainsite_options` (`option_name` varchar(191), `option_value` longtext);\n"
			. "CREATE TABLE `SERVMASK_PREFIX_basesite_x` (`a` int);\n"
			. "CREATE VIEW `SERVMASK_PREFIX_mainview` AS select 2 AS `a`;\nDROP VIEW IF EXISTS `wp_3_view`;\nCREATE VIEW `wp_mainview` AS select 2 AS `a`;\nCREATE VIEW `SERVMASK_PREFIX_mainsite_v` AS select 2 AS `a`;\n";
		$network = self::mapped( $file, $kind, $ids );
		$sites   = NetworkPrefixPairs::siteSuffixes( $network );

		$this->import( $file, $network, $crafted );
		foreach ( $this->tablesLike( 'lw%' ) as $table ) {
			$this->assertMatchesRegularExpression( '/^lwtab12_(' . implode( '|', array_map( 'preg_quote', $sites ) ) . '|lwmsrc_)/', $table );
		}
		$swap = new TableSwap( self::db(), self::TMP, 'wp_', ImportPlan::DEFAULT_EXTRA_PREFIXES, [ NetworkPrefixPairs::STAGE ], $sites );
		$swap->swap();
		if ( is_file( $this->dir . '/deferred.sql' ) ) {
			$swap->applyDeferred( $this->dir . '/deferred.sql' );
		}

		$after = $this->liveChecksums();
		foreach ( $before as $table => $sum ) {
			if ( 1 !== preg_match( '/^wp_(' . implode( '|', array_map( 'preg_quote', $sites ) ) . ')/', $table ) ) {
				$this->assertSame( $sum, $after[ $table ] ?? null, $table . ' changed' );
			}
		}
		$this->assertSame( [ [ 'a' => '1' ] ], self::db()->query( 'SELECT a FROM wp_mainview' ) );
		$this->assertSame( [ [ 'a' => '3' ] ], self::db()->query( 'SELECT a FROM wp_3_view' ) );
		$this->assertSame( [ 'admin', 'author3', 'editor2' ], array_column( self::db()->query( 'SELECT user_login FROM wp_users ORDER BY user_login' ), 'user_login' ) );
		foreach ( $this->tables() as $table ) {
			$this->assertMatchesRegularExpression( '/^(wp_(\d+_)?[a-z_]+|lwtab12_lwmsrc_[a-z_]+)$/', $table );
			$this->assertDoesNotMatchRegularExpression( '/^wp_lwmsrc_/', $table );
		}
	}

	/**
	 * @param string                                   $file Archive.
	 * @param string                                   $kind ArchiveKind value.
	 * @param array<int, array{0: int|null, 1: int}> $ids  Old id => new id.
	 */
	private function mapped( string $file, string $kind, array $ids ): NetworkImport {
		$entries = ArchiveKind::SINGLE === $kind ? [ [ 'BlogID' => null ] ] : (array) json_decode( $this->entry( $file, 'multisite.json' ), true )['Sites'];
		$byOld   = [];
		foreach ( $entries as $entry ) {
			$byOld[ (string) ( $entry['BlogID'] ?? '' ) ] = $entry;
		}
		$maps = [];
		foreach ( $ids as [ $old, $new ] ) {
			$url    = 'http://new.test/s' . $new;
			$maps[] = [
				'old'           => $byOld[ (string) $old ],
				'newId'         => $new,
				'url'           => $url,
				'created'       => true,
				'newSiteUrl'    => $url,
				'newHomeUrl'    => $url,
				'newUploadsUrl' => 'http://new.test/wp-content/uploads/sites/' . $new . '/',
			];
		}

		return new NetworkImport( $kind, BlogMap::fromArray( $maps ), self::target( false ) );
	}

	private function import( string $file, NetworkImport $network, string $append = '' ): ImportCursor {
		return ArchiveImport::run( self::db(), $file, $network, $this->dir, self::TMP, $append );
	}

	private static function moved( string $kind, ?int $old, int $new, string $url ): NetworkImport {
		$entry = [
			'BlogID'    => $old,
			'SiteURL'   => null === $old ? 'http://site-a.test' : 'http://net-n.test/b' . $old,
			'HomeURL'   => null === $old ? 'http://site-a.test' : 'http://net-n.test/b' . $old,
			'WordPress' => [ 'UploadsURL' => null === $old ? 'http://site-a.test/wp-content/uploads/' : 'http://net-n.test/wp-content/uploads/sites/' . $old . '/' ],
		];
		$map   = BlogMap::fromArray(
			[
				[
					'old'           => $entry,
					'newId'         => $new,
					'url'           => $url,
					'created'       => true,
					'newSiteUrl'    => $url,
					'newHomeUrl'    => $url,
					'newUploadsUrl' => 'http://new.test/wp-content/uploads/sites/' . $new . '/',
				],
			]
		);

		return new NetworkImport( $kind, $map, self::target( false ) );
	}

	private static function target( bool $subdomain ): TargetNetwork {
		return TargetNetwork::fromArray(
			[
				'subdomain'  => $subdomain,
				'domain'     => 'new.test',
				'path'       => '/',
				'scheme'     => 'http',
				'uploadsDir' => '/srv/new/wp-content/uploads',
				'uploadsUrl' => 'http://new.test/wp-content/uploads',
				'basePrefix' => 'wp_',
			]
		);
	}

	private function entry( string $file, string $name ): string {
		return ArchiveImport::entry( $file, $name );
	}

	/** @return array<string, string> Live table => checksum. */
	private function liveChecksums(): array {
		$sums = [];
		foreach ( self::db()->query( "SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'" ) as $row ) {
			$table = (string) array_values( $row )[0];
			if ( ! str_starts_with( $table, 'wp_' ) ) {
				continue;
			}
			$sums[ $table ] = (string) ( self::db()->query( 'CHECKSUM TABLE `' . $table . '`' )[0]['Checksum'] ?? '' );
		}

		return $sums;
	}

	private function value( string $sql ): string {
		$rows = self::db()->query( $sql );

		return [] === $rows ? '' : (string) array_values( $rows[0] )[0];
	}

	/** @return array<int, string> */
	private function tables(): array {
		return $this->tablesLike( '%' );
	}

	/** @return array<int, string> Sorted names matching a LIKE pattern. */
	private function tablesLike( string $like ): array {
		$names = array_map( static fn( array $r ): string => (string) array_values( $r )[0], self::db()->query( "SHOW TABLES LIKE '" . $like . "'" ) );
		sort( $names );

		return $names;
	}

	private function dropTemporary(): void {
		foreach ( $this->tablesLike( 'lw%' ) as $table ) {
			$this->exec( 'DROP TABLE `' . $table . '`' );
		}
	}
}
