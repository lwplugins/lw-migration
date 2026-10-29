<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\LinkRoutes;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;

final class LinkRoutesTest extends MonkeyTestCase {

	public function test_every_admin_route_needs_the_capability(): void {
		$registered = $this->registered();

		$this->assertSame(
			[
				'/links'                                 => [ 'GET', 'POST' ],
				'/links/code'                            => [ 'POST', 'DELETE' ],
				'/links/(?P<lid>[a-f0-9]{16})'           => [ 'DELETE' ],
				'/links/(?P<lid>[a-f0-9]{16})/check'     => [ 'POST' ],
				'/links/(?P<lid>[a-f0-9]{16})/push'      => [ 'POST' ],
				'/links/(?P<lid>[a-f0-9]{16})/pull'      => [ 'POST' ],
			],
			array_map( static fn( array $entry ): array => array_column( $entry[1], 'methods' ), $registered )
		);
		$this->assertSame( [ LinkRoutes::class, 'pushTo' ], $registered[ LinkRoutes::ONE . '/push' ][1][0]['callback'] );
		$this->assertSame( [ LinkRoutes::class, 'pullFrom' ], $registered[ LinkRoutes::ONE . '/pull' ][1][0]['callback'] );
		foreach ( $registered as $route => [ $namespace, $endpoints ] ) {
			$this->assertSame( Routes::NAMESPACE, $namespace );
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( [ Permission::class, 'check' ], $endpoint['permission_callback'], $route );
				$this->assertFalse( $endpoint['show_in_index'], $route );
				$this->assertSame( LinkRoutes::class, $endpoint['callback'][0] );
				$this->assertTrue( method_exists( LinkRoutes::class, $endpoint['callback'][1] ), $endpoint['callback'][1] );
			}
		}
	}

	public function test_nothing_is_registered_on_a_network_or_outside_the_main_site(): void {
		$this->assertSame( [], $this->registered( true, true ) );
		$this->assertSame( [], $this->registered( true, false ) );
	}

	public function test_the_link_id_route_takes_only_sixteen_hex_digits(): void {
		$pattern = '@^' . LinkRoutes::ONE . '$@i';

		$this->assertSame( 1, preg_match( $pattern, '/links/0123456789abcdef' ) );
		foreach ( [ '/links/0123456789abcde', '/links/0123456789abcdef0', '/links/code', '/links/../../x' ] as $bad ) {
			$this->assertSame( 0, preg_match( $pattern, $bad ), $bad );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, array<string, mixed>>}>
	 */
	private function registered( bool $multisite = false, bool $mainSite = true ): array {
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $endpoints ) use ( &$registered ) {
				$registered[ $route ] = [ $namespace, $endpoints ];
				return true;
			}
		);

		LinkRoutes::register();

		return $registered;
	}
}
