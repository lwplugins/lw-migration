<?php
/**
 * Tests for ShareRoutes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Archive\Format;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Rest\ShareRoutes;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ShareRoutesTest extends MonkeyTestCase {

	public function test_management_routes_need_the_capability(): void {
		$registered = $this->registered();

		$this->assertSame( [ '/backups/shares', '/backups/shares/(?P<sid>[a-f0-9]{8})', '/backups/shared' ], array_keys( $registered ) );
		foreach ( $registered as $route => [ $namespace, $endpoints ] ) {
			$this->assertSame( Routes::NAMESPACE, $namespace );
			foreach ( $endpoints as $endpoint ) {
				$guard = '/backups/shared' === $route ? 'share' : 'check';
				$this->assertSame( [ Permission::class, $guard ], $endpoint['permission_callback'], $route );
				$this->assertFalse( $endpoint['show_in_index'], $route );
				$this->assertSame( ShareRoutes::class, $endpoint['callback'][0] );
				$this->assertTrue( method_exists( ShareRoutes::class, $endpoint['callback'][1] ), $endpoint['callback'][1] );
			}
		}
		$this->assertSame( [ 'GET', 'POST' ], array_column( $registered['/backups/shares'][1], 'methods' ) );
		$this->assertSame( [ 'DELETE' ], array_column( $registered['/backups/shares/(?P<sid>[a-f0-9]{8})'][1], 'methods' ) );
		$this->assertSame( [ 'GET' ], array_column( $registered['/backups/shared'][1], 'methods' ), 'Core answers HEAD through the GET route.' );
	}

	public function test_nothing_is_registered_outside_the_main_site(): void {
		$this->assertSame( [], $this->registered( true, false ) );
		$this->assertCount( 3, $this->registered( true, true ) );
	}

	public function test_the_link_id_route_takes_only_eight_hex_digits(): void {
		$pattern = '@^' . ShareRoutes::ONE . '$@i';

		$this->assertSame( 1, preg_match( $pattern, '/backups/shares/0a1b2c3d' ) );
		foreach ( [ '/backups/shares/0a1b2c3', '/backups/shares/0a1b2c3d0', '/backups/shares/../../x', '/backups/shared' ] as $bad ) {
			$this->assertSame( 0, preg_match( $pattern, $bad ), $bad );
		}
	}

	/**
	 * Ruling R11 and fix M2: a use is a download that started, judged by
	 * what the Downloader sends: a whole file (200, also for several ranges
	 * or a stale If-Range), or a 206 from byte 0 that reaches past the archive
	 * header. HEAD never counts and touches nothing; the importer's header
	 * probe (0-4376) and resumes are no use.
	 */
	public function test_only_a_download_that_starts_at_byte_zero_counts_as_a_use(): void {
		$header = Format::HEADER_SIZE;
		$cases  = [
			[ 'HEAD', 200, 0, 99, null ],
			[ 'HEAD', 206, 0, $header, null ],
			[ 'GET', 200, 0, 99, true ],
			[ 'GET', 200, 0, 0, true ],
			[ 'GET', 206, 0, $header, true ],
			[ 'GET', 206, 0, 1048575, true ],
			[ 'GET', 206, 0, $header - 1, false ],
			[ 'GET', 206, 0, 99, false ],
			[ 'GET', 206, 100, 1048575, false ],
		];
		foreach ( $cases as [ $method, $status, $first, $last, $expected ] ) {
			$this->assertSame( $expected, ShareRoutes::countsAsUse( $method, $status, $first, $last ), implode( ' ', [ $method, $status, $first, $last ] ) );
		}
	}

	#[RunInSeparateProcess]
	public function test_a_link_that_stopped_opening_after_the_guard_gets_the_same_refusal(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		Functions\when( 'is_multisite' )->justReturn( false );
		$db              = FakeWpdb::install();
		$db->beforeWrite = function (): void {
			$this->fail( 'Nothing is written for a refused link.' );
		};
		foreach ( [ null, [ 'x' ], str_repeat( 'a', 32 ) ] as $token ) {
			$request = \Mockery::mock( \WP_REST_Request::class );
			$request->shouldReceive( 'get_param' )->with( 'share' )->andReturn( $token );
			$request->shouldReceive( 'get_method' )->andReturn( 'GET' );

			$this->assertEquals( Permission::shareRefusal(), ShareRoutes::download( $request ) );
		}
	}

	/**
	 * Runs ShareRoutes::register() and records what it registered.
	 *
	 * @param bool $multisite Whether the site is a network.
	 * @param bool $mainSite  Whether the request is on the network's main site.
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

		ShareRoutes::register();

		return $registered;
	}
}
