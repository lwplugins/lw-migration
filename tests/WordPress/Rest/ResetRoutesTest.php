<?php
/**
 * Tests for ResetRoutes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\ResetRoutes;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class ResetRoutesTest extends MonkeyTestCase {

	/**
	 * @return array<string, array<int, array<string, mixed>>> Route => endpoints.
	 */
	private function registered( bool $multisite = false, bool $mainSite = true ): array {
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $endpoints ) use ( &$registered ) {
				$registered[ $route ] = $endpoints;
				self::assertSame( Routes::NAMESPACE, $namespace );
				return true;
			}
		);
		ResetRoutes::register();

		return $registered;
	}

	public function test_both_routes_are_guarded_by_the_capability_and_hidden(): void {
		$routes = $this->registered();
		$this->assertSame( [ '/reset' ], array_keys( $routes ) );
		$this->assertSame( [ 'GET', 'POST' ], array_column( $routes['/reset'], 'methods' ) );
		$this->assertSame( [ [ ResetRoutes::class, 'summary' ], [ ResetRoutes::class, 'start' ] ], array_column( $routes['/reset'], 'callback' ) );
		foreach ( $routes['/reset'] as $endpoint ) {
			$this->assertSame( [ Permission::class, 'check' ], $endpoint['permission_callback'] );
			$this->assertFalse( $endpoint['show_in_index'] );
		}
	}

	public function test_the_network_main_site_has_the_routes(): void {
		$this->assertSame( [ '/reset' ], array_keys( $this->registered( true, true ) ) );
	}

	public function test_the_permission_callback_never_checks_the_password(): void {
		// Permission::check is what core calls twice (also on rest_post_dispatch); the password
		// check lives in ResetRoutes::start(). Calling the permission callback with a wrong password
		// in the body changes nothing in lw_migration_reset_tries.
		$db = FakeWpdb::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertTrue( Permission::check() );
		}
		$this->assertNull( $db->value( Options::RESET_TRIES ) );
		$this->assertSame( [], $db->rows );
	}

	public function test_not_registered_outside_the_main_site(): void {
		$this->assertSame( [], $this->registered( true, false ) );
	}
}
