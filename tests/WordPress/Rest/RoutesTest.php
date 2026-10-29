<?php
/**
 * Tests for Routes.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Tests\WordPress\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Job\JobException;
use LightweightPlugins\Migration\Job\JobStore;
use LightweightPlugins\Migration\Link\Guard;
use LightweightPlugins\Migration\Link\Links;
use LightweightPlugins\Migration\Rest\DestinationRoutes;
use LightweightPlugins\Migration\Rest\LinkPeerRoutes;
use LightweightPlugins\Migration\Rest\LinkRoutes;
use LightweightPlugins\Migration\Rest\NetworkRoutes;
use LightweightPlugins\Migration\Rest\OAuthRoutes;
use LightweightPlugins\Migration\Rest\Permission;
use LightweightPlugins\Migration\Rest\ResetRoutes;
use LightweightPlugins\Migration\Rest\RestException;
use LightweightPlugins\Migration\Rest\RouteMap;
use LightweightPlugins\Migration\Rest\Routes;
use LightweightPlugins\Migration\Rest\ScheduleRoutes;
use LightweightPlugins\Migration\Rest\ShareRoutes;
use LightweightPlugins\Migration\Options;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Tests\Support\FakeOptions;
use LightweightPlugins\Migration\Tests\Support\FakeWpdb;
use LightweightPlugins\Migration\Tests\Support\SiteFixture;
use LightweightPlugins\Migration\Tests\Support\TempDir;
use LightweightPlugins\Migration\Tests\WordPress\MonkeyTestCase;
use LightweightPlugins\Migration\WordPress\SiteLock;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RoutesTest extends MonkeyTestCase {

	private const JOB = '/jobs/(?P<id>[a-f0-9]{16})';

	/** Every route of a single site, with its methods, in registration order. */
	private const SINGLE_SITE = [
		'/jobs'                               => [ 'POST' ],
		'/jobs/active'                        => [ 'GET' ],
		self::JOB                             => [ 'GET', 'DELETE' ],
		self::JOB . '/tick'                   => [ 'POST' ],
		self::JOB . '/input'                  => [ 'POST' ],
		self::JOB . '/upload'                 => [ 'POST' ],
		'/backups'                            => [ 'GET', 'DELETE' ],
		'/backups/label'                      => [ 'POST' ],
		'/backups/entries'                    => [ 'GET' ],
		'/backups/contents'                   => [ 'GET' ],
		'/backups/download-token'             => [ 'POST' ],
		'/backups/download'                   => [ 'GET' ],
		'/settings'                           => [ 'GET', 'POST' ],
		'/backup-dir'                         => [ 'GET', 'POST' ],
		'/overview'                           => [ 'GET' ],
		'/export/estimate'                    => [ 'GET' ],
		'/schedules'                          => [ 'GET', 'POST' ],
		'/schedules/(?P<sid>[a-f0-9]{8})'     => [ 'POST', 'DELETE' ],
		'/schedules/(?P<sid>[a-f0-9]{8})/run' => [ 'POST' ],
		'/schedules/(?P<sid>[a-f0-9]{8})/log' => [ 'GET', 'DELETE' ],
		'/schedules/continue'                 => [ 'POST' ],
		'/backups/shares'                     => [ 'GET', 'POST' ],
		'/backups/shares/(?P<sid>[a-f0-9]{8})' => [ 'DELETE' ],
		'/backups/shared'                     => [ 'GET' ],
	];

	/** The connection routes: single sites only (spec 19), after the transfer links. */
	private const LINKS = [
		'/links'                               => [ 'GET', 'POST' ],
		'/links/code'                          => [ 'POST', 'DELETE' ],
		'/links/(?P<lid>[a-f0-9]{16})'         => [ 'DELETE' ],
		'/links/(?P<lid>[a-f0-9]{16})/check'   => [ 'POST' ],
		'/links/(?P<lid>[a-f0-9]{16})/push'    => [ 'POST' ],
		'/links/(?P<lid>[a-f0-9]{16})/pull'    => [ 'POST' ],
		'/link/pair'                           => [ 'POST' ],
		'/link/hello'                          => [ 'POST' ],
		'/link'                                => [ 'DELETE' ],
		'/link/push'                           => [ 'POST' ],
		'/link/pull'                           => [ 'POST' ],
		LinkPeerRoutes::JOB                    => [ 'GET', 'DELETE' ],
		LinkPeerRoutes::JOB . '/tick'          => [ 'POST' ],
		LinkPeerRoutes::JOB . '/input'         => [ 'POST' ],
		LinkPeerRoutes::JOB . '/upload/(?P<offset>\d{1,18})' => [ 'POST' ],
		LinkPeerRoutes::JOB . '/archive'       => [ 'GET' ],
	];

	/** The reset routes (ResetRoutes): on single sites and in a network's main site, last. */
	private const RESET = [
		'/reset' => [ 'GET', 'POST' ],
	];

	/** The cloud destination routes (DestinationRoutes): in the main site's context, after the reset route. */
	private const DESTINATIONS = [
		'/destinations'                            => [ 'GET', 'POST' ],
		'/destinations/(?P<did>[a-f0-9]{8})'      => [ 'POST', 'DELETE' ],
		'/destinations/(?P<did>[a-f0-9]{8})/test' => [ 'POST' ],
		'/destinations/(?P<did>[a-f0-9]{8})/trust' => [ 'POST' ],
		'/destinations/(?P<did>[a-f0-9]{8})/files' => [ 'GET', 'DELETE' ],
		'/destinations/(?P<did>[a-f0-9]{8})/files/restore' => [ 'POST' ],
		'/destinations/(?P<did>[a-f0-9]{8})/files/fetch' => [ 'POST' ],
		'/backups/upload'                         => [ 'POST' ],
		'/destinations/(?P<did>[a-f0-9]{8})/connect' => [ 'POST' ],
		'/oauth/callback'                         => [ 'GET' ],
	];

	public function test_every_route_is_guarded_by_the_capability_check(): void {
		$registered = $this->registered();

		$this->assertGuarded( array_merge( self::SINGLE_SITE, self::LINKS, self::RESET, self::DESTINATIONS ), $registered );
		$this->assertArrayNotHasKey( '/network/sites', $registered );
	}

	public function test_every_route_is_registered_and_guarded_on_a_network(): void {
		$this->assertGuarded( array_merge( self::SINGLE_SITE, [ '/network/sites' => [ 'GET' ] ], self::RESET, self::DESTINATIONS ), $this->registered( true ) );
	}

	public function test_the_network_route_exists_only_on_a_network(): void {
		$this->assertArrayNotHasKey( '/network/sites', $this->registered( false ) );
		$this->assertArrayHasKey( '/network/sites', $this->registered( true ) );
	}

	public function test_no_route_is_registered_outside_the_main_site_of_a_network(): void {
		$this->assertSame( [], $this->registered( true, false ), 'A subsite has no migration route: its admin reaches nothing.' );
	}

	public function test_routes_stay_out_of_the_public_index(): void {
		foreach ( $this->registered() as $route => [ , $endpoints ] ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertFalse( $endpoint['show_in_index'], $route );
			}
		}
	}

	/**
	 * WP_REST_Server matches a route case-insensitively ('@^' . $route . '$@i'),
	 * so an uppercase id reaches the callback; the job store is the boundary
	 * that refuses it. Anything else that is not 16 hex digits never matches.
	 */
	public function test_job_routes_accept_only_sixteen_hex_digits_as_the_id(): void {
		$routes = array_filter( array_keys( $this->registered() ), static fn( string $route ): bool => str_starts_with( $route, self::JOB ) );
		$store  = new JobStore( '/nonexistent' );

		$this->assertCount( 4, $routes );
		foreach ( $routes as $route ) {
			$pattern = '@^' . $route . '$@i';
			$suffix  = substr( $route, strlen( self::JOB ) );
			$this->assertSame( 1, preg_match( $pattern, '/jobs/0123456789abcdef' . $suffix ), $route );
			$this->assertSame( 1, preg_match( $pattern, '/jobs/0123456789ABCDEF' . $suffix ), $route . ' (matched without case, like core)' );
			foreach ( [ '0123456789abcde', '0123456789abcdef0', '../../../etc/pas', '0123456789abcdeg' ] as $bad ) {
				$this->assertSame( 0, preg_match( $pattern, '/jobs/' . $bad . $suffix ), $route . ' ' . $bad );
			}
		}
		$this->assertSame( '/nonexistent/0123456789abcdef', $store->dir( '0123456789abcdef' ) );
		$this->expectException( JobException::class );
		$store->dir( '0123456789ABCDEF' );
	}

	public function test_schedule_routes_accept_only_eight_hex_digits_and_never_shadow_the_loopback(): void {
		$pattern = '@^' . ScheduleRoutes::ONE . '$@i';

		$this->assertSame( 1, preg_match( $pattern, '/schedules/0a1b2c3d' ) );
		$this->assertSame( 0, preg_match( $pattern, '/schedules/continue' ) );
		$this->assertSame( 0, preg_match( $pattern, '/schedules/0a1b2c3' ) );
		$this->assertSame( 0, preg_match( $pattern, '/schedules/0a1b2c3d0' ) );
		$this->assertSame( 0, preg_match( $pattern, '/schedules/../../x' ) );
	}

	/**
	 * Ruling I5: a job a connected site drives here is never ticked, answered
	 * or fed from this site's screens; looking at it and cancelling it stay.
	 */
	#[RunInSeparateProcess]
	public function test_the_cookie_routes_never_drive_a_connections_job(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/wp-error-class.php';
		require_once dirname( __DIR__, 2 ) . '/Support/wp-rest-response-class.php';
		FakeWpdb::install( [ Options::STORAGE_DIR => 'lw-migration-0123456789ab' ] );
		FakeOptions::install();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ) => 1 === $id ? (object) [
				'ID'         => 1,
				'user_login' => 'admin',
			] : false
		);
		$capable = true;
		Functions\when( 'user_can' )->alias(
			static function () use ( &$capable ): bool {
				return $capable;
			}
		);
		( new Links() )->add(
			[
				'id'         => '0123456789abcdef',
				'direction'  => Links::IN,
				'address'    => 'https://a.example',
				'name'       => 'Site A',
				'allow'      => [ 'push' ],
				'createdBy'  => [
					'id'    => 1,
					'login' => 'admin',
				],
				'pairedFrom' => '',
			],
			random_bytes( 32 ),
			time(),
			true
		);
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0755, true );
		}
		Services::get()->storage()->ensure();
		$jobs = Services::get()->jobs();
		$job                = $jobs->create( 'import', [ 'upload', 'validate' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
		$job->state['link'] = [
			'id'      => '0123456789abcdef',
			'ref'     => 'aaaabbbbccccdddd',
			'name'    => 'Site A',
			'address' => 'https://a.example',
		];
		$job->state['site'] = SiteFixture::make( WP_CONTENT_DIR . '/site' )->toArray();
		$jobs->save( $job );
		$this->assertTrue( Services::get()->lock()->acquire( $job->id, time() ) );
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( $job->id );
		$request->shouldReceive( 'get_param' )->with( 'offset' )->andReturn( '0' );
		$request->shouldReceive( 'get_json_params' )->andReturn( [ 'confirm' => true ] );
		$request->shouldReceive( 'get_body' )->andReturn( 'bytes' );
		$before = (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' );

		try {
			foreach ( [ 'tickJob', 'answerJob', 'uploadChunk' ] as $route ) {
				$answer = Routes::$route( $request );
				$this->assertInstanceOf( \WP_Error::class, $answer, $route );
				$this->assertSame( [ 'lw_migration_link_job', 409 ], [ $answer->get_error_code(), $answer->get_error_data()['status'] ?? null ], $route );
				$this->assertSame( 'This job is driven by the connected site Site A. Follow it here, or cancel it.', $answer->get_error_message() );
			}
			$this->assertSame( $before, (string) file_get_contents( $jobs->dir( $job->id ) . '/job.json' ), 'Nothing ticked, answered or written.' );

			$view = Routes::getJob( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $view );
			$this->assertSame( [ $job->id, 'Site A' ], [ $view->get_data()['id'], $view->get_data()['link']['name'] ] );

			$cancelled = Routes::cancelJob( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $cancelled, $cancelled instanceof \WP_Error ? $cancelled->get_error_message() : '' );
			$this->assertSame( Job::CANCELLED, $cancelled->get_data()['job']['status'] );
			$job = $jobs->create( 'import', [ 'upload', 'validate' ], [ 'source' => 'upload', 'location' => 'site.wpress', 'safetyBackup' => false ], time() );
			$job->state = $jobs->load( $request->get_param( 'id' ) )->state;
			$jobs->save( $job );
			$this->assertTrue( Services::get()->lock()->acquire( $job->id, time() ) );
			$request = \Mockery::mock( \WP_REST_Request::class );
			$request->shouldReceive( 'get_param' )->with( 'id' )->andReturn( $job->id );
			$request->shouldReceive( 'get_json_params' )->andReturn( [ 'confirm' => true ] );
			$request->shouldReceive( 'get_body' )->andReturn( '{"confirm":true}' );
			$this->assertSame( 'lw_migration_link_job', Routes::tickJob( $request )->get_error_code(), 'A new job of the link is driven by it.' );

			// Fix round 1: once the link can no longer drive it, this site finishes it.
			$capable = false;
			$this->assertNotLinkJob( Routes::answerJob( $request ), 'Its admin was demoted.' );
			$capable = true;
			$this->assertInstanceOf( \WP_Error::class, Routes::answerJob( $request ) );
			$this->assertSame( 'lw_migration_link_job', Routes::answerJob( $request )->get_error_code(), 'Driven again once the admin may act.' );

			// Final review I1: a job the link released past its swap, or stopped driving, is this site's.
			$released                          = $jobs->load( $job->id );
			$released->state['cancelDeferred'] = true;
			$jobs->save( $released );
			$this->assertNotLinkJob( Routes::answerJob( $request ), 'Released past its swap.' );
			unset( $released->state['cancelDeferred'] );
			$jobs->save( $released );
			$this->assertSame( 'lw_migration_link_job', Routes::answerJob( $request )->get_error_code(), 'Saved just now: driven by the link.' );
			$released->updatedAt = time() - SiteLock::TTL;
			$jobs->save( $released );
			$this->assertNotLinkJob( Routes::answerJob( $request ), 'Not saved for the lock time.' );
			$released->updatedAt = time();
			$jobs->save( $released );
			( new Links() )->remove( '0123456789abcdef' );
			$this->assertNotLinkJob( Routes::answerJob( $request ), 'Its link was removed.' );
			$tick = Routes::tickJob( $request );
			$this->assertInstanceOf( \WP_REST_Response::class, $tick, $tick instanceof \WP_Error ? $tick->get_error_message() : '' );
			$this->assertNotSame( Job::PENDING, $jobs->load( $job->id )->status, 'This site drove it.' );
		} finally {
			TempDir::remove( WP_CONTENT_DIR );
		}
	}

	public function test_a_body_that_is_not_a_json_object_is_refused(): void {
		$request = static function ( string $body, $json ): \WP_REST_Request {
			$mock = \Mockery::mock( \WP_REST_Request::class );
			$mock->shouldReceive( 'get_body' )->andReturn( $body );
			$mock->shouldReceive( 'get_json_params' )->andReturn( $json );

			return $mock;
		};
		$this->assertSame( [], Routes::json( $request( '', null ) ), 'No body.' );
		$this->assertSame( [], Routes::json( $request( '{}', [] ) ) );
		$this->assertSame( [ 'a' => 1 ], Routes::json( $request( '{"a":1}', [ 'a' => 1 ] ) ) );
		foreach ( [ [ 'garbage', null ], [ 'a=1', null ], [ '5', 5 ], [ '"x"', 'x' ], [ '[1,2]', [ 1, 2 ] ] ] as [ $body, $json ] ) {
			try {
				Routes::json( $request( $body, $json ) );
				$this->fail( 'Refused: ' . $body );
			} catch ( RestException $e ) {
				$this->assertSame( [ 'lw_migration_invalid', 400 ], [ $e->errorCode, $e->status ], $body );
			}
		}
	}

	/**
	 * @param mixed $answer Route answer.
	 */
	private function assertNotLinkJob( $answer, string $label ): void {
		$this->assertFalse( $answer instanceof \WP_Error && 'lw_migration_link_job' === $answer->get_error_code(), $label );
	}

	/**
	 * @param array<string, array<int, string>>                                     $expected   Route => methods, in order.
	 * @param array<string, array{0: string, 1: array<int, array<string, mixed>>}> $registered What RouteMap::register() registered.
	 */
	private function assertGuarded( array $expected, array $registered ): void {
		$this->assertSame( array_keys( $expected ), array_keys( $registered ) );
		foreach ( $registered as $route => [ $namespace, $endpoints ] ) {
			$this->assertSame( Routes::NAMESPACE, $namespace );
			$this->assertSame( $expected[ $route ], array_column( $endpoints, 'methods' ), $route );
			$guard = [
				'/backups/download'   => 'download',
				'/schedules/continue' => 'loopback',
				'/backups/shared'     => 'share',
				'/oauth/callback'     => 'oauthCallback',
			][ $route ] ?? 'check';
			$peer  = [
				'/link/pair'  => 'pairing',
				'/link/hello' => 'checkPending',
			][ $route ] ?? ( str_starts_with( $route, '/link/' ) || '/link' === $route ? 'check' : null );
			$owner = self::owner( $route );
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( null === $peer ? [ Permission::class, $guard ] : [ Guard::class, $peer ], $endpoint['permission_callback'], $route );
				$this->assertSame( $owner, $endpoint['callback'][0] );
				$this->assertTrue( method_exists( $owner, $endpoint['callback'][1] ), $endpoint['callback'][1] );
				$this->assertTrue( is_callable( $endpoint['permission_callback'] ), $route );
				$this->assertFalse( $endpoint['show_in_index'], $route );
			}
		}
	}

	/**
	 * @param string $route Route.
	 * @return class-string The class that registers it.
	 */
	private static function owner( string $route ): string {
		if ( str_starts_with( $route, '/schedules' ) ) {
			return ScheduleRoutes::class;
		}
		if ( str_starts_with( $route, '/network' ) ) {
			return NetworkRoutes::class;
		}
		if ( '/reset' === $route ) {
			return ResetRoutes::class;
		}
		if ( '/oauth/callback' === $route || str_ends_with( $route, '/connect' ) ) {
			return OAuthRoutes::class;
		}
		if ( str_starts_with( $route, '/destinations' ) ) {
			return DestinationRoutes::class;
		}

		if ( str_starts_with( $route, '/links' ) ) {
			return LinkRoutes::class;
		}
		if ( str_starts_with( $route, '/link' ) ) {
			return LinkPeerRoutes::class;
		}

		if ( '/backups/upload' === $route ) {
			return DestinationRoutes::class;
		}

		return str_starts_with( $route, '/backups/share' ) ? ShareRoutes::class : Routes::class;
	}

	/**
	 * Runs RouteMap::register() and records what it registered.
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

		RouteMap::register();

		return $registered;
	}
}
