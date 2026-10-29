<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Database;

use LightweightPlugins\Migration\Database\PrefixMap;
use PHPUnit\Framework\TestCase;

final class PrefixMapTest extends TestCase {

	public function test_pairs_apply_in_order_at_position_zero_case_insensitively(): void {
		$map = new PrefixMap(
			[ [ 'wp_2_', 'SERVMASK_PREFIX_2_' ], [ 'wp_users', 'SERVMASK_PREFIX_mainsite_users' ], [ 'wp_', 'SERVMASK_PREFIX_basesite_' ] ],
			[ [ 'wp_2_', 'SERVMASK_PREFIX_2_' ], [ 'wp_', 'SERVMASK_PREFIX_basesite_' ] ]
		);

		$this->assertSame( 'SERVMASK_PREFIX_2_posts', $map->exportTableName( 'wp_2_posts' ) );
		$this->assertSame( 'SERVMASK_PREFIX_mainsite_users', $map->exportTableName( 'WP_users' ) );
		$this->assertSame( 'SERVMASK_PREFIX_basesite_posts', $map->exportTableName( 'wp_posts' ) );
		$this->assertSame( 'other_table', $map->exportTableName( 'other_table' ) );
		$this->assertSame( 'SERVMASK_PREFIX_basesite_capabilities', $map->exportColumnValue( 'wp_capabilities' ) );
		$this->assertSame( 'SERVMASK_PREFIX_2_capabilities', $map->exportColumnValue( 'wp_2_capabilities' ) );
		$this->assertSame( 'nickname', $map->exportColumnValue( 'nickname' ) );
	}

	public function test_a_later_pair_sees_the_rewritten_name_like_the_format_does(): void {
		$map = new PrefixMap( [ [ 'a_', 'b_' ], [ 'b_', 'c_' ] ], [] );

		$this->assertSame( 'c_x', $map->exportTableName( 'a_x' ) );
	}

	public function test_reserved_names_are_never_rewritten(): void {
		$map = new PrefixMap( [], [ [ 'wp_', 'SERVMASK_PREFIX_' ] ] );

		$this->assertSame( 'wp_force_deactivated_plugins', $map->exportColumnValue( 'wp_force_deactivated_plugins' ) );
		$this->assertSame( 'wp_rocket_settings', $map->exportColumnValue( 'wp_rocket_settings' ) );
	}
}
