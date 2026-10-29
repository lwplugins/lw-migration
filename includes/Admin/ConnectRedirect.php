<?php
/**
 * Opens a command-line connect link on the plugin's page.
 *
 * @package LightweightPlugins\Migration
 */

declare(strict_types=1);

namespace LightweightPlugins\Migration\Admin;

use LightweightPlugins\Migration\Multisite\MainSite;
use LightweightPlugins\Migration\Rest\ConnectLinks;
use LightweightPlugins\Migration\Rest\Permission;

defined( 'ABSPATH' ) || exit;

/**
 * Issue #12. On admin_init (after core's login check, so a logged-out
 * administrator signs in first and comes back with the link) the plugin's
 * page with ?lwm-connect=<ticket> is turned into a redirect: to the
 * authorize address of a state issued in this login session, or back to
 * the page with a fixed code (ConnectLinks::follow()). Only for a user with
 * the capability, in the main site's context, on a network only in the
 * network admin (where AdminPage::pageUrl() points). The answer is never
 * cached and sends no Referer (the address carried the ticket).
 */
final class ConnectRedirect {

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'maybeRedirect' ] );
	}

	public static function maybeRedirect(): void {
		$target = self::target( $_GET, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The one-time ticket, bound to this user, is the request's proof; target() reads only the page slug and the ticket.
		if ( null === $target ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The provider's or the LW sign-in service's authorize address built by OAuthApi::start(), or the plugin's own page; never an address from the request.
		exit;
	}

	/**
	 * @param array<string, mixed> $query  The request's query.
	 * @param int                  $userId The logged-in user.
	 * @param ConnectLinks|null    $links  Connect links (tests; null = ConnectLinks::fromWordPress()).
	 * @return string|null Where to send the browser; null when this request is no connect link (or not this user's to open).
	 */
	public static function target( array $query, int $userId, ?ConnectLinks $links = null ): ?string {
		$page   = is_string( $query['page'] ?? null ) ? $query['page'] : '';
		$ticket = is_string( $query[ ConnectLinks::PARAM ] ?? null ) ? sanitize_key( wp_unslash( $query[ ConnectLinks::PARAM ] ) ) : '';
		if ( AdminPage::SLUG !== $page || '' === $ticket || $userId <= 0 || ! current_user_can( Permission::capability() ) || ! MainSite::isContext() ) {
			return null;
		}
		if ( is_multisite() && ! is_network_admin() ) {
			return null;
		}

		return ( $links ?? ConnectLinks::fromWordPress() )->follow( $ticket, $userId );
	}
}
