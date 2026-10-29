<?php
/**
 * The network routes of lw-migration/v1.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\NetworkReader;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register() on the main site of a network only, thin
 * like Routes. GET /network/sites?page=&perPage=&search= lists the network's
 * sites for the site picker; the search looks at addresses (domain, path) only.
 */
final class NetworkRoutes {

	public const SITES = '/network/sites';

	public static function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		register_rest_route(
			Routes::NAMESPACE,
			self::SITES,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'listSites' ],
					'permission_callback' => [ Permission::class, 'check' ],
					'show_in_index'       => false,
				],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Request (?page, perPage, search).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listSites( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => ( new NetworkApi( new NetworkReader() ) )->find( $request->get_param( 'page' ), $request->get_param( 'perPage' ), $request->get_param( 'search' ) ) );
	}
}
