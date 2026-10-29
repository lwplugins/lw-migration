<?php
/**
 * The admin routes of the connections.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Registered by RouteMap::register(): listing the connections, making or
 * dropping this site's pairing code, typing the other site's code, checking
 * and removing a connection, sending this site over one (push) and copying
 * the other site here (pull). Every route needs Permission::check; single
 * sites only (spec 19). The routes the other site calls are LinkPeerRoutes.
 */
final class LinkRoutes {

	public const ONE = '/links/(?P<lid>[a-f0-9]{16})';

	/**
	 * The guards are written out per route (never a variable).
	 */
	public static function register(): void {
		if ( is_multisite() || ! MainSite::isContext() ) {
			return;
		}
		register_rest_route(
			Routes::NAMESPACE,
			'/links',
			[
				self::endpoint( 'GET', 'listLinks' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
				self::endpoint( 'POST', 'addLink' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
			]
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/links/code',
			[
				self::endpoint( 'POST', 'createCode' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
				self::endpoint( 'DELETE', 'dropCode' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ],
			]
		);
		register_rest_route( Routes::NAMESPACE, self::ONE, [ self::endpoint( 'DELETE', 'removeLink' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::ONE . '/check', [ self::endpoint( 'POST', 'checkLink' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::ONE . '/push', [ self::endpoint( 'POST', 'pushTo' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ] ] );
		register_rest_route( Routes::NAMESPACE, self::ONE . '/pull', [ self::endpoint( 'POST', 'pullFrom' ) + [ 'permission_callback' => [ Permission::class, 'check' ] ] ] );
	}

	/**
	 * @param string $method   HTTP method.
	 * @param string $callback Method of this class.
	 * @return array{methods: string, callback: array{0: class-string, 1: string}, show_in_index: false}
	 */
	private static function endpoint( string $method, string $callback ): array {
		return [
			'methods'       => $method,
			'callback'      => [ self::class, $callback ],
			'show_in_index' => false,
		];
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function listLinks() {
		return Routes::respond( static fn(): array => self::api()->all() );
	}

	/**
	 * @param \WP_REST_Request $request Request ({code}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function addLink( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->add( Routes::json( $request ), self::owner() ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request Request ({allow?}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function createCode( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->createCode( Routes::json( $request ), self::owner() ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dropCode() {
		return Routes::respond( static fn(): array => self::api()->dropCode() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function removeLink( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->remove( (string) $request->get_param( 'lid' ) ) );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function checkLink( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => self::api()->check( (string) $request->get_param( 'lid' ) ) );
	}

	/**
	 * Starts sending this site to the other site of an outgoing link.
	 *
	 * @param \WP_REST_Request $request Request ({options?, safetyBackup?}).
	 * @return \WP_REST_Response|\WP_Error The new push's job view (201).
	 */
	public static function pushTo( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				$jobs = JobsApi::fromServices( Services::get() );

				return $jobs->view( self::api()->push( (string) $request->get_param( 'lid' ), Routes::json( $request ) ) );
			},
			201
		);
	}

	/**
	 * Starts copying the other site of an outgoing link onto this site.
	 *
	 * @param \WP_REST_Request $request Request ({safetyBackup?}).
	 * @return \WP_REST_Response|\WP_Error The new import's job view (201).
	 */
	public static function pullFrom( \WP_REST_Request $request ) {
		return Routes::respond(
			static function () use ( $request ): array {
				$jobs = JobsApi::fromServices( Services::get() );

				return $jobs->view( self::api()->pull( (string) $request->get_param( 'lid' ), Routes::json( $request ), self::owner() ) );
			},
			201
		);
	}

	/**
	 * The logged-in admin the connection acts for (ruling I3), who also stays
	 * logged in after a pull.
	 *
	 * @return array{id: int, login: string}
	 */
	private static function owner(): array {
		return [
			'id'    => get_current_user_id(),
			'login' => (string) wp_get_current_user()->user_login,
		];
	}

	private static function api(): LinksApi {
		return LinksApi::fromServices( Services::get() );
	}
}
