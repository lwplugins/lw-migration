<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Multisite;

use LightweightPlugins\Migration\Multisite\ExportScope;
use PHPUnit\Framework\TestCase;

/**
 * The refusals (R24) need the translation functions: tests/WordPress/Multisite/ExportScopeRefusalTest.php.
 */
final class ExportScopeTest extends TestCase {

	private const LISTED = [ 'wp_blogs', 'wp_options', 'wp_posts', 'wp_sitemeta', 'wp_sitemap_x', 'wp_users', 'wp_usermeta', 'wp_bp_groups', 'wp_2_options', 'wp_2_posts', 'wp_3_options', 'wp_3_posts', 'wp_12_posts', 'wbk_x' ];

	public function test_network_mode_takes_everything_and_maps_blogs_then_the_base(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2, 3, 12 ], null );

		$this->assertSame( ExportScope::NETWORK, $scope->mode() );
		$this->assertTrue( $scope->hasMain() );
		$this->assertSame( [ 'wp_', 'wbk_', 'civicrm_' ], $scope->listPrefixes() );
		$this->assertSame( self::LISTED, $scope->tables( self::LISTED ) );
		$map = $scope->renamer();
		$this->assertSame( 'SERVMASK_PREFIX_12_posts', $map->exportTableName( 'wp_12_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_users', $map->exportTableName( 'wp_users' ) );
		$this->assertSame( 'SERVMASK_PREFIX_posts', $map->exportTableName( 'wp_posts' ) );
		$this->assertSame( 'wbk_x', $map->exportTableName( 'wbk_x' ) );
		$this->assertSame( 'SERVMASK_PREFIX_2_user_roles', $map->exportColumnValue( 'wp_2_user_roles' ) );
		$this->assertSame( [ 'wp_', 'wp_2_', 'wp_3_', 'wp_12_' ], $scope->blogPrefixes() );
		$this->assertSame( [ 'wp_options', 'wp_2_options', 'wp_3_options', 'wp_12_options' ], $scope->optionsTables() );
	}

	public function test_sites_with_the_main_site(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2, 3, 12 ], [ 3, 1 ] );

		$this->assertSame( ExportScope::SITES_WITH_MAIN, $scope->mode() );
		$this->assertSame( [ 'wp_' ], $scope->listPrefixes() );
		$this->assertSame( [ 'wp_blogs', 'wp_options', 'wp_posts', 'wp_sitemeta', 'wp_sitemap_x', 'wp_users', 'wp_usermeta', 'wp_bp_groups', 'wp_3_options', 'wp_3_posts' ], $scope->tables( self::LISTED ) );
		$map = $scope->renamer();
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_users', $map->exportTableName( 'wp_users' ) );
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_sitemap_x', $map->exportTableName( 'wp_sitemap_x' ), 'Global names match as prefixes, as the format does.' );
		$this->assertSame( 'SERVMASK_PREFIX_basesite_posts', $map->exportTableName( 'wp_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_basesite_bp_groups', $map->exportTableName( 'wp_bp_groups' ) );
		$this->assertSame( 'SERVMASK_PREFIX_3_posts', $map->exportTableName( 'wp_3_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_basesite_capabilities', $map->exportColumnValue( 'wp_capabilities' ) );
		$this->assertSame( [ 'wp_', 'wp_3_' ], $scope->blogPrefixes() );
		$this->assertSame( [ 'wp_options', 'wp_3_options' ], $scope->optionsTables() );
	}

	public function test_sites_without_the_main_site(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2, 3, 12 ], [ 2 ] );

		$this->assertSame( ExportScope::SITES, $scope->mode() );
		$this->assertFalse( $scope->hasMain() );
		$this->assertSame( [ 'wp_blogs', 'wp_sitemeta', 'wp_sitemap_x', 'wp_users', 'wp_usermeta', 'wp_bp_groups', 'wp_2_options', 'wp_2_posts' ], $scope->tables( self::LISTED ) );
		$map = $scope->renamer();
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_blogs', $map->exportTableName( 'wp_blogs' ) );
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_bp_groups', $map->exportTableName( 'wp_bp_groups' ) );
		$this->assertSame( 'SERVMASK_PREFIX_2_posts', $map->exportTableName( 'wp_2_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_user-settings', $map->exportColumnValue( 'wp_user-settings' ) );
		$this->assertSame( [ 'wp_2_' ], $scope->blogPrefixes() );
		$this->assertSame( [ 'wp_2_options' ], $scope->optionsTables() );
	}

	public function test_prefixed_columns_are_options_names_and_usermeta_keys(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2 ], [ 2 ] );

		$this->assertSame( [ 'wp_2_options' => 'option_name', 'wp_usermeta' => 'meta_key' ], $scope->prefixColumns( [ 'wp_2_options', 'wp_2_posts', 'wp_usermeta', 'wp_users' ] ) );
	}

	public function test_a_one_character_prefix_renames_only_the_short_keys(): void {
		$map = ( new ExportScope( 'w', [ 1, 2 ], null ) )->renamer();

		$this->assertSame( 'SERVMASK_PREFIX_capabilities', $map->exportColumnValue( 'wcapabilities' ) );
		$this->assertSame( 'SERVMASK_PREFIX_2_capabilities', $map->exportColumnValue( 'w2_capabilities' ) );
		$this->assertSame( 'widget_text', $map->exportColumnValue( 'widget_text' ) );
		$this->assertSame( 'SERVMASK_PREFIX_options', $map->exportTableName( 'woptions' ) );
	}

	public function test_row_filters_of_a_sites_export(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2, 3 ], [ 2 ] );
		$where = $scope->tableWhere( true, true, [ 'ai1wm_status' ], [ 'lw_migration_' ], static fn( string $s ): string => "'" . addslashes( $s ) . "'" );

		$this->assertSame( "`comment_approved` != 'spam'", $where['wp_2_comments'] );
		$this->assertSame( "`post_type` != 'revision'", $where['wp_2_posts'] );
		$this->assertSame( "`meta_key` NOT IN ('active_sitewide_plugins')", $where['wp_sitemeta'] );
		$this->assertSame( "`option_name` NOT IN ('ai1wm_status') AND `option_name` NOT LIKE 'lw\\\\_migration\\\\_%'", $where['wp_2_options'] );
		$this->assertSame( "`ID` IN (SELECT `user_id` FROM `wp_usermeta` WHERE `meta_key` IN ('wp_2_capabilities', 'wp_2_user_level'))", $where['wp_users'] );
		$this->assertSame( "`user_id` IN (SELECT `user_id` FROM `wp_usermeta` WHERE `meta_key` IN ('wp_2_capabilities', 'wp_2_user_level')) AND (`meta_key` REGEXP '^wp_2_' OR `meta_key` NOT REGEXP '^wp_')", $where['wp_usermeta'] );
		$this->assertArrayNotHasKey( 'wp_blogs', $where, 'blogs, site, signups … are never filtered.' );
		$this->assertArrayNotHasKey( 'wp_options', $where, 'The main site is not exported.' );
	}

	public function test_our_options_are_left_out_of_every_exported_options_table(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2, 3 ], [ 1, 3 ] );
		$where = $scope->tableWhere( false, false, [ 'ai1wm_status' ], [ 'lw_migration_' ], static fn( string $s ): string => "'" . addslashes( $s ) . "'" );

		$clause = "`option_name` NOT IN ('ai1wm_status') AND `option_name` NOT LIKE 'lw\\\\_migration\\\\_%'";
		$this->assertSame( $clause, $where['wp_options'] );
		$this->assertSame( $clause, $where['wp_3_options'] );
		$this->assertArrayNotHasKey( 'wp_3_comments', $where );
		$this->assertSame( "`user_id` IN (SELECT `user_id` FROM `wp_usermeta` WHERE `meta_key` IN ('wp_capabilities', 'wp_user_level', 'wp_3_capabilities', 'wp_3_user_level')) AND (`meta_key` REGEXP '^wp_3_' OR `meta_key` NOT REGEXP '^wp_[0-9]+_')", $where['wp_usermeta'] );
	}

	public function test_the_network_mode_filters_no_user(): void {
		$scope = new ExportScope( 'wp_', [ 1, 2 ], null );
		$where = $scope->tableWhere( false, false, [], [], static fn( string $s ): string => "'" . $s . "'" );

		$this->assertArrayNotHasKey( 'wp_users', $where );
		$this->assertArrayNotHasKey( 'wp_usermeta', $where );
		$this->assertSame( "`meta_key` NOT IN ('active_sitewide_plugins')", $where['wp_sitemeta'] );
	}

	/** @return array<string, array{0: array<int, mixed>}> */
	public static function emptyChoices(): array {
		return [
			'empty'       => [ [] ],
			'all invalid' => [ [ 0, -2, 'x' ] ],
		];
	}

	/**
	 * @dataProvider emptyChoices
	 * @param array<int, mixed> $chosen Chosen ids.
	 */
	public function test_an_empty_choice_is_refused_instead_of_meaning_the_whole_network( array $chosen ): void {
		$this->expectException( \InvalidArgumentException::class );
		new ExportScope( 'wp_', [ 1, 2 ], $chosen );
	}
}
