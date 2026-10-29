<?php
/**
 * The connect and callback routes of OAuth destinations.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Rest;

use LightweightPlugins\Migration\Admin\AdminPage;
use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Storage\Destinations\OAuth\RedirectUri;

defined( 'ABSPATH' ) || exit;

/**
 * Registered from DestinationRoutes::register(), in the main site's context
 * only, never in the index. The connect route needs the REST nonce and the
 * capability (Permission::check), like every admin route.
 *
 * The callback route: the provider sends the browser back here with a
 * top-level GET, so no REST nonce can come along and WordPress runs it as
 * user 0. Permission::oauthCallback authenticates the logged_in cookie itself (a
 * valid cookie of a user with the capability, main site only; anything
 * else is core's 401 and nothing runs), and OAuthApi::finish() takes the
 * user id from that cookie only. The CSRF protection is the state: single
 * use, issued to that user in that login session for one profile and one
 * Client ID, used up before any token request — a forged or replayed
 * callback, or one carried into another browser or session, finds no state
 * and exchanges nothing. Only GET does any work (core runs a HEAD request
 * through the GET handler; it gets the failed redirect and leaves the
 * state alone). Every answer is a 302 to the plugin's own page with a fixed
 * code (no address from the request: no open redirect), never cached and
 * without a Referer (the callback address carries the code and the state);
 * an unexpected failure is the same 302 with the failed code, never a
 * message or a trace.
 */
final class OAuthRoutes {

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> Route => [method, callback, permission].
	 */
	public static function routes(): array {
		return [
			'/destinations/' . DestinationRoutes::DID . '/connect' => [ 'POST', 'connect', 'check' ],
			RedirectUri::ROUTE => [ 'GET', 'callback', 'oauthCallback' ],
		];
	}

	public static function register(): void {
		if ( ! MainSite::isContext() ) {
			return;
		}
		foreach ( self::routes() as $route => [ $method, $callback, $permission ] ) {
			register_rest_route(
				Routes::NAMESPACE,
				$route,
				[
					[
						'methods'             => $method,
						'callback'            => [ self::class, $callback ],
						'permission_callback' => [ Permission::class, $permission ],
						'show_in_index'       => false,
					],
				]
			);
		}
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error 200 {url}.
	 */
	public static function connect( \WP_REST_Request $request ) {
		return Routes::respond( static fn(): array => OAuthApi::fromWordPress()->start( DestinationRoutes::did( $request ), get_current_user_id() ) );
	}

	/**
	 * @param \WP_REST_Request $request Request (?code=&state= or ?error=&state=).
	 */
	public static function callback( \WP_REST_Request $request ): \WP_REST_Response {
		return self::redirect( $request, static fn(): OAuthApi => OAuthApi::fromWordPress() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @param \Closure         $api     fn(): OAuthApi.
	 */
	public static function redirect( \WP_REST_Request $request, \Closure $api ): \WP_REST_Response {
		$url = null;
		if ( 'GET' === $request->get_method() ) {
			try {
				$url = $api()->finish( (array) $request->get_query_params(), (int) Permission::cookieUser() );
			} catch ( \Throwable $e ) {
				unset( $e ); // Never a trace or a provider text in the browser: the page explains.
			}
		}
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url ?? OAuthApi::address( AdminPage::pageUrl(), OAuthApi::FAILED ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}
}
