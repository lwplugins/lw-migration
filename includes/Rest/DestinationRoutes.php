<?php
/**
 * The admin routes of the cloud destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Job\Job;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register(), only in the main site's context (on a
 * network: the network admin app, spec 21.6). Thin like ScheduleRoutes:
 * every callback goes through Routes::respond(); every route needs
 * Permission::check; none is listed in the index. The OAuth connect and
 * callback routes (OAuthRoutes, M7c) are registered after these.
 */
final class DestinationRoutes {

	public const DID = '(?P<did>[a-f0-9]{8})';

	/**
	 * @return array<string, array<int, array{0: string, 1: string}>> Route => [method, callback].
	 */
	public static function routes(): array {
		$one = '/destinations/' . self::DID;

		return [
			'/destinations'         => [ [ 'GET', 'listDestinations' ], [ 'POST', 'createDestination' ] ],
			$one                    => [ [ 'POST', 'updateDestination' ], [ 'DELETE', 'deleteDestination' ] ],
			$one . '/test'          => [ [ 'POST', 'testDestination' ] ],
			$one . '/trust'         => [ [ 'POST', 'trustDestination' ] ],
			$one . '/files'         => [ [ 'GET', 'listFiles' ], [ 'DELETE', 'deleteFile' ] ],
			$one . '/files/restore' => [ [ 'POST', 'restoreFile' ] ],
			$one . '/files/fetch'   => [ [ 'POST', 'fetchFile' ] ],
			'/backups/upload'       => [ [ 'POST', 'uploadBackup' ] ],
		];
	}

	public static function register(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		foreach ( self::routes() as $route => $endpoints ) {
			register_rest_route(
				Routes::NAMESPACE,
				$route,
				array_map(
					static fn( array $endpoint ): array => [
						'methods'             => $endpoint[0],
						'callback'            => [ self::class, $endpoint[1] ],
						'permission_callback' => [ Permission::class, 'check' ],
						'show_in_index'       => false,
					],
					$endpoints
				)
			);
		}
		OAuthRoutes::register();
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listDestinations() {
		return Routes::respond( static fn(): array => self::api()->all() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function createDestination( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->create( Routes::json( $request ) ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function updateDestination( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->update( self::did( $request ), Routes::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deleteDestination( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->delete( self::did( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function testDestination( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->test( self::did( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request ({hostKey} or {certFingerprint}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function trustDestination( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->trust( self::did( $request ), Routes::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listFiles( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => CloudApi::fromServices( Services::get() )->files( self::did( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (?name=).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deleteFile( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				$name = $request->get_param( 'name' );
				if ( ! is_string( $name ) ) {
					throw RestException::invalidField( 'name' );
				}

				return CloudApi::fromServices( Services::get() )->deleteFile( self::did( $request ), $name );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request ({name, safetyBackup?, password?}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function restoreFile( \WP_REST_Request $request ) {
		return self::job( static fn( CloudApi $api ): Job => $api->restore( self::did( $request ), Routes::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request ({name}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function fetchFile( \WP_REST_Request $request ) {
		return self::job( static fn( CloudApi $api ): Job => $api->fetch( self::did( $request ), Routes::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request ({folder, name, destinations}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function uploadBackup( \WP_REST_Request $request ) {
		return self::job( static fn( CloudApi $api ): Job => $api->upload( Routes::json( $request ) ) );
	}

	public static function did( \WP_REST_Request $request ): string {
		return (string) $request->get_param( 'did' );
	}

	/**
	 * @param \Closure $start fn( CloudApi $api ): Job.
	 * @return \WP_REST_Response|\WP_Error 201 with the new job's view.
	 */
	private static function job( \Closure $start ) {
		return Routes::respond(
			static function () use ( $start ): array {
				$services = Services::get();

				return JobsApi::fromServices( $services )->view( $start( CloudApi::fromServices( $services ) ) );
			},
			201
		);
	}

	private static function api(): DestinationsApi {
		return DestinationsApi::fromServices( Services::get() );
	}
}
