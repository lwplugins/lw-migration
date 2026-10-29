<?php
/**
 * The admin routes of the reset.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register(): GET /reset answers the summary, POST
 * /reset checks the password and starts the job (201 with its view); the
 * job is then driven and cancelled by the job routes. Both need
 * Permission::check, which core calls a second time on rest_post_dispatch:
 * the password is checked and counted in the callback only, never there.
 * On a network the routes exist in the main site's context only.
 */
final class ResetRoutes {

	/**
	 * The guards are written out per route (never a variable).
	 */
	public static function register(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		register_rest_route(
			Routes::NAMESPACE,
			'/reset',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'summary' ],
					'permission_callback' => [ Permission::class, 'check' ],
					'show_in_index'       => false,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'start' ],
					'permission_callback' => [ Permission::class, 'check' ],
					'show_in_index'       => false,
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function summary() {
		return Routes::respond( static fn(): array => ResetApi::fromServices( Services::get() )->summary() );
	}

	/**
	 * @param \WP_REST_Request $request Request ({type, password, backup?, confirm?}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				$services = Services::get();

				return JobsApi::fromServices( $services )->view( ResetApi::fromServices( $services )->start( Routes::json( $request ) ) );
			},
			201
		);
	}
}
