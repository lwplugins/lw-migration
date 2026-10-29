<?php
/**
 * The schedule routes of lw-migration/v1.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Schedules\Events;
use LightweightPlugins\Migration\Schedules\Loopback;
use LightweightPlugins\Migration\Schedules\ScheduleJobs;
use LightweightPlugins\Migration\Services;
use LightweightPlugins\Migration\Support\RequestAbort;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register() (so on a network only on its main site),
 * thin like Routes: every callback goes through Routes::respond(). All routes need
 * Permission::check, except the loopback: it carries a one-time token in its
 * JSON body instead of a login (Permission::loopback), runs one slice of the
 * cron driver and answers 202 {ok: true} — no job data, whatever happened.
 */
final class ScheduleRoutes {

	public const ONE = '/schedules/(?P<sid>[a-f0-9]{8})';

	/**
	 * @return array<string, array<int, array{0: string, 1: string, 2: string}>> Route => [method, callback, permission method].
	 */
	public static function routes(): array {
		return [
			'/schedules'       => [ [ 'GET', 'listSchedules', 'check' ], [ 'POST', 'createSchedule', 'check' ] ],
			self::ONE          => [ [ 'POST', 'updateSchedule', 'check' ], [ 'DELETE', 'deleteSchedule', 'check' ] ],
			self::ONE . '/run' => [ [ 'POST', 'runSchedule', 'check' ] ],
			self::ONE . '/log' => [ [ 'GET', 'scheduleLog', 'check' ], [ 'DELETE', 'clearScheduleLog', 'check' ] ],
			Loopback::ROUTE    => [ [ 'POST', 'continueSchedules', 'loopback' ] ],
		];
	}

	public static function register(): void {
		foreach ( self::routes() as $route => $endpoints ) {
			register_rest_route(
				Routes::NAMESPACE,
				$route,
				array_map(
					static fn( array $endpoint ): array => [
						'methods'             => $endpoint[0],
						'callback'            => [ self::class, $endpoint[1] ],
						'permission_callback' => [ Permission::class, $endpoint[2] ],
						'show_in_index'       => false,
					],
					$endpoints
				)
			);
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listSchedules() {
		return Routes::respond( static fn(): array => self::api()->all() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function createSchedule( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->create( Routes::json( $request ) ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function updateSchedule( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->update( self::sid( $request ), Routes::json( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deleteSchedule( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				self::api()->delete( self::sid( $request ) );

				return [ 'deleted' => true ];
			}
		);
	}

	/**
	 * "Run now": the new job's view; the browser drives it like any job.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function runSchedule( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				$services = Services::get();
				$job      = self::api()->run( self::sid( $request ), ScheduleJobs::fromServices( $services, $services->webSecrets() ) );

				return JobsApi::fromServices( $services )->view( $job );
			},
			201
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function scheduleLog( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->log( self::sid( $request ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function clearScheduleLog( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->clearLog( self::sid( $request ) ) );
	}

	/**
	 * The loopback (Permission::loopback used up its token): one slice, then
	 * the same answer whatever happened. The caller does not wait for it.
	 */
	public static function continueSchedules(): \WP_REST_Response {
		RequestAbort::ignore();
		QuietOutput::run(
			static function (): void {
				Events::continueWork(); // Guarded: a failure goes to the PHP error log.
			}
		);

		return new \WP_REST_Response( [ 'ok' => true ], 202 );
	}

	private static function api(): SchedulesApi {
		return SchedulesApi::fromServices( Services::get() );
	}

	private static function sid( \WP_REST_Request $request ): string {
		return (string) $request->get_param( 'sid' );
	}
}
