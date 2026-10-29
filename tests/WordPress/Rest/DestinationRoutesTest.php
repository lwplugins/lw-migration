<?php
declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Rest\DestinationRoutes;
use LightweightPlugins\Migration\Rest\ErrorMap;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class DestinationRoutesTest extends MonkeyTestCase {

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function registered( bool $mainSite ): array {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( $mainSite );
		Functions\when( 'get_main_network_id' )->justReturn( 1 );
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $endpoints ) use ( &$registered ) {
				self::assertSame( Routes::NAMESPACE, $namespace );
				$registered[ $route ] = $endpoints;
				return true;
			}
		);
		DestinationRoutes::register();

		return $registered;
	}

	public function test_every_route_needs_the_capability_and_is_hidden(): void {
		$routes = $this->registered( true );

		$this->assertNotEmpty( $routes );
		foreach ( $routes as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$guard = '/oauth/callback' === $route ? 'oauthCallback' : 'check'; // M7c: the provider's redirect carries no REST nonce.
				$this->assertSame( [ Permission::class, $guard ], $endpoint['permission_callback'], $route );
				$this->assertFalse( $endpoint['show_in_index'], $route );
			}
		}
		$this->assertArrayHasKey( '/destinations/' . DestinationRoutes::DID . '/test', $routes );
		$this->assertSame( 'POST', $routes[ '/destinations/' . DestinationRoutes::DID . '/trust' ][0]['methods'] );
		$this->assertArrayHasKey( '/destinations/' . DestinationRoutes::DID . '/connect', $routes );
		$this->assertArrayHasKey( '/oauth/callback', $routes );
	}

	public function test_none_outside_the_main_site(): void {
		$this->assertSame( [], $this->registered( false ) );
	}

	public function test_the_id_route_matches_only_eight_hex_digits(): void {
		$pattern = '@^/destinations/' . DestinationRoutes::DID . '$@i';

		$this->assertSame( 1, preg_match( $pattern, '/destinations/0a1b2c3d' ) );
		foreach ( [ '0a1b2c3', '0a1b2c3d0', '../../x', '0a1b2c3g' ] as $bad ) {
			$this->assertSame( 0, preg_match( $pattern, '/destinations/' . $bad ), $bad );
		}
	}

	#[RunInSeparateProcess]
	public function test_a_file_name_that_is_not_text_is_refused_before_anything_else(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-response-class.php';
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'did' )->andReturn( 'aaaa1111' );
		$request->shouldReceive( 'get_param' )->with( 'name' )->andReturn( [ 'a.wpress' ] );

		$error = DestinationRoutes::deleteFile( $request );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( [ ErrorMap::INVALID, 400, 'name' ], [ $error->get_error_code(), $error->get_error_data()['status'] ?? null, $error->get_error_data()['field'] ?? null ] );
	}
}
