<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\Unit\Reset;

use LightweightPlugins\Migration\Reset\ResetTables;
use PHPUnit\Framework\TestCase;

final class ResetTablesNamesTest extends TestCase {

	public function test_the_tables_that_go_are_named_first_ten_and_counted(): void {
		$retire = array_map( static fn( int $i ): string => sprintf( 'wp_t%02d', $i ), range( 1, 12 ) );
		$plan   = [
			'core'   => [ 'options', 'posts' ],
			'retire' => $retire,
			'kept'   => [ 'wp_shop_options', 'wp_shop_posts' ],
			'views'  => [ 'wp_report' ],
		];

		$this->assertSame(
			[
				'replaced'     => 2,
				'retired'      => 12,
				'kept'         => 2,
				'retiredNames' => array_slice( $retire, 0, 10 ),
				'more'         => 2,
			],
			ResetTables::named( $plan )
		);
	}

	public function test_a_short_list_has_no_more(): void {
		$named = ResetTables::named(
			[
				'core'   => [],
				'retire' => [ 'wp_log' ],
				'kept'   => [],
				'views'  => [],
			]
		);

		$this->assertSame( [ 'wp_log' ], $named['retiredNames'] );
		$this->assertSame( 0, $named['more'] );
	}

	public function test_a_prefix_without_underscore_warns(): void {
		$this->assertTrue( ResetTables::prefixWarning( 'wp' ) );
		$this->assertFalse( ResetTables::prefixWarning( 'wp_' ) );
	}

	public function test_a_prefix_that_is_empty_or_could_name_our_run_tables_is_unsafe(): void {
		foreach ( [ '', 'l', 'lw', 'LWT', 'lwo', 'lwtab', 'lwtab12_', 'lwyab12_x_', 'LwXaB12_' ] as $prefix ) {
			$this->assertTrue( ResetTables::unsafePrefix( $prefix ), $prefix );
		}
		foreach ( [ 'wp_', 'wp', 'lwa_', 'lwtabc12_', 'lwt_', 'shop_' ] as $prefix ) {
			$this->assertFalse( ResetTables::unsafePrefix( $prefix ), $prefix );
		}
	}

	public function test_the_core_tables_are_the_ones_core_declares_not_what_plugins_added(): void {
		$wpdb           = new class() {
			/** @var array<int, string> */
			public $tables = [ 'posts', 'options' ];
			/** @var array<int, string> */
			public $global_tables = [ 'users', 'usermeta' ];
		};
		$wpdb->tables[] = 'shopx_itemmeta';

		$this->assertSame( [ 'users', 'usermeta', 'posts', 'options' ], ResetTables::coreSuffixes( get_class( $wpdb ) ) );
	}
}
